<?php

namespace App\Services\Receipts;

use App\Enums\ModuleCapability;
use App\Exceptions\LocalReceiptOcrUnavailable;
use App\Exceptions\ModuleDisabled;
use App\Models\ReceiptDocument;
use App\Models\User;
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

    /** @return list<array<string, mixed>> */
    public function index(User $actor): array
    {
        $this->access->authorize($actor, self::MODULE, ModuleCapability::View);

        return $this->visibleReceipts($actor, ModuleCapability::View)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(100)
            ->get()
            ->map(fn (ReceiptDocument $receipt): array => $this->present($receipt))
            ->all();
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
            $text = $this->ocr->extract($file);
        } catch (LocalReceiptOcrUnavailable) {
            throw new HttpResponseException(
                response()->json([
                    'message' => 'Local receipt OCR is unavailable. You can still enter an expense in its source module.',
                ], Response::HTTP_SERVICE_UNAVAILABLE),
            );
        }

        return [
            'preview_token' => $this->createPreviewToken($actor, $this->fileDigest($file)),
            'suggestions' => $this->parser->parse($text),
            'default_currency' => strtoupper((string) ($actor->default_currency ?: 'EUR')),
            'needs_confirmation' => true,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{created: bool, receipt: array<string, mixed>, target: array<string, mixed>}
     */
    public function confirm(User $actor, UploadedFile $file, array $data): array
    {
        $this->access->authorize($actor, self::MODULE, ModuleCapability::Create);
        $digest = $this->fileDigest($file);
        $this->validatePreviewToken($actor, (string) $data['preview_token'], $digest);
        $normalized = $this->normalizeConfirmation($data);
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
                        'receipt' => $this->present($prior),
                        'target' => $this->existingTarget($prior),
                    ];
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
                        $target = $this->vehicles->addCost($actor, $normalized['vehicle_id'], [
                            'category' => $normalized['vehicle_category'],
                            'amount' => $normalized['amount'],
                            'currency' => $normalized['currency'],
                            'period_start' => $normalized['invoice_date'],
                            'period_end' => null,
                            'period' => 'once',
                            'description' => $normalized['description'],
                        ]);
                        $targetId = (int) $target['id'];
                        $financialTransactionId = null;
                        $vehicleCostId = $targetId;
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
                    'invoice_number' => $normalized['invoice_number'],
                    'invoice_date' => $normalized['invoice_date'],
                    'amount' => $normalized['amount'],
                    'currency' => $normalized['currency'],
                    'tax_amount' => $normalized['tax_amount'],
                    'idempotency_key' => $normalized['idempotency_key'],
                    'file_digest' => $digest,
                    'request_fingerprint' => $fingerprint,
                ]);
                $this->access->authorize($actor, self::MODULE, ModuleCapability::Create, $receipt);

                return [
                    'created' => true,
                    'receipt' => $this->present($receipt),
                    'target' => ['type' => $normalized['target_type'], 'id' => $targetId, 'record' => $target],
                ];
            }, 1);
        } catch (Throwable $exception) {
            if (is_string($storedPath)) {
                Storage::disk('local')->delete($storedPath);
            }

            throw $exception;
        }
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
            $receipt = $this->visibleReceipts($actor, ModuleCapability::Edit)
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

    private function createPreviewToken(User $actor, string $digest): string
    {
        return Crypt::encryptString(json_encode([
            'user_id' => $actor->id,
            'file_digest' => $digest,
            'expires_at' => now()->addMinutes(15)->timestamp,
        ], JSON_THROW_ON_ERROR));
    }

    private function validatePreviewToken(User $actor, string $token, string $digest): void
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
            || ! hash_equals($payload['file_digest'], $digest)) {
            throw ValidationException::withMessages(['preview_token' => 'This invoice preview expired or the uploaded file changed. Preview it again.']);
        }
    }

    /** @param array<string, mixed> $data
     * @return array{target_type: string, finance_category_id: ?int, vehicle_id: ?int, vehicle_category: ?string, description: string, merchant: ?string, invoice_number: ?string, invoice_date: string, amount: string, currency: string, tax_amount: ?string, idempotency_key: string}
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

    /** @return Builder<ReceiptDocument> */
    private function visibleReceipts(User $actor, ModuleCapability $capability): Builder
    {
        $morphType = (new ReceiptDocument)->getMorphClass();

        return ReceiptDocument::query()->where(function (Builder $query) use ($actor, $capability, $morphType): void {
            $query->where('owner_user_id', $actor->id)
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
    private function present(ReceiptDocument $receipt): array
    {
        return [
            'id' => (int) $receipt->id,
            'target_type' => $receipt->financial_transaction_id !== null ? 'finance' : ($receipt->vehicle_cost_id !== null ? 'vehicle' : null),
            'target_record_id' => $receipt->financial_transaction_id !== null
                ? (int) $receipt->financial_transaction_id
                : ($receipt->vehicle_cost_id !== null ? (int) $receipt->vehicle_cost_id : null),
            'merchant' => $receipt->merchant,
            'invoice_number' => $receipt->invoice_number,
            'invoice_date' => Carbon::parse($receipt->getRawOriginal('invoice_date'))->format('Y-m-d'),
            'amount' => (string) $receipt->amount,
            'currency' => $receipt->currency,
            'tax_amount' => $receipt->tax_amount === null ? null : (string) $receipt->tax_amount,
            'mime_type' => $receipt->mime_type,
            'file_url' => '/api/receipts/'.$receipt->id.'/file',
            'created_at' => $receipt->created_at?->toISOString(),
        ];
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
