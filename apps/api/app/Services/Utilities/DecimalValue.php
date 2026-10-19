<?php

namespace App\Services\Utilities;

final class DecimalValue
{
    private const PATTERN = '/\A(?:0|[1-9]\d{0,13})(?:\.\d{1,6})?\z/';

    public static function normalize(mixed $value): ?string
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }

        $value = trim((string) $value);

        return preg_match(self::PATTERN, $value) === 1 ? $value : null;
    }

    public static function compare(string $left, string $right): int
    {
        $leftParts = self::parts($left);
        $rightParts = self::parts($right);
        $comparison = strlen($leftParts['integer']) <=> strlen($rightParts['integer']);

        if ($comparison === 0) {
            $comparison = strcmp($leftParts['integer'], $rightParts['integer']) <=> 0;
        }

        return $comparison !== 0 ? $comparison : strcmp($leftParts['fraction'], $rightParts['fraction']) <=> 0;
    }

    public static function subtract(string $larger, string $smaller): string
    {
        $left = self::scaledDigits($larger);
        $right = self::scaledDigits($smaller);
        $result = '';
        $borrow = 0;

        for ($index = strlen($left) - 1; $index >= 0; $index--) {
            $digit = (int) $left[$index] - (int) $right[$index] - $borrow;
            $borrow = $digit < 0 ? 1 : 0;
            $result = (string) ($digit < 0 ? $digit + 10 : $digit).$result;
        }

        $integer = ltrim(substr($result, 0, -6), '0');
        $fraction = substr($result, -6);

        return ($integer === '' ? '0' : $integer).'.'.$fraction;
    }

    /** @return array{integer: string, fraction: string} */
    private static function parts(string $value): array
    {
        [$integer, $fraction] = array_pad(explode('.', $value, 2), 2, '');

        return [
            'integer' => ltrim($integer, '0') ?: '0',
            'fraction' => str_pad($fraction, 6, '0'),
        ];
    }

    private static function scaledDigits(string $value): string
    {
        $parts = self::parts($value);

        return str_pad($parts['integer'], 14, '0', STR_PAD_LEFT).$parts['fraction'];
    }
}
