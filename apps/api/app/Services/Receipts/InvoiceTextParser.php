<?php

namespace App\Services\Receipts;

class InvoiceTextParser
{
    /** @return array{merchant: ?string, invoice_number: ?string, invoice_date: ?string, amount: ?string, currency: ?string, tax_amount: ?string, fuel_liters: ?string, fuel_unit_price: ?string, fuel_odometer_value: ?string} */
    public function parse(string $text): array
    {
        $text = mb_substr(str_replace(["\r\n", "\r"], "\n", $text), 0, 100_000);
        $lines = array_values(array_filter(array_map('trim', explode("\n", $text)), static fn (string $line): bool => $line !== ''));

        return [
            'merchant' => $this->merchant($lines),
            'invoice_number' => $this->invoiceNumber($lines),
            'invoice_date' => $this->invoiceDate($lines),
            'amount' => $this->amount($lines, '/(?:gesamtbetrag|rechnungsbetrag|zahlbetrag|zu zahlen|endbetrag|total(?:\s+due)?|amount\s+due|grand\s+total|balance\s+due)/iu'),
            'currency' => $this->currency($text),
            'tax_amount' => $this->amount($lines, '/(?:mwst|ust|vat|tax(?:\s+amount)?)/iu'),
            'fuel_liters' => $this->fuelLiters($lines),
            'fuel_unit_price' => $this->fuelUnitPrice($lines),
            'fuel_odometer_value' => $this->fuelOdometerValue($lines),
        ];
    }

    /** @return array{suggestions: array<string, ?string>, field_status: array<string, string>, review_required: list<string>} */
    public function parseWithReview(string $text, ?float $sourceConfidence): array
    {
        $suggestions = $this->parse($text);
        $fieldStatus = [];
        $reviewRequired = [];

        foreach ($suggestions as $field => $value) {
            $status = $value === null
                ? 'missing'
                : ($this->hasStrongEvidence($field, $text) && ($sourceConfidence === null || $sourceConfidence >= 70) ? 'recognized' : 'uncertain');
            $fieldStatus[$field] = $status;
            if ($status !== 'recognized') {
                $reviewRequired[] = $field;
            }
        }

        return [
            'suggestions' => $suggestions,
            'field_status' => $fieldStatus,
            'review_required' => $reviewRequired,
        ];
    }

    /** @param list<string> $lines */
    private function merchant(array $lines): ?string
    {
        foreach ($lines as $line) {
            if (mb_strlen($line) > 200 || preg_match('/^(?:rechnung|invoice|gutschrift|credit\s+note)\b/iu', $line) === 1) {
                continue;
            }

            return $line;
        }

        return null;
    }

    /** @param list<string> $lines */
    private function invoiceNumber(array $lines): ?string
    {
        foreach ($lines as $line) {
            if (preg_match('/(?:rechnungs(?:nummer|nr\.?)|invoice\s*(?:number|no\.?)|bill\s*no\.?)\s*[:#-]?\s*([A-Z0-9][A-Z0-9._\/-]{1,49})/iu', $line, $matches) === 1) {
                return mb_substr(trim($matches[1], " \t:;,.#"), 0, 100);
            }
        }

        return null;
    }

    /** @param list<string> $lines */
    private function invoiceDate(array $lines): ?string
    {
        $candidates = [];
        foreach ($lines as $line) {
            if (preg_match_all('/(?<!\d)(\d{4})-(\d{1,2})-(\d{1,2})(?!\d)|(?<!\d)(\d{1,2})[.\/-](\d{1,2})[.\/-](\d{2,4})(?!\d)/u', $line, $matches, PREG_SET_ORDER) === 0) {
                continue;
            }

            foreach ($matches as $match) {
                if (($match[1] ?? '') !== '') {
                    $candidate = sprintf('%04d-%02d-%02d', (int) $match[1], (int) $match[2], (int) $match[3]);
                } else {
                    $year = (int) $match[6];
                    $year += $year < 100 ? 2000 : 0;
                    $candidate = sprintf('%04d-%02d-%02d', $year, (int) $match[5], (int) $match[4]);
                }

                if ($this->isValidDate($candidate)) {
                    $candidates[] = [
                        'date' => $candidate,
                        'priority' => preg_match('/(?:rechnungsdatum|invoice\s+date|datum|date)/iu', $line) === 1 ? 0 : 1,
                    ];
                }
            }
        }

        usort($candidates, static fn (array $left, array $right): int => $left['priority'] <=> $right['priority']);

        return $candidates[0]['date'] ?? null;
    }

    /** @param list<string> $lines */
    private function amount(array $lines, string $labelPattern): ?string
    {
        foreach ($lines as $line) {
            if (preg_match($labelPattern, $line) !== 1) {
                continue;
            }

            if (preg_match_all('/(?<![\pL\pN])(?:\d{1,3}(?:[., \x{00A0}\x{202F}]\d{3})+|\d+)(?:[.,]\d{1,2})?(?![\pL\pN])/u', $line, $matches) > 0) {
                $amounts = array_map(fn (string $candidate): ?string => $this->normalizeAmount($candidate), $matches[0]);
                $amounts = array_values(array_filter($amounts, static fn (?string $amount): bool => $amount !== null));

                if ($amounts !== []) {
                    return $amounts[array_key_last($amounts)];
                }
            }
        }

        return null;
    }

    private function currency(string $text): ?string
    {
        if (preg_match('/\b(EUR|USD|GBP|CHF)\b|€|\$|£/iu', $text, $matches) !== 1) {
            return null;
        }

        return match (strtoupper($matches[0])) {
            '€', 'EUR' => 'EUR',
            '$', 'USD' => 'USD',
            '£', 'GBP' => 'GBP',
            'CHF' => 'CHF',
            default => null,
        };
    }

    /** @param list<string> $lines */
    private function fuelLiters(array $lines): ?string
    {
        foreach ($lines as $line) {
            if (preg_match('/(?<!\d)(\d{1,8}(?:[.,]\d{1,3})?)\s*(?:l|ltr|liter|liters)\b/iu', $line, $matches) === 1) {
                return $this->normalizeDecimal($matches[1], 3, 11);
            }
        }

        return null;
    }

    /** @param list<string> $lines */
    private function fuelUnitPrice(array $lines): ?string
    {
        foreach ($lines as $line) {
            if (preg_match('/(?<!\d)(\d{1,8}(?:[.,]\d{1,4})?)\s*(?:€|eur)?\s*\/\s*(?:l|liter)\b/iu', $line, $matches) === 1) {
                return $this->normalizeDecimal($matches[1], 4, 10);
            }
            if (preg_match('/(?:preis\s*(?:pro|\/)\s*(?:liter|l)|price\s*(?:per|\/)\s*(?:liter|l))\D{0,12}(\d{1,8}(?:[.,]\d{1,4})?)/iu', $line, $matches) === 1) {
                return $this->normalizeDecimal($matches[1], 4, 10);
            }
        }

        return null;
    }

    /** @param list<string> $lines */
    private function fuelOdometerValue(array $lines): ?string
    {
        foreach ($lines as $line) {
            if (preg_match('/(?:kilometerstand|km\s*-?\s*stand|odometer)\D{0,12}(\d{1,17}(?:[.,]\d{1,3})?)/iu', $line, $matches) === 1) {
                return $this->normalizeDecimal($matches[1], 3, 17);
            }
        }

        return null;
    }

    private function hasStrongEvidence(string $field, string $text): bool
    {
        return match ($field) {
            'merchant' => false,
            'invoice_number' => preg_match('/(?:rechnungs(?:nummer|nr\.?)|invoice\s*(?:number|no\.?)|bill\s*no\.?)\s*[:#-]?\s*[A-Z0-9]/iu', $text) === 1,
            'invoice_date' => preg_match('/(?:rechnungsdatum|invoice\s+date|datum|date)\D{0,16}\d{1,4}[.\/-]\d{1,4}[.\/-]\d{2,4}|(?:rechnungsdatum|invoice\s+date|datum|date)\D{0,16}\d{4}-\d{1,2}-\d{1,2}/iu', $text) === 1,
            'amount' => preg_match('/(?:gesamtbetrag|rechnungsbetrag|zahlbetrag|zu zahlen|endbetrag|total(?:\s+due)?|amount\s+due|grand\s+total|balance\s+due)/iu', $text) === 1,
            'currency' => preg_match('/\b(EUR|USD|GBP|CHF)\b|€|\$|£/iu', $text) === 1,
            'tax_amount' => preg_match('/(?:mwst|ust|vat|tax(?:\s+amount)?)/iu', $text) === 1,
            'fuel_liters' => preg_match('/\d{1,8}(?:[.,]\d{1,3})?\s*(?:l|ltr|liter|liters)\b/iu', $text) === 1,
            'fuel_unit_price' => preg_match('/\d{1,8}(?:[.,]\d{1,4})?\s*(?:€|eur)?\s*\/\s*(?:l|liter)\b|(?:preis\s*(?:pro|\/)\s*(?:liter|l)|price\s*(?:per|\/)\s*(?:liter|l))/iu', $text) === 1,
            'fuel_odometer_value' => preg_match('/(?:kilometerstand|km\s*-?\s*stand|odometer)\D{0,12}\d{1,17}/iu', $text) === 1,
            default => false,
        };
    }

    private function normalizeDecimal(string $value, int $fractionDigits, int $wholeDigits): ?string
    {
        $value = preg_replace('/[\s\x{00A0}\x{202F}]/u', '', $value) ?? '';
        $lastComma = strrpos($value, ',');
        $lastDot = strrpos($value, '.');
        $decimalSeparator = $lastComma === false ? ($lastDot === false ? null : '.') : ($lastDot === false || $lastComma > $lastDot ? ',' : '.');

        if ($lastComma !== false && $lastDot !== false) {
            $thousandsSeparator = $decimalSeparator === ',' ? '.' : ',';
            $value = str_replace($thousandsSeparator, '', $value);
        } elseif ($decimalSeparator !== null) {
            $fractionLength = strlen($value) - (int) strrpos($value, $decimalSeparator) - 1;
            if ($fractionLength > $fractionDigits || $fractionLength === 3 && $fractionDigits < 3) {
                $value = str_replace($decimalSeparator, '', $value);
                $decimalSeparator = null;
            }
        }

        if ($decimalSeparator === ',') {
            $value = str_replace(',', '.', $value);
        }
        if (preg_match('/\A\d{1,'.$wholeDigits.'}(?:\.\d{1,'.$fractionDigits.'})?\z/', $value) !== 1) {
            return null;
        }

        return $value;
    }

    private function normalizeAmount(string $value): ?string
    {
        $value = preg_replace('/[\s\x{00A0}\x{202F}]/u', '', $value) ?? '';
        $hasComma = str_contains($value, ',');
        $hasDot = str_contains($value, '.');

        if ($hasComma && $hasDot) {
            if (strrpos($value, ',') > strrpos($value, '.')) {
                $value = str_replace('.', '', $value);
                $value = str_replace(',', '.', $value);
            } else {
                $value = str_replace(',', '', $value);
            }
        } elseif ($hasComma) {
            $value = preg_match('/,\d{1,2}\z/', $value) === 1
                ? str_replace(',', '.', $value)
                : str_replace(',', '', $value);
        } elseif ($hasDot && preg_match('/\.\d{1,2}\z/', $value) !== 1) {
            $value = str_replace('.', '', $value);
        }

        if (preg_match('/\A\d{1,16}(?:\.\d{1,2})?\z/', $value) !== 1) {
            return null;
        }

        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $whole = ltrim($whole, '0') ?: '0';
        if (strlen($whole) > 16) {
            return null;
        }

        return $whole.($fraction === '' ? '' : '.'.$fraction);
    }

    private function isValidDate(string $date): bool
    {
        [$year, $month, $day] = array_map('intval', explode('-', $date));

        return checkdate($month, $day, $year);
    }
}
