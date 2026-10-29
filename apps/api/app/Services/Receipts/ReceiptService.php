<?php

namespace App\Services\Receipts;

use App\Enums\ModuleCapability;
use App\Exceptions\LocalReceiptOcrUnavailable;
use App\Exceptions\ModuleDisabled;
use App\Exceptions\ModuleNotFound;
use App\Models\FinanceCategory;
use App\Models\FinancialTransaction;
use App\Models\ReceiptDocument;
use App\Models\User;
use App\Models\VehicleCost;
use App\Services\Finance\FinanceDecimalValue;
use App\Services\Finance\FinanceService;
use App\Services\Modules\ModuleAccessService;
use App\Services\Vehicles\VehicleDecimalValue;
use App\Services\Vehicles\VehicleService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class ReceiptService
{
    private const MODULE = 'receipts';

    public function __construct(
        private readonly ModuleAccessService $access,
        private readonly LocalReceiptOcr $ocr,
        private readonly InvoiceTextParser $parser,
        private readonly FinanceService $finance,
        private readonly VehicleService $vehicles,
    ) {}

    /** @param array<string, mixed> $filters
     * @return array{receipts: list<array<string, mixed>>, pagination: array{current_page: int, last_page: int, per_page: int, total: int}, currencies: list<string>, category_filters: list<array{value: string, label: string}>}
     */
    public function index(User $actor, array $filters): array
    {
        $this->access->authorize($actor, self::MODULE, ModuleCapability::View);
        $visible = $this->visibleReceipts($actor, ModuleCapability::View);
        $currencies = (clone $visible)
            ->select('currency')
            ->distinct()
            ->orderBy('currency')
            ->pluck('currency')
            ->map(static fn (mixed $currency): string => (string) $currency)
            ->values()
            ->all();
        $query = clone $visible;
        $search = trim((string) ($filters['search'] ?? ''));

        if ($search !== '') {
            $term = '%'.mb_strtolower($search).'%';
            $query->where(function (Builder $matching) use ($term): void {
                $matching->whereRaw('LOWER(COALESCE(merchant, \'\')) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(COALESCE(invoice_number, \'\')) LIKE ?', [$term]);
            });
        }

        if (isset($filters['date_from']) && $filters['date_from'] !== '') {
            $query->whereDate('invoice_date', '>=', $filters['date_from']);
        }
        if (isset($filters['date_to']) && $filters['date_to'] !== '') {
            $query->whereDate('invoice_date', '<=', $filters['date_to']);
        }
        if (isset($filters['min_amount']) && $filters['min_amount'] !== '') {
            $query->where('amount', '>=', $filters['min_amount']);
        }
        if (isset($filters['max_amount']) && $filters['max_amount'] !== '') {
            $query->where('amount', '<=', $filters['max_amount']);
        }
        if (isset($filters['currency']) && $filters['currency'] !== '') {
            $query->where('currency', strtoupper((string) $filters['currency']));
        }
        if (($filters['sharing_status'] ?? '') === 'shared') {
            $query->where('is_private', false);
        } elseif (($filters['sharing_status'] ?? '') === 'private') {
            $query->where('is_private', true);
        }

        if (isset($filters['target_type']) && $filters['target_type'] !== '') {
            if ($filters['target_type'] === 'archive') {
                $query->whereNull('financial_transaction_id')->whereNull('vehicle_cost_id');
            } elseif ($filters['target_type'] === 'finance') {
                try {
                    $this->access->authorize($actor, 'finance', ModuleCapability::View);
                    $query->whereIn('financial_transaction_id', $this->visibleFinanceTransactions($actor)->select('id'));
                } catch (AuthorizationException|ModuleDisabled|ModuleNotFound) {
                    $query->whereRaw('1 = 0');
                }
            } else {
                try {
                    $this->access->authorize($actor, 'vehicles', ModuleCapability::View);
                    $query->whereIn('vehicle_cost_id', $this->visibleVehicleCosts($actor)->select('id'));
                } catch (AuthorizationException|ModuleDisabled|ModuleNotFound) {
                    $query->whereRaw('1 = 0');
                }
            }
        }

        if (isset($filters['category']) && $filters['category'] !== '') {
            [$categoryModule, $categoryValue] = array_pad(explode(':', (string) $filters['category'], 2), 2, '');
            try {
                if ($categoryModule === 'finance' && ctype_digit($categoryValue)) {
                    $this->access->authorize($actor, 'finance', ModuleCapability::View);
                    $category = FinanceCategory::query()->find((int) $categoryValue);
                    if ($category instanceof FinanceCategory) {
                        $this->access->authorize($actor, 'finance', ModuleCapability::View, $category);
                        $query->whereIn('financial_transaction_id', $this->visibleFinanceTransactions($actor)
                            ->where('finance_category_id', $category->id)
                            ->select('id'));
                    } else {
                        $query->whereRaw('1 = 0');
                    }
                } elseif ($categoryModule === 'vehicle') {
                    $this->access->authorize($actor, 'vehicles', ModuleCapability::View);
                    $query->whereIn('vehicle_cost_id', $this->visibleVehicleCosts($actor)
                        ->where('category', $categoryValue)
                        ->select('id'));
                } else {
                    $query->whereRaw('1 = 0');
                }
            } catch (AuthorizationException|ModuleDisabled|ModuleNotFound) {
                $query->whereRaw('1 = 0');
            }
        }

        $page = $query
            ->orderByDesc('invoice_date')
            ->orderByDesc('id')
            ->paginate(30);

        return [
            'receipts' => $page->getCollection()
                ->map(fn (ReceiptDocument $receipt): array => $this->present($receipt, $actor))
                ->all(),
            'pagination' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
            'currencies' => $currencies,
            'category_filters' => $this->categoryFilterOptions($actor),
        ];
    }

    /** @return array<string, mixed> */
    public function updateSharing(User $actor, int $receiptId, bool $sharedWithHousehold): array
    {
        return DB::transaction(function () use ($actor, $receiptId, $sharedWithHousehold): array {
            $currentActor = User::query()
                ->whereKey($actor->id)
                ->where('is_active', true)
                ->lockForUpdate()
                ->first();

            if (! $currentActor instanceof User) {
                throw new AuthorizationException('An active receipt owner is required.');
            }

            $this->access->authorize($currentActor, self::MODULE, ModuleCapability::Manage);
            $receipt = ReceiptDocument::query()
                ->where('owner_user_id', $currentActor->id)
                ->whereKey($receiptId)
                ->lockForUpdate()
                ->firstOrFail();
            $receipt->is_private = ! $sharedWithHousehold;
            $receipt->save();

            return $this->present($receipt, $currentActor);
        }, 3);
    }

    /** @return array{finance_available: bool, finance_categories: list<array<string, mixed>>, vehicles_available: bool, vehicles: list<array<string, mixed>>} */
    public function options(User $actor): array
    {
        $this->access->authorize($actor, self::MODULE, ModuleCapability::Create);
        $financeCategories = [];
        $vehicles = [];
        $financeAvailable = false;
        $vehiclesAvailable = false;

        try {
            $financeCategories = array_values(array_filter(
                $this->finance->importableCategories($actor),
                static fn (array $category): bool => ($category['type'] ?? null) === 'expense',
            ));
            $financeAvailable = $financeCategories !== [];
        } catch (AuthorizationException|ModuleDisabled) {
            // A target module is optional; an inaccessible target is not offered.
        }

        try {
            $this->access->authorize($actor, 'vehicles', ModuleCapability::Create);
            $vehicles = $this->vehicles->index($actor);
            $vehiclesAvailable = $vehicles !== [];
        } catch (AuthorizationException|ModuleDisabled) {
            // A target module is optional; an inaccessible target is not offered.
        }

        return [
            'finance_available' => $financeAvailable,
            'finance_categories' => $financeCategories,
            'vehicles_available' => $vehiclesAvailable,
            'vehicles' => $vehicles,
        ];
    }

    /** @return array<string, mixed> */
    public function preview(User $actor, UploadedFile $file): array
    {
        $this->access->authorize($actor, self::MODULE, ModuleCapability::Create);
        try {
            $extraction = $this->ocr->extractDetailed($file);
        } catch (LocalReceiptOcrUnavailable) {
            throw new HttpResponseException(
                response()->json([
                    'message' => 'Local receipt OCR is unavailable. You can still enter an expense in its source module.',
                ], Response::HTTP_SERVICE_UNAVAILABLE),
            );
        }

        $digest = $this->fileDigest($file);
        $analysis = $this->parser->parseWithReview($extraction['text'], $extraction['confidence']);

        return [
            'preview_token' => $this->createPreviewToken($actor, $digest, $analysis['review_required']),
            'suggestions' => $analysis['suggestions'],
            'field_status' => $analysis['field_status'],
            'review_required' => $analysis['review_required'],
            'duplicate_matches' => $this->findPotentialDuplicates($actor, $digest, $analysis['suggestions']),
            'default_currency' => strtoupper((string) ($actor->default_currency ?: 'EUR')),
            'needs_confirmation' => true,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{created: bool, receipt: array<string, mixed>, target: array<string, mixed>|null}
     */
    public function confirm(User $actor, UploadedFile $file, array $data): array
    {
        $this->access->authorize($actor, self::MODULE, ModuleCapability::Create);
        $digest = $this->fileDigest($file);
        $preview = $this->validatePreviewToken($actor, (string) $data['preview_token'], $digest);
        $normalized = $this->normalizeConfirmation($data);
        if (array_diff($preview['review_required'], $normalized['reviewed_fields']) !== []) {
            throw ValidationException::withMessages(['reviewed_fields' => 'Review each missing or uncertain invoice field before saving.']);
        }
        $fingerprint = $this->requestFingerprint($digest, $normalized);
        $storedPath = null;

        try {
            return DB::transaction(function () use ($actor, $file, $digest, $fingerprint, $normalized, &$storedPath): array {
                User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
                $this->access->authorize($actor, self::MODULE, ModuleCapability::Create);
                $prior = ReceiptDocument::query()
                    ->where('owner_user_id', $actor->id)
                    ->where('idempotency_key', $normalized['idempotency_key'])
                    ->lockForUpdate()
                    ->first();

                if ($prior instanceof ReceiptDocument) {
                    if (! hash_equals($prior->request_fingerprint, $fingerprint)) {
                        throw new HttpException(Response::HTTP_CONFLICT, 'This idempotency key was already used for a different receipt.');
                    }

                    return [
                        'created' => false,
                        'receipt' => $this->present($prior, $actor),
                        'target' => $this->existingTarget($prior),
                    ];
                }

                if ($this->findPotentialDuplicates($actor, $digest, $normalized) !== [] && ! $normalized['duplicate_acknowledged']) {
                    throw ValidationException::withMessages(['duplicate_acknowledged' => 'Review the possible duplicate warning before saving this receipt.']);
                }

                try {
                    if ($normalized['target_type'] === 'finance') {
                        $target = $this->finance->createTransaction($actor, [
                            'finance_category_id' => $normalized['finance_category_id'],
                            'type' => 'expense',
                            'description' => $normalized['description'],
                            'payee' => $normalized['merchant'],
                            'amount' => $normalized['amount'],
                            'currency' => $normalized['currency'],
                            'occurred_on' => $normalized['invoice_date'],
                            'visibility' => 'private',
                        ]);
                        $targetId = (int) $target['id'];
                        $financialTransactionId = $targetId;
                        $vehicleCostId = null;
                    } else {
                        if ($normalized['target_type'] === 'vehicle') {
                            $target = $this->vehicles->addCost($actor, $normalized['vehicle_id'], [
                                'category' => $normalized['vehicle_category'],
                                'amount' => $normalized['amount'],
                                'currency' => $normalized['currency'],
                                'period_start' => $normalized['invoice_date'],
                                'period_end' => null,
                                'period' => 'once',
                                'description' => $normalized['description'],
                                'fuel_liters' => $normalized['vehicle_category'] === 'fuel' ? $normalized['fuel_liters'] : null,
                                'fuel_unit_price' => $normalized['vehicle_category'] === 'fuel' ? $normalized['fuel_unit_price'] : null,
                                'fuel_odometer_value' => $normalized['vehicle_category'] === 'fuel' ? $normalized['fuel_odometer_value'] : null,
                            ]);
                            $targetId = (int) $target['id'];
                            $financialTransactionId = null;
                            $vehicleCostId = $targetId;
                        } else {
                            $target = null;
                            $targetId = null;
                            $financialTransactionId = null;
                            $vehicleCostId = null;
                        }
                    }
                } catch (ModuleDisabled) {
                    throw new AuthorizationException('The selected target module is unavailable.');
                }

                $storedPath = $file->store('receipts', 'local');
                if (! is_string($storedPath) || $storedPath === '') {
                    throw new HttpException(Response::HTTP_INTERNAL_SERVER_ERROR, 'The receipt file could not be stored.');
                }

                $receipt = ReceiptDocument::query()->create([
                    'owner_user_id' => $actor->id,
                    'financial_transaction_id' => $financialTransactionId,
                    'vehicle_cost_id' => $vehicleCostId,
                    'file_path' => $storedPath,
                    'mime_type' => (string) $file->getMimeType(),
                    'merchant' => $normalized['merchant'],
                    'description' => $normalized['description'],
                    'invoice_number' => $normalized['invoice_number'],
                    'invoice_date' => $normalized['invoice_date'],
                    'amount' => $normalized['amount'],
                    'currency' => $normalized['currency'],
                    'tax_amount' => $normalized['tax_amount'],
                    'fuel_liters' => $normalized['fuel_liters'],
                    'fuel_unit_price' => $normalized['fuel_unit_price'],
                    'fuel_odometer_value' => $normalized['fuel_odometer_value'],
                    'idempotency_key' => $normalized['idempotency_key'],
                    'file_digest' => $digest,
                    'request_fingerprint' => $fingerprint,
                ]);
                $this->access->authorize($actor, self::MODULE, ModuleCapability::Create, $receipt);

                return [
                    'created' => true,
                    'receipt' => $this->present($receipt, $actor),
                    'target' => $target === null ? null : ['type' => $normalized['target_type'], 'id' => $targetId, 'record' => $target],
                ];
            }, 1);
        } catch (Throwable $exception) {
            if (is_string($storedPath)) {
                Storage::disk('local')->delete($storedPath);
            }

            throw $exception;
        }
    }

    /** @return array{targets: list<array{id: int, label: string, date: string, amount: string, currency: string}>, create_categories: list<array<string, mixed>>, create_vehicles: list<array<string, mixed>>} */
    public function targetOptions(User $actor, string $targetType, string $search): array
    {
        $this->access->authorize($actor, self::MODULE, ModuleCapability::Edit);
        $targets = [];
        $createCategories = [];
        $createVehicles = [];
        $search = trim($search);

        if ($targetType === 'finance') {
            try {
                $this->access->authorize($actor, 'finance', ModuleCapability::View);
                $query = $this->visibleFinanceTransactions($actor)->with('category');
                if ($search !== '') {
                    $term = '%'.mb_strtolower($search).'%';
                    $query->where(function (Builder $matching) use ($term): void {
                        $matching->whereRaw('LOWER(COALESCE(description, \'\')) LIKE ?', [$term])
                            ->orWhereRaw('LOWER(COALESCE(payee, \'\')) LIKE ?', [$term]);
                    });
                }
                foreach ($query->orderByDesc('occurred_on')->orderByDesc('id')->limit(50)->get() as $transaction) {
                    $this->access->authorize($actor, 'finance', ModuleCapability::View, $transaction);
                    $targets[] = [
                        'id' => (int) $transaction->id,
                        'label' => trim(implode(' · ', array_filter([
                            $transaction->payee,
                            $transaction->description,
                            $transaction->occurred_on,
                            $transaction->amount.' '.$transaction->currency,
                        ]))),
                        'date' => $transaction->occurred_on,
                        'amount' => (string) $transaction->amount,
                        'currency' => $transaction->currency,
                    ];
                }
            } catch (AuthorizationException|ModuleDisabled|ModuleNotFound) {
                // Do not surface targets from an unavailable module.
            }

            try {
                $createCategories = array_values(array_filter(
                    $this->finance->importableCategories($actor),
                    static fn (array $category): bool => ($category['type'] ?? null) === 'expense',
                ));
            } catch (AuthorizationException|ModuleDisabled|ModuleNotFound) {
                // Existing links remain available even without create permission.
            }
        } else {
            try {
                $this->access->authorize($actor, 'vehicles', ModuleCapability::View);
                $visibleVehicles = $this->vehicles->index($actor);
                $vehicleNames = collect($visibleVehicles)->mapWithKeys(static fn (array $vehicle): array => [(int) $vehicle['id'] => (string) $vehicle['name']]);
                $query = $this->visibleVehicleCosts($actor)->whereIn('vehicle_id', $vehicleNames->keys())->with('vehicle');
                if ($search !== '') {
                    $term = '%'.mb_strtolower($search).'%';
                    $query->where(function (Builder $matching) use ($term): void {
                        $matching->whereRaw('LOWER(COALESCE(description, \'\')) LIKE ?', [$term])
                            ->orWhereRaw('LOWER(category) LIKE ?', [$term]);
                    });
                }
                foreach ($query->orderByDesc('period_start')->orderByDesc('id')->limit(50)->get() as $cost) {
                    $this->access->authorize($actor, 'vehicles', ModuleCapability::View, $cost);
                    $targets[] = [
                        'id' => (int) $cost->id,
                        'label' => trim(implode(' · ', array_filter([
                            $vehicleNames->get((int) $cost->vehicle_id),
                            $cost->description ?: ucfirst($cost->category),
                            $cost->period_start,
                            $cost->amount.' '.$cost->currency,
                        ]))),
                        'date' => $cost->period_start,
                        'amount' => (string) $cost->amount,
                        'currency' => $cost->currency,
                    ];
                }
                $this->access->authorize($actor, 'vehicles', ModuleCapability::Create);
                $createVehicles = $visibleVehicles;
            } catch (AuthorizationException|ModuleDisabled|ModuleNotFound) {
                // Existing links remain available even without create permission.
            }
        }

        return ['targets' => $targets, 'create_categories' => $createCategories, 'create_vehicles' => $createVehicles];
    }

    /** @return array<string, mixed> */
    public function linkTarget(User $actor, int $receiptId, string $targetType, int $targetId): array
    {
        return DB::transaction(function () use ($actor, $receiptId, $targetType, $targetId): array {
            $receipt = $this->ownedReceiptForUpdate($actor, $receiptId);
            $this->access->authorize($actor, self::MODULE, ModuleCapability::Edit, $receipt);

            if ($receipt->financial_transaction_id !== null || $receipt->vehicle_cost_id !== null) {
                $sameTarget = $targetType === 'finance'
                    ? (int) $receipt->financial_transaction_id === $targetId
                    : (int) $receipt->vehicle_cost_id === $targetId;
                if ($sameTarget) {
                    return $this->present($receipt, $actor);
                }
                throw new HttpException(Response::HTTP_CONFLICT, 'This receipt is already linked to another entry.');
            }

            if ($targetType === 'finance') {
                $this->access->authorize($actor, 'finance', ModuleCapability::View);
                $target = $this->visibleFinanceTransactions($actor)->whereKey($targetId)->lockForUpdate()->firstOrFail();
                $this->access->authorize($actor, 'finance', ModuleCapability::View, $target);
                $receipt->financial_transaction_id = $target->id;
            } else {
                $this->access->authorize($actor, 'vehicles', ModuleCapability::View);
                $target = $this->visibleVehicleCosts($actor)->whereKey($targetId)->lockForUpdate()->firstOrFail();
                $this->access->authorize($actor, 'vehicles', ModuleCapability::View, $target);
                $receipt->vehicle_cost_id = $target->id;
            }

            $receipt->save();

            return $this->present($receipt, $actor);
        }, 3);
    }

    /** @param array<string, mixed> $data
     * @return array{created: bool, receipt: array<string, mixed>, target: array<string, mixed>|null}
     */
    public function createTarget(User $actor, int $receiptId, array $data): array
    {
        return DB::transaction(function () use ($actor, $receiptId, $data): array {
            $receipt = $this->ownedReceiptForUpdate($actor, $receiptId);
            $this->access->authorize($actor, self::MODULE, ModuleCapability::Edit, $receipt);

            if ($receipt->financial_transaction_id !== null || $receipt->vehicle_cost_id !== null) {
                return ['created' => false, 'receipt' => $this->present($receipt, $actor), 'target' => $this->existingTarget($receipt)];
            }

            $description = $receipt->description ?: ($receipt->merchant ? 'Invoice from '.$receipt->merchant : 'Invoice expense');
            if ($data['target_type'] === 'finance') {
                $target = $this->finance->createTransaction($actor, [
                    'finance_category_id' => (int) $data['finance_category_id'],
                    'type' => 'expense',
                    'description' => $description,
                    'payee' => $receipt->merchant,
                    'amount' => (string) $receipt->amount,
                    'currency' => $receipt->currency,
                    'occurred_on' => $receipt->invoice_date,
                    'visibility' => 'private',
                ]);
                $receipt->financial_transaction_id = (int) $target['id'];
                $targetType = 'finance';
            } else {
                $target = $this->vehicles->addCost($actor, (int) $data['vehicle_id'], [
                    'category' => (string) $data['vehicle_category'],
                    'amount' => (string) $receipt->amount,
                    'currency' => $receipt->currency,
                    'period_start' => $receipt->invoice_date,
                    'period_end' => null,
                    'period' => 'once',
                    'description' => $description,
                    'fuel_liters' => $data['vehicle_category'] === 'fuel' ? $receipt->fuel_liters : null,
                    'fuel_unit_price' => $data['vehicle_category'] === 'fuel' ? $receipt->fuel_unit_price : null,
                    'fuel_odometer_value' => $data['vehicle_category'] === 'fuel' ? $receipt->fuel_odometer_value : null,
                ]);
                $receipt->vehicle_cost_id = (int) $target['id'];
                $targetType = 'vehicle';
            }

            $receipt->save();

            return [
                'created' => true,
                'receipt' => $this->present($receipt, $actor),
                'target' => ['type' => $targetType, 'id' => (int) $target['id'], 'record' => $target],
            ];
        }, 3);
    }

    /** @return array{path: string, mime_type: string, extension: string} */
    public function file(User $actor, int $receiptId): array
    {
        $this->access->authorize($actor, self::MODULE, ModuleCapability::View);
        $receipt = $this->visibleReceipts($actor, ModuleCapability::View)->whereKey($receiptId)->firstOrFail();
        $this->access->authorize($actor, self::MODULE, ModuleCapability::View, $receipt);
        abort_if(! Storage::disk('local')->exists($receipt->file_path), 404);

        return [
            'path' => Storage::disk('local')->path($receipt->file_path),
            'mime_type' => $receipt->mime_type,
            'extension' => match ($receipt->mime_type) {
                'application/pdf' => 'pdf',
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                default => 'bin',
            },
        ];
    }

    public function delete(User $actor, int $receiptId): void
    {
        $this->access->authorize($actor, self::MODULE, ModuleCapability::Edit);
        $path = DB::transaction(function () use ($actor, $receiptId): string {
            $receipt = ReceiptDocument::query()
                ->where('owner_user_id', $actor->id)
                ->whereKey($receiptId)
                ->lockForUpdate()
                ->firstOrFail();
            $this->access->authorize($actor, self::MODULE, ModuleCapability::Edit, $receipt);
            $path = $receipt->file_path;
            $receipt->delete();

            return $path;
        }, 3);

        Storage::disk('local')->delete($path);
    }

    /** @param list<string> $reviewRequired */
    private function createPreviewToken(User $actor, string $digest, array $reviewRequired): string
    {
        return Crypt::encryptString(json_encode([
            'user_id' => $actor->id,
            'file_digest' => $digest,
            'review_required' => $reviewRequired,
            'expires_at' => now()->addMinutes(15)->timestamp,
        ], JSON_THROW_ON_ERROR));
    }

    /** @return array{review_required: list<string>} */
    private function validatePreviewToken(User $actor, string $token, string $digest): array
    {
        try {
            $payload = json_decode(Crypt::decryptString($token), true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw ValidationException::withMessages(['preview_token' => 'Preview this invoice again before saving it.']);
        }

        if (! is_array($payload)
            || (int) ($payload['user_id'] ?? 0) !== $actor->id
            || ! is_int($payload['expires_at'] ?? null)
            || $payload['expires_at'] < now()->timestamp
            || ! is_string($payload['file_digest'] ?? null)
            || ! hash_equals($payload['file_digest'], $digest)
            || ! is_array($payload['review_required'] ?? null)
            || array_filter($payload['review_required'], static fn (mixed $field): bool => ! is_string($field)) !== []) {
            throw ValidationException::withMessages(['preview_token' => 'This invoice preview expired or the uploaded file changed. Preview it again.']);
        }

        return ['review_required' => array_values($payload['review_required'])];
    }

    /** @param array<string, mixed> $data
     * @return array{target_type: string, finance_category_id: ?int, vehicle_id: ?int, vehicle_category: ?string, description: string, merchant: ?string, invoice_number: ?string, invoice_date: string, amount: string, currency: string, tax_amount: ?string, fuel_liters: ?string, fuel_unit_price: ?string, fuel_odometer_value: ?string, reviewed_fields: list<string>, duplicate_acknowledged: bool, idempotency_key: string}
     */
    private function normalizeConfirmation(array $data): array
    {
        $amount = FinanceDecimalValue::money($data['amount'] ?? null);
        $rawTaxAmount = $data['tax_amount'] ?? null;
        $taxAmount = $rawTaxAmount === null || $rawTaxAmount === ''
            ? null
            : VehicleDecimalValue::money($rawTaxAmount);

        if ($amount === null) {
            throw ValidationException::withMessages(['amount' => 'Enter a valid non-negative invoice total.']);
        }
        if ($rawTaxAmount !== null && $rawTaxAmount !== '' && $taxAmount === null) {
            throw ValidationException::withMessages(['tax_amount' => 'Enter a valid non-negative tax amount.']);
        }

        $rawFuelLiters = $data['fuel_liters'] ?? null;
        $rawFuelUnitPrice = $data['fuel_unit_price'] ?? null;
        $rawFuelOdometerValue = $data['fuel_odometer_value'] ?? null;
        $fuelLiters = $rawFuelLiters === null || $rawFuelLiters === '' ? null : VehicleDecimalValue::fuelQuantity($rawFuelLiters);
        $fuelUnitPrice = $rawFuelUnitPrice === null || $rawFuelUnitPrice === '' ? null : VehicleDecimalValue::fuelUnitPrice($rawFuelUnitPrice);
        $fuelOdometerValue = $rawFuelOdometerValue === null || $rawFuelOdometerValue === '' ? null : VehicleDecimalValue::distance($rawFuelOdometerValue);
        if (($rawFuelLiters !== null && $rawFuelLiters !== '' && ($fuelLiters === null || preg_match('/\A0(?:\.0+)?\z/', $fuelLiters) === 1))
            || ($rawFuelUnitPrice !== null && $rawFuelUnitPrice !== '' && ($fuelUnitPrice === null || preg_match('/\A0(?:\.0+)?\z/', $fuelUnitPrice) === 1))) {
            throw ValidationException::withMessages(['fuel_liters' => 'Enter valid positive fuel quantity and unit price values.']);
        }
        if (($fuelLiters === null) !== ($fuelUnitPrice === null)) {
            throw ValidationException::withMessages(['fuel_liters' => 'Enter both fuel quantity and unit price, or leave both blank.']);
        }
        if ($rawFuelOdometerValue !== null && $rawFuelOdometerValue !== '' && $fuelOdometerValue === null) {
            throw ValidationException::withMessages(['fuel_odometer_value' => 'Enter a valid non-negative odometer value.']);
        }

        $reviewedFields = $data['reviewed_fields'] ?? [];
        $reviewedFields = is_array($reviewedFields) ? array_values(array_unique(array_filter($reviewedFields, 'is_string'))) : [];

        return [
            'target_type' => (string) $data['target_type'],
            'finance_category_id' => isset($data['finance_category_id']) ? (int) $data['finance_category_id'] : null,
            'vehicle_id' => isset($data['vehicle_id']) ? (int) $data['vehicle_id'] : null,
            'vehicle_category' => isset($data['vehicle_category']) ? (string) $data['vehicle_category'] : null,
            'description' => trim((string) $data['description']),
            'merchant' => $this->nullableTrim($data['merchant'] ?? null),
            'invoice_number' => $this->nullableTrim($data['invoice_number'] ?? null),
            'invoice_date' => (string) $data['invoice_date'],
            'amount' => $amount,
            'currency' => strtoupper(trim((string) $data['currency'])),
            'tax_amount' => $taxAmount,
            'fuel_liters' => $fuelLiters,
            'fuel_unit_price' => $fuelUnitPrice,
            'fuel_odometer_value' => $fuelOdometerValue,
            'reviewed_fields' => $reviewedFields,
            'duplicate_acknowledged' => filter_var($data['duplicate_acknowledged'] ?? false, FILTER_VALIDATE_BOOL),
            'idempotency_key' => (string) $data['idempotency_key'],
        ];
    }

    /** @param array<string, mixed> $normalized */
    private function requestFingerprint(string $digest, array $normalized): string
    {
        return hash('sha256', json_encode([
            'file_digest' => $digest,
            'target_type' => $normalized['target_type'],
            'finance_category_id' => $normalized['finance_category_id'],
            'vehicle_id' => $normalized['vehicle_id'],
            'vehicle_category' => $normalized['vehicle_category'],
            'description' => $normalized['description'],
            'merchant' => $normalized['merchant'],
            'invoice_number' => $normalized['invoice_number'],
            'invoice_date' => $normalized['invoice_date'],
            'amount' => $normalized['amount'],
            'currency' => $normalized['currency'],
            'tax_amount' => $normalized['tax_amount'],
            'fuel_liters' => $normalized['fuel_liters'],
            'fuel_unit_price' => $normalized['fuel_unit_price'],
            'fuel_odometer_value' => $normalized['fuel_odometer_value'],
            'reviewed_fields' => $normalized['reviewed_fields'],
            'duplicate_acknowledged' => $normalized['duplicate_acknowledged'],
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    private function fileDigest(UploadedFile $file): string
    {
        $path = $file->getRealPath();
        if (! is_string($path) || ! is_file($path)) {
            throw ValidationException::withMessages(['file' => 'The uploaded invoice could not be read.']);
        }

        $digest = hash_file('sha256', $path);
        if (! is_string($digest)) {
            throw ValidationException::withMessages(['file' => 'The uploaded invoice could not be fingerprinted.']);
        }

        return $digest;
    }

    private function nullableTrim(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function ownedReceiptForUpdate(User $actor, int $receiptId): ReceiptDocument
    {
        $currentActor = User::query()->whereKey($actor->id)->where('is_active', true)->lockForUpdate()->first();
        if (! $currentActor instanceof User) {
            throw new AuthorizationException('An active receipt owner is required.');
        }

        return ReceiptDocument::query()
            ->where('owner_user_id', $currentActor->id)
            ->whereKey($receiptId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    /** @param array<string, mixed> $candidate
     * @return list<array{id: int, merchant: ?string, invoice_date: string, amount: string, currency: string, reasons: list<string>}>
     */
    private function findPotentialDuplicates(User $actor, string $digest, array $candidate): array
    {
        $matches = [];
        $base = ReceiptDocument::query()->where('owner_user_id', $actor->id);
        $exact = (clone $base)->where('file_digest', $digest)->limit(5)->get();
        foreach ($exact as $receipt) {
            $matches[$receipt->id] = ['receipt' => $receipt, 'reasons' => ['identical_file']];
        }

        $merchant = $this->nullableTrim($candidate['merchant'] ?? null);
        $invoiceNumber = $this->nullableTrim($candidate['invoice_number'] ?? null);
        if ($merchant !== null && $invoiceNumber !== null) {
            $sameInvoice = (clone $base)
                ->whereRaw('LOWER(merchant) = ?', [mb_strtolower($merchant)])
                ->whereRaw('LOWER(invoice_number) = ?', [mb_strtolower($invoiceNumber)])
                ->limit(5)
                ->get();
            foreach ($sameInvoice as $receipt) {
                $matches[$receipt->id] ??= ['receipt' => $receipt, 'reasons' => []];
                $matches[$receipt->id]['reasons'][] = 'merchant_invoice_number';
            }
        }

        $date = $candidate['invoice_date'] ?? null;
        $amount = $candidate['amount'] ?? null;
        $currency = $candidate['currency'] ?? null;
        if ($merchant !== null && is_string($date) && is_string($amount) && is_string($currency)) {
            $sameTotal = (clone $base)
                ->whereRaw('LOWER(merchant) = ?', [mb_strtolower($merchant)])
                ->whereDate('invoice_date', $date)
                ->where('amount', $amount)
                ->where('currency', strtoupper($currency))
                ->limit(5)
                ->get();
            foreach ($sameTotal as $receipt) {
                $matches[$receipt->id] ??= ['receipt' => $receipt, 'reasons' => []];
                $matches[$receipt->id]['reasons'][] = 'merchant_date_amount';
            }
        }

        return array_values(array_map(static function (array $match): array {
            /** @var ReceiptDocument $receipt */
            $receipt = $match['receipt'];

            return [
                'id' => (int) $receipt->id,
                'merchant' => $receipt->merchant,
                'invoice_date' => Carbon::parse($receipt->getRawOriginal('invoice_date'))->format('Y-m-d'),
                'amount' => (string) $receipt->amount,
                'currency' => $receipt->currency,
                'reasons' => array_values(array_unique($match['reasons'])),
            ];
        }, array_slice($matches, 0, 5, true)));
    }

    /** @return Builder<FinancialTransaction> */
    private function visibleFinanceTransactions(User $actor): Builder
    {
        $morphType = (new FinancialTransaction)->getMorphClass();

        return FinancialTransaction::query()->where('type', 'expense')->where(function (Builder $query) use ($actor, $morphType): void {
            $query->where('owner_user_id', $actor->id)
                ->orWhere('is_private', false)
                ->orWhereExists(function (QueryBuilder $permissions) use ($actor, $morphType): void {
                    $permissions->selectRaw('1')->from('resource_user_permissions')
                        ->whereColumn('resource_user_permissions.resource_id', 'financial_transactions.id')
                        ->where('resource_user_permissions.resource_type', $morphType)
                        ->where('resource_user_permissions.user_id', $actor->id)
                        ->where('resource_user_permissions.capability', ModuleCapability::View->value);
                });
        });
    }

    /** @return Builder<VehicleCost> */
    private function visibleVehicleCosts(User $actor): Builder
    {
        $morphType = (new VehicleCost)->getMorphClass();

        return VehicleCost::query()->where(function (Builder $query) use ($actor, $morphType): void {
            $query->where('owner_user_id', $actor->id)
                ->orWhere('is_private', false)
                ->orWhereExists(function (QueryBuilder $permissions) use ($actor, $morphType): void {
                    $permissions->selectRaw('1')->from('resource_user_permissions')
                        ->whereColumn('resource_user_permissions.resource_id', 'vehicle_costs.id')
                        ->where('resource_user_permissions.resource_type', $morphType)
                        ->where('resource_user_permissions.user_id', $actor->id)
                        ->where('resource_user_permissions.capability', ModuleCapability::View->value);
                });
        });
    }

    /** @return list<array{value: string, label: string}> */
    private function categoryFilterOptions(User $actor): array
    {
        $options = [];
        try {
            foreach ($this->finance->categories($actor) as $category) {
                if (($category['type'] ?? null) === 'expense') {
                    $options[] = ['value' => 'finance:'.(int) $category['id'], 'label' => 'Finance · '.(string) $category['name']];
                }
            }
        } catch (AuthorizationException|ModuleDisabled|ModuleNotFound) {
            // A disabled or inaccessible target module is omitted from receipt filters.
        }

        try {
            $this->access->authorize($actor, 'vehicles', ModuleCapability::View);
            foreach (['fuel' => 'Fuel / charging', 'maintenance' => 'Maintenance', 'insurance' => 'Insurance', 'tax' => 'Tax', 'registration' => 'Registration', 'finance' => 'Finance', 'other' => 'Other'] as $category => $label) {
                $options[] = ['value' => 'vehicle:'.$category, 'label' => 'Vehicle · '.$label];
            }
        } catch (AuthorizationException|ModuleDisabled|ModuleNotFound) {
            // A disabled or inaccessible target module is omitted from receipt filters.
        }

        return $options;
    }

    /** @return Builder<ReceiptDocument> */
    private function visibleReceipts(User $actor, ModuleCapability $capability): Builder
    {
        $morphType = (new ReceiptDocument)->getMorphClass();

        return ReceiptDocument::query()->where(function (Builder $query) use ($actor, $capability, $morphType): void {
            $query->where('owner_user_id', $actor->id)
                ->orWhere('is_private', false)
                ->orWhereExists(function (QueryBuilder $permissions) use ($actor, $capability, $morphType): void {
                    $permissions->selectRaw('1')
                        ->from('resource_user_permissions')
                        ->whereColumn('resource_user_permissions.resource_id', 'receipt_documents.id')
                        ->where('resource_user_permissions.resource_type', $morphType)
                        ->where('resource_user_permissions.user_id', $actor->id)
                        ->where('resource_user_permissions.capability', $capability->value);
                });
        });
    }

    /** @return array<string, mixed> */
    private function present(ReceiptDocument $receipt, User $actor): array
    {
        $target = $this->visibleTargetReference($receipt, $actor);

        return [
            'id' => (int) $receipt->id,
            'target_type' => $target['type'] ?? null,
            'target_record_id' => $target['id'] ?? null,
            'target_category' => $target['category'] ?? null,
            'target_linked' => $target !== null || ($receipt->ownerUserId() === $actor->id && ($receipt->financial_transaction_id !== null || $receipt->vehicle_cost_id !== null)),
            'merchant' => $receipt->merchant,
            'description' => $receipt->description,
            'invoice_number' => $receipt->invoice_number,
            'invoice_date' => Carbon::parse($receipt->getRawOriginal('invoice_date'))->format('Y-m-d'),
            'amount' => (string) $receipt->amount,
            'currency' => $receipt->currency,
            'tax_amount' => $receipt->tax_amount === null ? null : (string) $receipt->tax_amount,
            'fuel_liters' => $receipt->fuel_liters === null ? null : (string) $receipt->fuel_liters,
            'fuel_unit_price' => $receipt->fuel_unit_price === null ? null : (string) $receipt->fuel_unit_price,
            'fuel_odometer_value' => $receipt->fuel_odometer_value === null ? null : (string) $receipt->fuel_odometer_value,
            'shared_with_household' => ! $receipt->isPrivate(),
            'is_owner' => $receipt->ownerUserId() === $actor->id,
            'mime_type' => $receipt->mime_type,
            'file_url' => '/api/receipts/'.$receipt->id.'/file',
            'created_at' => $receipt->created_at?->toISOString(),
        ];
    }

    /** @return array{type: string, id: int, category: ?string}|null */
    private function visibleTargetReference(ReceiptDocument $receipt, User $actor): ?array
    {
        if ($receipt->ownerUserId() === $actor->id) {
            if ($receipt->financial_transaction_id !== null) {
                $transaction = FinancialTransaction::query()->find($receipt->financial_transaction_id);

                return $transaction instanceof FinancialTransaction
                    ? ['type' => 'finance', 'id' => (int) $transaction->id, 'category' => 'finance:'.(int) $transaction->finance_category_id]
                    : null;
            }
            if ($receipt->vehicle_cost_id !== null) {
                $cost = VehicleCost::query()->find($receipt->vehicle_cost_id);

                return $cost instanceof VehicleCost
                    ? ['type' => 'vehicle', 'id' => (int) $cost->id, 'category' => 'vehicle:'.$cost->category]
                    : null;
            }

            return null;
        }

        try {
            if ($receipt->financial_transaction_id !== null) {
                $transaction = FinancialTransaction::query()->find($receipt->financial_transaction_id);
                if ($transaction instanceof FinancialTransaction) {
                    $this->access->authorize($actor, 'finance', ModuleCapability::View, $transaction);
                    $category = FinanceCategory::query()->find($transaction->finance_category_id);
                    $categoryValue = null;
                    if ($category instanceof FinanceCategory) {
                        $this->access->authorize($actor, 'finance', ModuleCapability::View, $category);
                        $categoryValue = 'finance:'.(int) $category->id;
                    }

                    return ['type' => 'finance', 'id' => (int) $transaction->id, 'category' => $categoryValue];
                }
            }

            if ($receipt->vehicle_cost_id !== null) {
                $cost = VehicleCost::query()->find($receipt->vehicle_cost_id);
                if ($cost instanceof VehicleCost) {
                    $this->access->authorize($actor, 'vehicles', ModuleCapability::View, $cost);

                    return ['type' => 'vehicle', 'id' => (int) $cost->id, 'category' => 'vehicle:'.$cost->category];
                }
            }
        } catch (AuthorizationException|ModuleDisabled|ModuleNotFound) {
            return null;
        }

        return null;
    }

    /** @return array<string, mixed>|null */
    private function existingTarget(ReceiptDocument $receipt): ?array
    {
        if ($receipt->financial_transaction_id !== null) {
            return ['type' => 'finance', 'id' => (int) $receipt->financial_transaction_id, 'record' => null];
        }
        if ($receipt->vehicle_cost_id !== null) {
            return ['type' => 'vehicle', 'id' => (int) $receipt->vehicle_cost_id, 'record' => null];
        }

        return null;
    }
}
