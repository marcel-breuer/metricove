<?php

namespace App\Services\Import;

use App\Contracts\MeterPhotoOcr;
use App\Enums\ModuleCapability;
use App\Exceptions\LocalOcrUnavailable;
use App\Models\ImportRun;
use App\Models\User;
use App\Models\UtilityReading;
use App\Services\Modules\ModuleAccessService;
use App\Services\Utilities\DecimalValue;
use App\Services\Utilities\UtilityService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class ImportService
{
    private const MODULE = 'import';

    public function __construct(
        private readonly ModuleAccessService $access,
        private readonly UtilityService $utilities,
        private readonly UtilityReadingsCsvParser $csv,
        private readonly MeterPhotoOcr $ocr,
    ) {}

    /** @param array<string, mixed>|null $mapping
     * @return array<string, mixed>
     */
    public function previewUtilityReadings(User $actor, int $meterId, UploadedFile $file, ?array $mapping = null): array
    {
        $this->access->authorize($actor, self::MODULE, ModuleCapability::Create);
        $context = $this->utilities->importContext($actor, $meterId);
        $columns = $this->csv->columns($file);

        if ($mapping === null) {
            return [
                'mapping_required' => true,
                'columns' => $columns,
                'suggested_mapping' => $this->csv->suggestedMapping($columns),
            ];
        }

        $mapping = $this->csv->normalizeMapping($mapping, $columns);
        $parsed = $this->csv->parse($file, $mapping);
        $analysis = $this->analyze($parsed, $context['readings']);
        $fileHash = $this->requestFingerprint($file, $mapping);

        return [
            'preview_token' => $this->previewToken($actor, $meterId, $fileHash),
            'meter' => ['id' => (int) $context['meter']->id, 'name' => $context['meter']->name, 'unit' => $context['meter']->unit],
            'mapping_required' => false,
            'columns' => $columns,
            'mapping' => $mapping,
            'total_rows' => count($parsed['rows']) + count($parsed['errors']),
            'ready_count' => count($analysis['new_rows']),
            'skipped_count' => $analysis['skipped_count'],
            'rows' => $analysis['rows'],
            'errors' => $analysis['errors'],
        ];
    }

    /** @return array{message: string, created_count: int, skipped_count: int} */
    public function confirmUtilityReadings(
        User $actor,
        int $meterId,
        UploadedFile $file,
        string $previewToken,
        string $idempotencyKey,
        array $mapping,
    ): array {
        $this->access->authorize($actor, self::MODULE, ModuleCapability::Create);
        $columns = $this->csv->columns($file);
        $mapping = $this->csv->normalizeMapping($mapping, $columns);
        $fileHash = $this->requestFingerprint($file, $mapping);
        $this->assertPreviewToken($actor, $meterId, $fileHash, $previewToken);
        $parsed = $this->csv->parse($file, $mapping);

        return DB::transaction(function () use ($actor, $meterId, $fileHash, $idempotencyKey, $parsed): array {
            User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $context = $this->utilities->importContext($actor, $meterId, lock: true);
            $prior = ImportRun::query()
                ->where('user_id', $actor->id)
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($prior instanceof ImportRun) {
                if ((int) $prior->utility_meter_id !== $meterId || ! hash_equals($prior->file_sha256, $fileHash)) {
                    throw new HttpResponseException(
                        response()->json(['message' => 'This idempotency key was already used for a different import.'], Response::HTTP_CONFLICT),
                    );
                }

                return $this->importResult($prior->created_count, $prior->skipped_count);
            }

            $analysis = $this->analyze($parsed, $context['readings']);
            if ($analysis['errors'] !== []) {
                throw ValidationException::withMessages([
                    'file' => 'The CSV changed or is no longer valid. Review the preview and try again.',
                ]);
            }

            foreach ($analysis['new_rows'] as $row) {
                $this->utilities->createReading($actor, $meterId, [
                    'reading_date' => $row['reading_date'],
                    'value' => $row['value'],
                    'note' => $row['note'],
                ]);
            }

            $run = ImportRun::query()->create([
                'user_id' => $actor->id,
                'utility_meter_id' => $meterId,
                'idempotency_key' => $idempotencyKey,
                'file_sha256' => $fileHash,
                'created_count' => count($analysis['new_rows']),
                'skipped_count' => $analysis['skipped_count'],
            ]);

            return $this->importResult($run->created_count, $run->skipped_count);
        }, 3);
    }

    /** @return LazyCollection<int, UtilityReading> */
    public function exportUtilityReadings(User $actor, int $meterId): LazyCollection
    {
        $this->access->authorize($actor, self::MODULE, ModuleCapability::View);
        $this->access->authorize($actor, 'utilities', ModuleCapability::View);

        return $this->utilities->exportableReadings($actor, $meterId);
    }

    /** @return array{value: ?string, needs_confirmation: true, provider: string} */
    public function suggestUtilityReading(User $actor, int $meterId, UploadedFile $photo): array
    {
        $this->access->authorize($actor, self::MODULE, ModuleCapability::Create);
        $this->utilities->authorizeMeterForReading($actor, $meterId);

        try {
            $value = $this->ocr->suggest($photo);
        } catch (LocalOcrUnavailable) {
            throw new HttpResponseException(
                response()->json([
                    'message' => 'Local OCR is unavailable. You can still enter the reading manually.',
                ], Response::HTTP_SERVICE_UNAVAILABLE),
            );
        }

        return ['value' => $value, 'needs_confirmation' => true, 'provider' => 'local'];
    }

    /**
     * @param  array{rows: list<array{row: int, reading_date: string, value: string, note: ?string}>, errors: list<array{row: int, message: string}>}  $parsed
     * @param  list<UtilityReading>  $existing
     * @return array{rows: list<array<string, mixed>>, new_rows: list<array{row: int, reading_date: string, value: string, note: ?string}>, skipped_count: int, errors: list<array{row: int, message: string}>}
     */
    private function analyze(array $parsed, array $existing): array
    {
        $errors = $parsed['errors'];
        $rows = [];
        $newRows = [];
        $skippedCount = 0;
        $seen = [];
        $timeline = [];
        $existingByDate = [];

        foreach ($existing as $reading) {
            $date = $reading->reading_date->format('Y-m-d');
            $existingByDate[$date] = $reading;
            $timeline[$date] = ['reading_date' => $date, 'value' => (string) $reading->value, 'row' => null];
        }

        foreach ($parsed['rows'] as $row) {
            $date = $row['reading_date'];
            $existingReading = $existingByDate[$date] ?? null;

            if (isset($seen[$date])) {
                $first = $seen[$date];
                if (! $this->sameReading($first['value'], $first['note'], $row['value'], $row['note'])) {
                    $errors[] = ['row' => $row['row'], 'message' => 'This CSV contains different readings for the same date.'];
                    $rows[] = [...$row, 'action' => 'invalid'];
                } else {
                    $skippedCount++;
                    $rows[] = [...$row, 'action' => 'skip'];
                }

                continue;
            }
            $seen[$date] = $row;

            if ($existingReading instanceof UtilityReading) {
                if (! $this->sameReading((string) $existingReading->value, $existingReading->note, $row['value'], $row['note'])) {
                    $errors[] = ['row' => $row['row'], 'message' => 'A reading already exists for this date with a different value or note.'];
                    $rows[] = [...$row, 'action' => 'invalid'];
                } else {
                    $skippedCount++;
                    $rows[] = [...$row, 'action' => 'skip'];
                }

                continue;
            }

            $newRows[] = $row;
            $rows[] = [...$row, 'action' => 'import'];
            $timeline[$date] = ['reading_date' => $date, 'value' => $row['value'], 'row' => $row['row']];
        }

        $orderedTimeline = array_values($timeline);
        usort($orderedTimeline, static fn (array $left, array $right): int => strcmp($left['reading_date'], $right['reading_date']));

        for ($index = 1; $index < count($orderedTimeline); $index++) {
            $previous = $orderedTimeline[$index - 1];
            $current = $orderedTimeline[$index];

            if (DecimalValue::compare($previous['value'], $current['value']) > 0) {
                $rowNumber = $current['row'] ?? $previous['row'];
                if ($rowNumber !== null) {
                    $errors[] = ['row' => $rowNumber, 'message' => 'Readings must not decrease over time.'];
                }
            }
        }

        $errors = collect($errors)->unique(fn (array $error): string => $error['row'].'|'.$error['message'])
            ->sortBy('row')
            ->values()
            ->all();

        return [
            'rows' => $rows,
            'new_rows' => $newRows,
            'skipped_count' => $skippedCount,
            'errors' => $errors,
        ];
    }

    private function sameReading(string $leftValue, ?string $leftNote, string $rightValue, ?string $rightNote): bool
    {
        return DecimalValue::compare($leftValue, $rightValue) === 0
            && trim((string) $leftNote) === trim((string) $rightNote);
    }

    /** @param array{reading_date: int, value: int, note: ?int} $mapping */
    private function requestFingerprint(UploadedFile $file, array $mapping): string
    {
        $fileHash = hash_file('sha256', $file->getRealPath());
        if (! is_string($fileHash)) {
            throw ValidationException::withMessages(['file' => 'The CSV file could not be fingerprinted.']);
        }

        return hash('sha256', $fileHash."\0".json_encode($mapping, JSON_THROW_ON_ERROR));
    }

    private function previewToken(User $actor, int $meterId, string $fileHash): string
    {
        return Crypt::encryptString(json_encode([
            'user_id' => $actor->id,
            'meter_id' => $meterId,
            'file_sha256' => $fileHash,
            'expires_at' => now()->addMinutes(20)->getTimestamp(),
        ], JSON_THROW_ON_ERROR));
    }

    private function assertPreviewToken(User $actor, int $meterId, string $fileHash, string $token): void
    {
        try {
            $payload = json_decode(Crypt::decryptString($token), true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            $payload = null;
        }

        if (! is_array($payload)
            || (int) ($payload['user_id'] ?? 0) !== $actor->id
            || (int) ($payload['meter_id'] ?? 0) !== $meterId
            || ! is_string($payload['file_sha256'] ?? null)
            || ! hash_equals($payload['file_sha256'], $fileHash)
            || (int) ($payload['expires_at'] ?? 0) < now()->getTimestamp()) {
            throw ValidationException::withMessages(['preview_token' => 'The preview expired or no longer matches this file. Preview it again.']);
        }
    }

    /** @return array{message: string, created_count: int, skipped_count: int} */
    private function importResult(int $createdCount, int $skippedCount): array
    {
        return [
            'message' => 'Utility readings imported.',
            'created_count' => $createdCount,
            'skipped_count' => $skippedCount,
        ];
    }
}
