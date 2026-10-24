<?php

namespace App\Services\Import;

use App\Enums\ModuleCapability;
use App\Models\FinanceImportedTransaction;
use App\Models\FinanceImportRun;
use App\Models\User;
use App\Services\Finance\FinanceService;
use App\Services\Modules\ModuleAccessService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class FinanceTransactionImportService
{
    private const IMPORT_MODULE = 'import';

    public function __construct(
        private readonly ModuleAccessService $access,
        private readonly FinanceService $finance,
        private readonly FinanceTransactionImportParser $parser,
    ) {}

    /**
     * @param  array<string, mixed>|null  $mapping
     * @param  array<string, mixed>|null  $categoryDefaults
     * @return array<string, mixed>
     */
    public function preview(User $actor, UploadedFile $file, ?array $mapping = null, ?array $categoryDefaults = null, ?array $selectedRows = null): array
    {
        $this->authorize($actor);
        $categoryDefaults = $this->normalizeCategoryDefaults($categoryDefaults ?? []);
        $isXml = $this->parser->isXml($file);

        if ($isXml && $mapping !== null) {
            throw ValidationException::withMessages(['mapping' => 'Column mapping applies only to CSV files.']);
        }

        if (! $isXml && $mapping === null) {
            $columns = $this->parser->columns($file);

            return [
                'format' => 'csv',
                'mapping_required' => true,
                'columns' => $columns,
                'suggested_mapping' => $this->parser->suggestedMapping($columns),
                'categories' => $this->finance->importableCategories($actor),
            ];
        }

        $normalizedMapping = $isXml ? null : $this->parser->normalizeMapping($mapping, $this->parser->columns($file));
        $parsed = $isXml ? $this->parser->parseCamt053($file) : $this->parser->parseCsv($file, $normalizedMapping ?? []);
        $analysis = $this->analyze($actor, $parsed, $categoryDefaults);
        $selectableRows = array_column($analysis['new_rows'], 'row');
        $selectedRows = $this->normalizeSelectedRows($selectedRows, $selectableRows);
        $analysis = $this->applySelection($analysis, $selectedRows);
        $fingerprint = $this->requestFingerprint($file, $normalizedMapping, $categoryDefaults, $selectedRows);

        return [
            'format' => $isXml ? 'camt.053' : 'csv',
            'mapping_required' => false,
            'mapping' => $normalizedMapping,
            'category_defaults' => $categoryDefaults,
            'preview_token' => $this->previewToken($actor, $fingerprint, $selectedRows, $selectableRows),
            'selected_rows' => $selectedRows,
            'total_rows' => count($parsed['rows']) + count($parsed['errors']),
            'ready_count' => count($analysis['new_rows']),
            'skipped_count' => $analysis['skipped_count'],
            'rows' => $analysis['rows'],
            'errors' => $analysis['errors'],
            'categories' => $this->finance->importableCategories($actor),
        ];
    }

    /** @param array<string, mixed>|null $mapping
     * @param  array<string, mixed>|null  $categoryDefaults
     * @return array{message: string, created_count: int, skipped_count: int}
     */
    public function confirm(
        User $actor,
        UploadedFile $file,
        string $previewToken,
        string $idempotencyKey,
        ?array $mapping = null,
        ?array $categoryDefaults = null,
        ?array $selectedRows = null,
    ): array {
        $this->authorize($actor);
        $categoryDefaults = $this->normalizeCategoryDefaults($categoryDefaults ?? []);
        $isXml = $this->parser->isXml($file);
        if ($isXml && $mapping !== null) {
            throw ValidationException::withMessages(['mapping' => 'Column mapping applies only to CSV files.']);
        }
        $normalizedMapping = $isXml ? null : $this->parser->normalizeMapping($mapping ?? [], $this->parser->columns($file));
        $parsed = $isXml ? $this->parser->parseCamt053($file) : $this->parser->parseCsv($file, $normalizedMapping ?? []);
        $tokenPayload = $this->decodePreviewToken($actor, $previewToken);
        $selectableRows = is_array($tokenPayload['selectable_rows'] ?? null) ? $tokenPayload['selectable_rows'] : [];
        $selection = $this->normalizeSelectedRows($selectedRows ?? ($tokenPayload['selected_rows'] ?? null), $selectableRows);
        if ($selection !== array_values($tokenPayload['selected_rows'] ?? [])) {
            throw new HttpResponseException(
                response()->json(['message' => 'The selected rows changed. Preview the selection again before importing.'], Response::HTTP_CONFLICT),
            );
        }
        $fingerprint = $this->requestFingerprint($file, $normalizedMapping, $categoryDefaults, $selection);
        $this->assertPreviewToken($tokenPayload, $fingerprint);

        return DB::transaction(function () use ($actor, $fingerprint, $idempotencyKey, $parsed, $categoryDefaults, $selection): array {
            User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $this->authorize($actor);
            $prior = FinanceImportRun::query()
                ->where('user_id', $actor->id)
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($prior instanceof FinanceImportRun) {
                if (! hash_equals($prior->fingerprint, $fingerprint)) {
                    throw new HttpResponseException(
                        response()->json(['message' => 'This idempotency key was already used for a different finance import.'], Response::HTTP_CONFLICT),
                    );
                }

                return $this->result($prior->created_count, $prior->skipped_count);
            }

            $analysis = $this->analyze($actor, $parsed, $categoryDefaults);
            $currentEligibleRows = array_map('intval', array_column(
                array_filter($analysis['rows'], static fn (array $row): bool => in_array($row['action'], ['import', 'skip'], true)),
                'row',
            ));
            if (array_diff($selection, $currentEligibleRows) !== []) {
                throw new HttpResponseException(
                    response()->json(['message' => 'A selected transaction is no longer eligible. Review a new preview before importing.'], Response::HTTP_CONFLICT),
                );
            }
            if ($analysis['errors'] !== [] && $selection === []) {
                throw ValidationException::withMessages([
                    'file' => 'No valid transactions are selected. Correct the reported rows or select valid transactions before importing.',
                ]);
            }

            $analysis = $this->applySelection($analysis, $selection);
            foreach ($analysis['new_rows'] as $row) {
                $transaction = $this->finance->createTransaction($actor, [
                    'finance_category_id' => $row['finance_category_id'],
                    'type' => $row['type'],
                    'description' => $row['description'],
                    'payee' => $row['payee'],
                    'amount' => $row['amount'],
                    'currency' => $row['currency'],
                    'occurred_on' => $row['occurred_on'],
                    'visibility' => 'private',
                ]);
                FinanceImportedTransaction::query()->create([
                    'user_id' => $actor->id,
                    'financial_transaction_id' => $transaction['id'],
                    'source_fingerprint' => $row['source_fingerprint'],
                ]);
            }

            $run = FinanceImportRun::query()->create([
                'user_id' => $actor->id,
                'idempotency_key' => $idempotencyKey,
                'fingerprint' => $fingerprint,
                'created_count' => count($analysis['new_rows']),
                'skipped_count' => $analysis['skipped_count'],
            ]);

            return $this->result($run->created_count, $run->skipped_count);
        }, 3);
    }

    private function authorize(User $actor): void
    {
        $this->access->authorize($actor, self::IMPORT_MODULE, ModuleCapability::Create);
        $this->access->authorize($actor, 'finance', ModuleCapability::View);
        $this->access->authorize($actor, 'finance', ModuleCapability::Create);
    }

    /** @param array<string, mixed> $categoryDefaults
     * @return array{expense: ?int, income: ?int}
     */
    private function normalizeCategoryDefaults(array $categoryDefaults): array
    {
        $normalized = [];
        foreach (['expense', 'income'] as $type) {
            $value = $categoryDefaults[$type] ?? null;
            if ($value === null || $value === '') {
                $normalized[$type] = null;

                continue;
            }
            if (! is_numeric($value) || (string) (int) $value !== (string) $value || (int) $value < 1) {
                throw ValidationException::withMessages(["category_defaults.{$type}" => 'Choose a valid Finance category.']);
            }
            $normalized[$type] = (int) $value;
        }

        return $normalized;
    }

    /**
     * @param  array{rows: list<array<string, mixed>>, errors: list<array{row: int, message: string}>}  $parsed
     * @param  array{expense: ?int, income: ?int}  $categoryDefaults
     * @return array{rows: list<array<string, mixed>>, new_rows: list<array<string, mixed>>, skipped_count: int, errors: list<array{row: int, message: string}>}
     */
    private function analyze(User $actor, array $parsed, array $categoryDefaults): array
    {
        $errors = $parsed['errors'];
        $rows = [];
        $newRows = [];
        $skippedCount = 0;
        $seen = [];
        $categories = $this->finance->importableCategories($actor);
        $categoriesById = [];
        $categoriesByName = [];
        foreach ($categories as $category) {
            $categoryId = (int) $category['id'];
            $type = (string) $category['type'];
            $categoriesById[$categoryId] = $category;
            $categoriesByName[$type.'|'.mb_strtolower(trim((string) $category['name']))][] = $category;
        }

        $normalizedRows = [];
        foreach ($parsed['rows'] as $row) {
            $category = null;
            if (is_string($row['category_name'] ?? null) && trim($row['category_name']) !== '') {
                $matches = $categoriesByName[$row['type'].'|'.mb_strtolower(trim($row['category_name']))] ?? [];
                $category = count($matches) === 1 ? $matches[0] : null;
                if ($category === null) {
                    $errors[] = ['row' => (int) $row['row'], 'message' => count($matches) === 0
                        ? 'No accessible category matches this transaction type and category name.'
                        : 'More than one accessible category matches this name. Remove the category mapping or use an unambiguous category name.'];
                    $rows[] = [...$row, 'category' => null, 'action' => 'invalid'];

                    continue;
                }
            } else {
                $defaultId = $categoryDefaults[$row['type']] ?? null;
                $candidate = $defaultId === null ? null : ($categoriesById[$defaultId] ?? null);
                if ($candidate === null || $candidate['type'] !== $row['type']) {
                    $errors[] = ['row' => (int) $row['row'], 'message' => 'Choose an accessible default category for each transaction type or map a category column.'];
                    $rows[] = [...$row, 'category' => null, 'action' => 'invalid'];

                    continue;
                }
                $category = $candidate;
            }

            $sourceFingerprint = $this->rowFingerprint($row);
            $manualFingerprint = $this->manualFingerprint($row);
            $normalizedRows[] = [
                ...$row,
                'finance_category_id' => (int) $category['id'],
                'category' => ['id' => (int) $category['id'], 'name' => (string) $category['name']],
                'source_fingerprint' => $sourceFingerprint,
                'manual_fingerprint' => $manualFingerprint,
            ];
        }

        $dates = array_values(array_unique(array_column($normalizedRows, 'occurred_on')));
        $existingManual = [];
        foreach ($this->finance->visibleTransactionsOnDates($actor, $dates) as $transaction) {
            $existingManual[$this->manualFingerprint($transaction)] = true;
        }
        $existingImported = FinanceImportedTransaction::query()
            ->where('user_id', $actor->id)
            ->whereIn('source_fingerprint', array_column($normalizedRows, 'source_fingerprint'))
            ->pluck('source_fingerprint')->all();
        $importedFingerprints = array_fill_keys($existingImported, true);

        foreach ($normalizedRows as $row) {
            $sourceFingerprint = $row['source_fingerprint'];
            $manualFingerprint = $row['manual_fingerprint'];
            if (isset($seen[$sourceFingerprint]) || isset($importedFingerprints[$sourceFingerprint]) || isset($existingManual[$manualFingerprint])) {
                $skippedCount++;
                $rows[] = [...$row, 'action' => 'skip'];

                continue;
            }
            $seen[$sourceFingerprint] = true;
            $newRows[] = $row;
            $rows[] = [...$row, 'action' => 'import'];
        }

        return [
            'rows' => array_map(static function (array $row): array {
                unset($row['source_fingerprint'], $row['manual_fingerprint'], $row['finance_category_id']);

                return $row;
            }, $rows),
            'new_rows' => $newRows,
            'skipped_count' => $skippedCount,
            'errors' => $errors,
        ];
    }

    /** @param array<string, mixed> $analysis
     * @param  list<int>  $selectedRows
     * @return array<string, mixed>
     */
    private function applySelection(array $analysis, array $selectedRows): array
    {
        $selected = array_fill_keys($selectedRows, true);
        $newRows = array_values(array_filter(
            $analysis['new_rows'],
            static fn (array $row): bool => isset($selected[$row['row']]),
        ));
        foreach ($analysis['rows'] as &$row) {
            if ($row['action'] === 'import') {
                $row['selected'] = isset($selected[$row['row']]);
                if (! $row['selected']) {
                    $row['action'] = 'omit';
                }
            } else {
                $row['selected'] = false;
            }
        }
        unset($row);

        return [
            ...$analysis,
            'rows' => $analysis['rows'],
            'new_rows' => $newRows,
        ];
    }

    /** @param array<array-key, mixed>|null $requested
     * @param  list<int>  $allowed
     * @return list<int>
     */
    private function normalizeSelectedRows(?array $requested, array $allowed): array
    {
        $allowed = array_map('intval', $allowed);
        if ($requested === null) {
            $selection = $allowed;
        } else {
            $selection = [];
            foreach ($requested as $row) {
                if (! is_numeric($row) || (string) (int) $row !== (string) $row || ! in_array((int) $row, $allowed, true)) {
                    throw ValidationException::withMessages(['selected_rows' => 'Select only valid transaction rows shown by the preview.']);
                }
                $selection[] = (int) $row;
            }
        }
        $selection = array_unique($selection);
        sort($selection, SORT_NUMERIC);

        return $selection;
    }

    /** @param array<string, mixed> $row */
    private function rowFingerprint(array $row): string
    {
        $sourceReference = trim((string) ($row['source_reference'] ?? ''));
        $payload = [
            'occurred_on' => $row['occurred_on'],
            'type' => $row['type'],
            'amount' => $row['amount'],
            'currency' => $row['currency'],
            'description' => $row['description'],
            'payee' => $this->normalizedPayee($row['payee'] ?? null),
            'source_reference' => $sourceReference === '' ? null : $sourceReference,
        ];

        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    /** @param array<string, mixed> $row */
    private function manualFingerprint(array $row): string
    {
        return hash('sha256', json_encode([
            'occurred_on' => $row['occurred_on'],
            'type' => $row['type'],
            'amount' => $row['amount'],
            'currency' => $row['currency'],
            'description' => $row['description'],
            'payee' => $this->normalizedPayee($row['payee'] ?? null),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    private function normalizedPayee(mixed $payee): ?string
    {
        if (! is_string($payee)) {
            return null;
        }

        $payee = trim($payee);

        return $payee === '' ? null : $payee;
    }

    /** @param array<string, mixed>|null $mapping
     * @param  array{expense: ?int, income: ?int}  $categoryDefaults
     */
    private function requestFingerprint(UploadedFile $file, ?array $mapping, array $categoryDefaults, array $selectedRows): string
    {
        $fileHash = hash_file('sha256', $file->getRealPath());
        if (! is_string($fileHash)) {
            throw ValidationException::withMessages(['file' => 'The upload could not be fingerprinted.']);
        }

        return hash('sha256', json_encode([
            'file_sha256' => $fileHash,
            'mapping' => $mapping,
            'category_defaults' => $categoryDefaults,
            'selected_rows' => $selectedRows,
        ], JSON_THROW_ON_ERROR));
    }

    /** @param list<int> $selectedRows
     * @param  list<int>  $selectableRows
     */
    private function previewToken(User $actor, string $fingerprint, array $selectedRows, array $selectableRows): string
    {
        return Crypt::encryptString(json_encode([
            'user_id' => $actor->id,
            'fingerprint' => $fingerprint,
            'selected_rows' => $selectedRows,
            'selectable_rows' => $selectableRows,
            'expires_at' => now()->addMinutes(20)->getTimestamp(),
        ], JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    private function decodePreviewToken(User $actor, string $token): array
    {
        try {
            $payload = json_decode(Crypt::decryptString($token), true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            $payload = null;
        }

        if (! is_array($payload)
            || ($payload['user_id'] ?? null) !== $actor->id
            || ! is_string($payload['fingerprint'] ?? null)
            || ! is_array($payload['selected_rows'] ?? null)
            || ! is_array($payload['selectable_rows'] ?? null)
            || ! is_int($payload['expires_at'] ?? null)
            || $payload['expires_at'] < now()->getTimestamp()) {
            throw new HttpResponseException(
                response()->json(['message' => 'The preview is invalid or expired. Create a new preview before importing.'], Response::HTTP_CONFLICT),
            );
        }

        return $payload;
    }

    /** @param array<string, mixed> $payload */
    private function assertPreviewToken(array $payload, string $fingerprint): void
    {
        if (! hash_equals($payload['fingerprint'], $fingerprint)) {
            throw new HttpResponseException(
                response()->json(['message' => 'The file or import options changed. Create a new preview before importing.'], Response::HTTP_CONFLICT),
            );
        }
    }

    /** @return array{message: string, created_count: int, skipped_count: int} */
    private function result(int $created, int $skipped): array
    {
        return [
            'message' => 'Finance transactions imported.',
            'created_count' => $created,
            'skipped_count' => $skipped,
        ];
    }
}
