<?php

namespace App\Services\Import;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class FinanceTransactionImportParser
{
    private const MAX_ROWS = 1000;

    private const MAX_COLUMNS = 100;

    private const DELIMITERS = [',', ';', "\t"];

    /** @return list<string> */
    public function columns(UploadedFile $file): array
    {
        $delimiter = $this->detectDelimiter($file);
        $handle = $this->open($file);

        try {
            return $this->readHeader($handle, $delimiter);
        } finally {
            fclose($handle);
        }
    }

    public function isXml(UploadedFile $file): bool
    {
        $handle = $this->open($file);

        try {
            $prefix = fread($handle, 512);
        } finally {
            fclose($handle);
        }

        return is_string($prefix) && str_starts_with(ltrim($prefix, "\xEF\xBB\xBF\x00\t\n\r "), '<');
    }

    /** @param list<string> $columns
     * @param  array<string, mixed>  $mapping
     * @return array{occurred_on: int, description: int, amount: int, currency: int, payee: ?int, category: ?int}
     */
    public function normalizeMapping(array $mapping, array $columns): array
    {
        $normalized = [];
        foreach (['occurred_on', 'description', 'amount', 'currency'] as $field) {
            $normalized[$field] = $this->columnIndex($mapping[$field] ?? null, $field, true);
        }
        foreach (['payee', 'category'] as $field) {
            $normalized[$field] = $this->columnIndex($mapping[$field] ?? null, $field, false);
        }

        $indexes = array_values(array_filter($normalized, static fn (?int $index): bool => $index !== null));
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

    /** @param list<string> $columns
     * @return array{occurred_on: ?int, description: ?int, amount: ?int, currency: ?int, payee: ?int, category: ?int}
     */
    public function suggestedMapping(array $columns): array
    {
        $aliases = [
            'occurred_on' => ['occurred_on', 'date', 'booking date', 'booking_date', 'transaction date', 'transaction_date'],
            'description' => ['description', 'details', 'remittance information', 'purpose', 'name'],
            'amount' => ['amount', 'transaction amount', 'value'],
            'currency' => ['currency', 'currency code', 'ccy'],
            'payee' => ['payee', 'counterparty', 'merchant'],
            'category' => ['category', 'expense category'],
        ];
        $mapping = array_fill_keys(array_keys($aliases), null);

        foreach ($columns as $index => $column) {
            $normalized = Str::lower(trim($column));
            foreach ($aliases as $field => $fieldAliases) {
                if ($mapping[$field] === null && in_array($normalized, $fieldAliases, true)) {
                    $mapping[$field] = $index;
                    break;
                }
            }
        }

        return $mapping;
    }

    /** @param array<string, mixed> $mapping
     * @return array{rows: list<array{row: int, occurred_on: string, description: string, amount: string, currency: string, type: string, payee: ?string, category_name: ?string, source_reference: ?string}>, errors: list<array{row: int, message: string}>}
     */
    public function parseCsv(UploadedFile $file, array $mapping): array
    {
        $delimiter = $this->detectDelimiter($file);
        $handle = $this->open($file);

        try {
            $columns = $this->readHeader($handle, $delimiter);
            $mapping = $this->normalizeMapping($mapping, $columns);
            $rows = [];
            $errors = [];
            $line = 1;

            while (($cells = fgetcsv($handle, null, $delimiter, '"', '')) !== false) {
                $line++;
                if ($line > self::MAX_ROWS + 1) {
                    $errors[] = ['row' => $line, 'message' => 'A finance import can contain no more than 1,000 transactions.'];
                    break;
                }
                if ($cells === [null]) {
                    continue;
                }
                if (count($cells) !== count($columns)) {
                    $errors[] = ['row' => $line, 'message' => 'This row does not have the same number of columns as the header.'];

                    continue;
                }

                $date = trim((string) $cells[$mapping['occurred_on']]);
                $description = trim((string) $cells[$mapping['description']]);
                $rawAmount = trim((string) $cells[$mapping['amount']]);
                $currency = strtoupper(trim((string) $cells[$mapping['currency']]));
                $payee = $mapping['payee'] === null ? null : trim((string) $cells[$mapping['payee']]);
                $category = $mapping['category'] === null ? null : trim((string) $cells[$mapping['category']]);
                $dateValue = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
                $dateIsValid = $dateValue instanceof \DateTimeImmutable
                    && $dateValue->format('Y-m-d') === $date
                    && \DateTimeImmutable::getLastErrors() === false;
                $money = $this->signedMoney($rawAmount);

                if (! $dateIsValid) {
                    $errors[] = ['row' => $line, 'message' => 'Use a valid date in YYYY-MM-DD format.'];

                    continue;
                }
                if ($description === '' || mb_strlen($description) > 200 || preg_match('//u', $description) !== 1) {
                    $errors[] = ['row' => $line, 'message' => 'Add a description with up to 200 valid UTF-8 characters.'];

                    continue;
                }
                if ($money === null) {
                    $errors[] = ['row' => $line, 'message' => 'Use a non-zero signed amount with up to 16 whole and 2 fractional digits. Negative amounts are income; positive amounts are expenses.'];

                    continue;
                }
                if (preg_match('/\A[A-Z]{3}\z/', $currency) !== 1) {
                    $errors[] = ['row' => $line, 'message' => 'Use a three-letter ISO currency code.'];

                    continue;
                }
                if (($payee !== null && (mb_strlen($payee) > 200 || preg_match('//u', $payee) !== 1))
                    || ($category !== null && (mb_strlen($category) > 80 || preg_match('//u', $category) !== 1))) {
                    $errors[] = ['row' => $line, 'message' => 'Keep payees to 200 and categories to 80 valid UTF-8 characters or fewer.'];

                    continue;
                }

                $rows[] = [
                    'row' => $line,
                    'occurred_on' => $date,
                    'description' => $description,
                    'amount' => $money['amount'],
                    'currency' => $currency,
                    'type' => $money['type'],
                    'payee' => $payee === '' ? null : $payee,
                    'category_name' => $category === '' ? null : $category,
                    'source_reference' => null,
                ];
            }

            if ($rows === [] && $errors === []) {
                $errors[] = ['row' => 2, 'message' => 'Add at least one finance transaction after the CSV header.'];
            }

            return ['rows' => $rows, 'errors' => $errors];
        } finally {
            fclose($handle);
        }
    }

    /**
     * Parse the common CAMT.053 statement structure, including transaction-level details when present.
     * D = expense and C = income. Reversal entries invert that direction.
     *
     * @return array{rows: list<array{row: int, occurred_on: string, description: string, amount: string, currency: string, type: string, payee: ?string, category_name: ?string, source_reference: ?string}>, errors: list<array{row: int, message: string}>}
     */
    public function parseCamt053(UploadedFile $file): array
    {
        $xml = file_get_contents($file->getRealPath());
        if (! is_string($xml) || strlen($xml) > 10 * 1024 * 1024 || preg_match('/<!\s*(?:DOCTYPE|ENTITY)/i', $xml) === 1) {
            throw ValidationException::withMessages(['file' => 'Upload a valid CAMT.053 XML statement without document type declarations.']);
        }

        $document = new \DOMDocument;
        $document->resolveExternals = false;
        $document->substituteEntities = false;
        $loaded = $document->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS | LIBXML_NSCLEAN);
        if (! $loaded || ! $document->documentElement instanceof \DOMElement) {
            throw ValidationException::withMessages(['file' => 'Upload a well-formed CAMT.053 XML statement.']);
        }

        $rootName = $document->documentElement->localName;
        $namespace = $document->documentElement->namespaceURI ?? '';
        if (! in_array($rootName, ['Document', 'BkToCstmrStmt'], true)
            || preg_match('/camt\.053\.001\.\d+\z/', $namespace) !== 1) {
            throw ValidationException::withMessages(['file' => 'The XML root is not a supported CAMT.053 statement.']);
        }

        $xpath = new \DOMXPath($document);
        $entries = $xpath->query('//*[local-name()="Ntry"]');
        if ($entries === false || $entries->length === 0) {
            throw ValidationException::withMessages(['file' => 'The CAMT.053 statement contains no transaction entries.']);
        }

        $rows = [];
        $errors = [];
        $rowNumber = 1;
        foreach ($entries as $entry) {
            if (! $entry instanceof \DOMElement) {
                continue;
            }
            $date = $this->nodeText($xpath, './/*[local-name()="BookgDt"]/*[local-name()="Dt"]', $entry)
                ?? $this->nodeText($xpath, './/*[local-name()="BookgDt"]/*[local-name()="DtTm"]', $entry)
                ?? $this->nodeText($xpath, './/*[local-name()="ValDt"]/*[local-name()="Dt"]', $entry);
            $indicator = strtoupper((string) ($this->nodeText($xpath, './*[local-name()="CdtDbtInd"]', $entry) ?? ''));
            $reversal = strtolower((string) ($this->nodeText($xpath, './*[local-name()="RvslInd"]', $entry) ?? '')) === 'true';
            $entryInfo = $this->nodeText($xpath, './*[local-name()="AddtlNtryInf"]', $entry);
            $details = $xpath->query('.//*[local-name()="TxDtls"]', $entry);
            $detailNodes = $details === false ? [] : iterator_to_array($details);
            if (count($detailNodes) > 1) {
                foreach ($detailNodes as $detailNode) {
                    $transactionAmounts = $xpath->query('.//*[local-name()="TxAmt"]/*[local-name()="Amt"]', $detailNode);
                    if ($transactionAmounts === false || $transactionAmounts->length === 0) {
                        $detailNodes = [$entry];
                        break;
                    }
                }
            }
            if ($detailNodes === []) {
                $detailNodes = [$entry];
            }

            foreach ($detailNodes as $detail) {
                if (! $detail instanceof \DOMElement) {
                    continue;
                }
                $rowNumber++;
                if ($rowNumber > self::MAX_ROWS + 1) {
                    $errors[] = ['row' => $rowNumber, 'message' => 'A CAMT.053 import can contain no more than 1,000 transactions.'];
                    break 2;
                }
                $amountNode = null;
                if ($detail !== $entry) {
                    $transactionAmounts = $xpath->query('.//*[local-name()="TxAmt"]/*[local-name()="Amt"]', $detail);
                    if ($transactionAmounts !== false && $transactionAmounts->length > 0 && $transactionAmounts->item(0) instanceof \DOMElement) {
                        $amountNode = $transactionAmounts->item(0);
                    }
                }
                if (! $amountNode instanceof \DOMElement) {
                    $amountNodeList = $xpath->query('./*[local-name()="Amt"]', $entry);
                    if ($amountNodeList !== false && $amountNodeList->item(0) instanceof \DOMElement) {
                        $amountNode = $amountNodeList->item(0);
                    }
                }
                $rawAmount = $amountNode?->textContent;
                $currency = strtoupper((string) $amountNode?->getAttribute('Ccy'));
                $money = $this->unsignedMoney(is_string($rawAmount) ? trim($rawAmount) : '');
                $transactionType = $reversal ? ($indicator === 'DBIT' ? 'income' : 'expense') : ($indicator === 'DBIT' ? 'expense' : ($indicator === 'CRDT' ? 'income' : null));
                $dateValue = $date === null ? false : \DateTimeImmutable::createFromFormat('!Y-m-d', substr($date, 0, 10));
                $dateIsValid = $dateValue instanceof \DateTimeImmutable && $dateValue->format('Y-m-d') === substr((string) $date, 0, 10);
                $remittance = $xpath->query('.//*[local-name()="RmtInf"]//*[local-name()="Ustrd"]', $detail);
                $parts = [];
                if ($remittance !== false) {
                    foreach ($remittance as $text) {
                        $value = trim($text->textContent);
                        if ($value !== '') {
                            $parts[] = $value;
                        }
                    }
                }
                if ($parts === [] && $entryInfo !== null) {
                    $parts[] = $entryInfo;
                }
                $description = trim(implode(' ', $parts));
                $partyName = null;
                $partyPath = $transactionType === 'income'
                    ? './/*[local-name()="RltdPties"]/*[local-name()="Dbtr"]//*[local-name()="Nm"]'
                    : './/*[local-name()="RltdPties"]/*[local-name()="Cdtr"]//*[local-name()="Nm"]';
                $partyName = $this->nodeText($xpath, $partyPath, $detail);
                $sourceReference = $detail === $entry
                    ? ($this->nodeText($xpath, './*[local-name()="AcctSvcrRef"]', $entry) ?? $this->nodeText($xpath, './*[local-name()="NtryRef"]', $entry))
                    : ($this->nodeText($xpath, './/*[local-name()="AcctSvcrRef"]', $detail) ?? $this->nodeText($xpath, './/*[local-name()="EndToEndId"]', $detail));

                if (! $dateIsValid) {
                    $errors[] = ['row' => $rowNumber, 'message' => 'The CAMT.053 entry has no supported booking or value date.'];

                    continue;
                }
                if ($money === null) {
                    $errors[] = ['row' => $rowNumber, 'message' => 'The CAMT.053 entry has a zero or unsupported amount.'];

                    continue;
                }
                if (preg_match('/\A[A-Z]{3}\z/', $currency) !== 1) {
                    $errors[] = ['row' => $rowNumber, 'message' => 'The CAMT.053 entry has no valid ISO currency code.'];

                    continue;
                }
                if ($transactionType === null) {
                    $errors[] = ['row' => $rowNumber, 'message' => 'The CAMT.053 entry has no supported debit or credit indicator.'];

                    continue;
                }
                if ($description === '') {
                    $description = $partyName ?: $entryInfo ?: 'Imported bank transaction';
                }
                if (mb_strlen($description) > 200 || preg_match('//u', $description) !== 1) {
                    $errors[] = ['row' => $rowNumber, 'message' => 'The CAMT.053 description exceeds 200 valid UTF-8 characters.'];

                    continue;
                }
                if ($partyName !== null && (mb_strlen($partyName) > 200 || preg_match('//u', $partyName) !== 1)) {
                    $partyName = null;
                }

                $rows[] = [
                    'row' => $rowNumber,
                    'occurred_on' => $dateValue->format('Y-m-d'),
                    'description' => $description,
                    'amount' => $money,
                    'currency' => $currency,
                    'type' => $transactionType,
                    'payee' => $partyName,
                    'category_name' => null,
                    'source_reference' => $sourceReference,
                ];
                if (count($rows) > self::MAX_ROWS) {
                    $errors[] = ['row' => $rowNumber, 'message' => 'A CAMT.053 import can contain no more than 1,000 transactions.'];
                    array_pop($rows);
                    break 2;
                }
            }
        }

        if ($rows === [] && $errors === []) {
            $errors[] = ['row' => 1, 'message' => 'The CAMT.053 statement contains no supported transaction details.'];
        }

        return ['rows' => $rows, 'errors' => $errors];
    }

    /** @return array{type: string, amount: string}|null */
    private function signedMoney(string $value): ?array
    {
        if (preg_match('/\A(-?)(\d{1,16})(?:[.,](\d{1,2}))?\z/', $value, $matches) !== 1) {
            return null;
        }
        $whole = ltrim($matches[2], '0') ?: '0';
        $fraction = str_pad($matches[3] ?? '', 2, '0');
        $amount = $whole.'.'.$fraction;
        if ($amount === '0.00') {
            return null;
        }

        return ['type' => $matches[1] === '-' ? 'income' : 'expense', 'amount' => $amount];
    }

    private function unsignedMoney(string $value): ?string
    {
        if (preg_match('/\A(\d{1,16})(?:\.(\d{1,2}))?\z/', $value, $matches) !== 1) {
            return null;
        }
        $whole = ltrim($matches[1], '0') ?: '0';
        $fraction = str_pad($matches[2] ?? '', 2, '0');
        $amount = $whole.'.'.$fraction;

        return $amount === '0.00' ? null : $amount;
    }

    private function columnIndex(mixed $value, string $field, bool $required): ?int
    {
        if ($value === null || $value === '') {
            if (! $required) {
                return null;
            }
            throw ValidationException::withMessages(["mapping.{$field}" => 'Choose a CSV column for this field.']);
        }
        if (! is_numeric($value) || (string) (int) $value !== (string) $value || (int) $value < 0 || (int) $value >= self::MAX_COLUMNS) {
            throw ValidationException::withMessages(["mapping.{$field}" => 'Choose a valid CSV column.']);
        }

        return (int) $value;
    }

    private function detectDelimiter(UploadedFile $file): string
    {
        $handle = $this->open($file);

        try {
            $bestDelimiter = self::DELIMITERS[0];
            $bestColumnCount = 0;
            foreach (self::DELIMITERS as $delimiter) {
                rewind($handle);
                $header = fgetcsv($handle, null, $delimiter, '"', '');
                $columnCount = is_array($header) ? count($header) : 0;
                if ($columnCount > $bestColumnCount) {
                    $bestDelimiter = $delimiter;
                    $bestColumnCount = $columnCount;
                }
            }

            return $bestDelimiter;
        } finally {
            fclose($handle);
        }
    }

    /** @return list<string> */
    private function readHeader($handle, string $delimiter): array
    {
        $header = fgetcsv($handle, null, $delimiter, '"', '');
        if (! is_array($header)) {
            throw ValidationException::withMessages(['file' => 'Add a CSV header with the columns to map.']);
        }
        if (isset($header[0]) && str_starts_with((string) $header[0], "\xEF\xBB\xBF")) {
            $header[0] = substr($header[0], 3);
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
            throw ValidationException::withMessages(['file' => 'The upload could not be read.']);
        }

        return $handle;
    }

    private function nodeText(\DOMXPath $xpath, string $expression, \DOMNode $context): ?string
    {
        $nodes = $xpath->query($expression, $context);
        if ($nodes === false || ! $nodes->item(0) instanceof \DOMNode) {
            return null;
        }

        $text = trim($nodes->item(0)->textContent);

        return $text === '' ? null : $text;
    }
}
