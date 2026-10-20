<?php

namespace App\Services\Import;

use App\Services\Utilities\DecimalValue;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

class UtilityReadingsCsvParser
{
    private const MAX_ROWS = 1000;

    private const MAX_COLUMNS = 100;

    /** @return list<string> */
    public function columns(UploadedFile $file): array
    {
        $handle = $this->open($file);

        try {
            return $this->readHeader($handle);
        } finally {
            fclose($handle);
        }
    }

    /** @param array<string, mixed> $mapping
     * @param  list<string>  $columns
     * @return array{reading_date: int, value: int, note: ?int}
     */
    public function normalizeMapping(array $mapping, array $columns): array
    {
        $normalized = [];
        foreach (['reading_date', 'value'] as $field) {
            $index = $mapping[$field] ?? null;
            if (! is_numeric($index) || (string) (int) $index !== (string) $index) {
                throw ValidationException::withMessages(["mapping.{$field}" => 'Choose a CSV column for this field.']);
            }
            $normalized[$field] = (int) $index;
        }

        $noteIndex = $mapping['note'] ?? null;
        if ($noteIndex !== null && $noteIndex !== '' && (! is_numeric($noteIndex) || (string) (int) $noteIndex !== (string) $noteIndex)) {
            throw ValidationException::withMessages(['mapping.note' => 'Choose a CSV column for the note or leave it blank.']);
        }
        $normalized['note'] = $noteIndex === null || $noteIndex === '' ? null : (int) $noteIndex;

        $indexes = array_values(array_filter([$normalized['reading_date'], $normalized['value'], $normalized['note']], static fn (?int $index): bool => $index !== null));
        if (count(array_unique($indexes)) !== count($indexes)) {
            throw ValidationException::withMessages(['mapping' => 'Choose a different CSV column for each mapped field.']);
        }

        foreach ($indexes as $index) {
            if (! array_key_exists($index, $columns)) {
                throw ValidationException::withMessages(['mapping' => 'A selected column is not present in this CSV file.']);
            }
        }

        return $normalized;
    }

    /**
     * Suggest a mapping from common header names. Unrecognized fields remain unmapped.
     *
     * @param  list<string>  $columns
     * @return array{reading_date: ?int, value: ?int, note: ?int}
     */
    public function suggestedMapping(array $columns): array
    {
        $aliases = [
            'reading_date' => ['reading_date', 'date', 'reading date', 'recorded_on'],
            'value' => ['value', 'reading_value', 'meter_reading', 'reading', 'register'],
            'note' => ['note', 'notes', 'comment', 'description'],
        ];
        $mapping = ['reading_date' => null, 'value' => null, 'note' => null];

        foreach ($columns as $index => $column) {
            $normalized = strtolower(trim($column));
            if (in_array($normalized, $aliases['reading_date'], true) && $mapping['reading_date'] === null) {
                $mapping['reading_date'] = $index;
            } elseif (in_array($normalized, $aliases['value'], true) && $mapping['value'] === null) {
                $mapping['value'] = $index;
            } elseif (in_array($normalized, $aliases['note'], true) && $mapping['note'] === null) {
                $mapping['note'] = $index;
            }
        }

        return $mapping;
    }

    /**
     * @param  array{reading_date: int, value: int, note: ?int}  $mapping
     * @return array{rows: list<array{row: int, reading_date: string, value: string, note: ?string}>, errors: list<array{row: int, message: string}>}
     */
    public function parse(UploadedFile $file, array $mapping): array
    {
        $handle = $this->open($file);

        try {
            $columns = $this->readHeader($handle);
            $mapping = $this->normalizeMapping($mapping, $columns);
            $rows = [];
            $errors = [];
            $line = 1;

            while (($cells = fgetcsv($handle, null, ',', '"', '')) !== false) {
                $line++;
                if ($line > self::MAX_ROWS + 1) {
                    $errors[] = ['row' => $line, 'message' => 'A CSV import can contain no more than 1,000 rows.'];
                    break;
                }

                if ($cells === [null]) {
                    continue;
                }

                if (count($cells) !== count($columns)) {
                    $errors[] = ['row' => $line, 'message' => 'This row does not have the same number of columns as the header.'];

                    continue;
                }

                $date = trim((string) ($cells[$mapping['reading_date']] ?? ''));
                $rawValue = trim((string) ($cells[$mapping['value']] ?? ''));
                $rawNote = $mapping['note'] === null ? '' : trim((string) ($cells[$mapping['note']] ?? ''));
                $dateValue = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
                $dateIsValid = $dateValue instanceof \DateTimeImmutable
                    && $dateValue->format('Y-m-d') === $date
                    && \DateTimeImmutable::getLastErrors() === false;
                $value = DecimalValue::normalize($rawValue);
                $noteIsValid = preg_match('//u', $rawNote) === 1 && mb_strlen($rawNote) <= 2000;

                if (! $dateIsValid) {
                    $errors[] = ['row' => $line, 'message' => 'Use a valid date in YYYY-MM-DD format.'];

                    continue;
                }

                if ($value === null) {
                    $errors[] = ['row' => $line, 'message' => 'Use a non-negative decimal with up to 14 whole and 6 fractional digits.'];

                    continue;
                }

                if (! $noteIsValid) {
                    $errors[] = ['row' => $line, 'message' => 'Keep notes to 2,000 valid UTF-8 characters or fewer.'];

                    continue;
                }

                $rows[] = [
                    'row' => $line,
                    'reading_date' => $date,
                    'value' => $value,
                    'note' => $rawNote === '' ? null : $rawNote,
                ];
            }

            if ($rows === [] && $errors === []) {
                $errors[] = ['row' => 2, 'message' => 'Add at least one utility reading after the CSV header.'];
            }

            return ['rows' => $rows, 'errors' => $errors];
        } finally {
            fclose($handle);
        }
    }

    /** @param resource $handle
     * @return list<string>
     */
    private function readHeader($handle): array
    {
        $header = fgetcsv($handle, null, ',', '"', '');
        if (! is_array($header)) {
            throw ValidationException::withMessages(['file' => 'Add a CSV header with the columns to map.']);
        }

        if (isset($header[0]) && str_starts_with((string) $header[0], "\xEF\xBB\xBF")) {
            $header[0] = substr((string) $header[0], 3);
        }

        if (count($header) < 2 || count($header) > self::MAX_COLUMNS) {
            throw ValidationException::withMessages(['file' => 'A CSV header must contain between 2 and 100 columns.']);
        }

        $columns = array_map(static fn ($cell): string => trim((string) $cell), $header);
        foreach ($columns as $column) {
            if (preg_match('//u', $column) !== 1 || mb_strlen($column) > 200) {
                throw ValidationException::withMessages(['file' => 'Keep CSV column names to 200 valid UTF-8 characters or fewer.']);
            }
        }

        return $columns;
    }

    /** @return resource */
    private function open(UploadedFile $file)
    {
        $handle = fopen($file->getRealPath(), 'rb');
        if ($handle === false) {
            throw ValidationException::withMessages(['file' => 'The CSV file could not be read.']);
        }

        return $handle;
    }
}
