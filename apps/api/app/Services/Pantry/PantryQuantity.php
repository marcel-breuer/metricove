<?php

namespace App\Services\Pantry;

final class PantryQuantity
{
    private const SCALE = 1000;

    private const MAX_SCALED = 99_999_999_999_999;

    public static function quantity(mixed $value): ?string
    {
        $scaled = self::parse($value, false);

        return $scaled === null ? null : self::format($scaled);
    }

    public static function delta(mixed $value): ?string
    {
        $scaled = self::parse($value, true);

        return $scaled === null || $scaled === 0 ? null : self::format($scaled);
    }

    public static function add(string $quantity, string $delta): ?string
    {
        $current = self::parse($quantity, false);
        $change = self::parse($delta, true);

        if ($current === null || $change === null) {
            return null;
        }

        $result = $current + $change;

        if ($result < 0 || $result > self::MAX_SCALED) {
            return null;
        }

        return self::format($result);
    }

    private static function parse(mixed $value, bool $allowNegative): ?int
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }

        $value = trim((string) $value);
        $pattern = $allowNegative
            ? '/\A-?(?:0|[1-9]\d{0,10})(?:\.\d{1,3})?\z/'
            : '/\A(?:0|[1-9]\d{0,10})(?:\.\d{1,3})?\z/';

        if (preg_match($pattern, $value) !== 1) {
            return null;
        }

        $negative = str_starts_with($value, '-');
        if ($negative) {
            $value = substr($value, 1);
        }

        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $scaled = ((int) $whole * self::SCALE) + (int) str_pad($fraction, 3, '0');

        return $negative ? -$scaled : $scaled;
    }

    private static function format(int $scaled): string
    {
        $sign = $scaled < 0 ? '-' : '';
        $absolute = abs($scaled);

        return $sign.intdiv($absolute, self::SCALE).'.'.str_pad((string) ($absolute % self::SCALE), 3, '0', STR_PAD_LEFT);
    }
}
