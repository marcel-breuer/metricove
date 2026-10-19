<?php

namespace App\Services\Finance;

final class FinanceDecimalValue
{
    public static function money(mixed $value): ?string
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }

        $value = trim((string) $value);

        if (preg_match('/\A(?:0|[1-9]\d{0,15})(?:\.\d{1,2})?\z/', $value) !== 1) {
            return null;
        }

        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $normalized = $whole.'.'.str_pad($fraction, 2, '0');

        return $normalized === '0.00' ? null : $normalized;
    }

    /** @param iterable<string|int|float|null> $values */
    public static function sum(iterable $values): string
    {
        $cents = '0';

        foreach ($values as $value) {
            if ($value === null) {
                continue;
            }

            $money = self::money(is_float($value) ? number_format($value, 2, '.', '') : (string) $value);

            if ($money === null) {
                continue;
            }

            [$whole, $fraction] = explode('.', $money, 2);
            $cents = self::addIntegers($cents, ltrim($whole.$fraction, '0') ?: '0');
        }

        $digits = str_pad($cents, 3, '0', STR_PAD_LEFT);
        $whole = ltrim(substr($digits, 0, -2), '0') ?: '0';

        return $whole.'.'.substr($digits, -2);
    }

    public static function subtract(string $left, string $right): string
    {
        [$leftWhole, $leftFraction] = explode('.', self::money($left) ?? '0.00', 2);
        [$rightWhole, $rightFraction] = explode('.', self::money($right) ?? '0.00', 2);
        $leftCents = ltrim($leftWhole.$leftFraction, '0') ?: '0';
        $rightCents = ltrim($rightWhole.$rightFraction, '0') ?: '0';
        $comparison = self::compareIntegers($leftCents, $rightCents);

        if ($comparison === 0) {
            return '0.00';
        }

        $negative = $comparison < 0;
        $difference = self::subtractIntegers($negative ? $rightCents : $leftCents, $negative ? $leftCents : $rightCents);
        $digits = str_pad($difference, 3, '0', STR_PAD_LEFT);
        $whole = ltrim(substr($digits, 0, -2), '0') ?: '0';

        return ($negative ? '-' : '').$whole.'.'.substr($digits, -2);
    }

    private static function addIntegers(string $left, string $right): string
    {
        $left = strrev($left);
        $right = strrev($right);
        $length = max(strlen($left), strlen($right));
        $carry = 0;
        $sum = '';

        for ($index = 0; $index < $length; $index++) {
            $digit = (int) ($left[$index] ?? '0') + (int) ($right[$index] ?? '0') + $carry;
            $sum .= (string) ($digit % 10);
            $carry = intdiv($digit, 10);
        }

        if ($carry > 0) {
            $sum .= (string) $carry;
        }

        return strrev($sum);
    }

    private static function compareIntegers(string $left, string $right): int
    {
        $left = ltrim($left, '0') ?: '0';
        $right = ltrim($right, '0') ?: '0';
        $length = strlen($left) <=> strlen($right);

        return $length !== 0 ? $length : (strcmp($left, $right) <=> 0);
    }

    private static function subtractIntegers(string $larger, string $smaller): string
    {
        $larger = strrev($larger);
        $smaller = strrev($smaller);
        $borrow = 0;
        $difference = '';

        for ($index = 0; $index < strlen($larger); $index++) {
            $digit = (int) $larger[$index] - (int) ($smaller[$index] ?? '0') - $borrow;

            if ($digit < 0) {
                $digit += 10;
                $borrow = 1;
            } else {
                $borrow = 0;
            }

            $difference .= (string) $digit;
        }

        return ltrim(strrev($difference), '0') ?: '0';
    }
}
