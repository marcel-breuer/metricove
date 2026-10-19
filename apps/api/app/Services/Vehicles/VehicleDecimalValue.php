<?php

namespace App\Services\Vehicles;

final class VehicleDecimalValue
{
    public static function distance(mixed $value): ?string
    {
        return self::normalize($value, 17, 3);
    }

    public static function money(mixed $value): ?string
    {
        return self::normalize($value, 16, 2);
    }

    public static function compareDistance(string $left, string $right): int
    {
        return self::compare($left, $right, 3);
    }

    private static function normalize(mixed $value, int $wholeDigits, int $fractionDigits): ?string
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }

        $pattern = '/\A(?:0|[1-9]\d{0,'.($wholeDigits - 1).'})(?:\.\d{1,'.$fractionDigits.'})?\z/';
        $value = trim((string) $value);

        return preg_match($pattern, $value) === 1 ? $value : null;
    }

    private static function compare(string $left, string $right, int $scale): int
    {
        [$leftInteger, $leftFraction] = array_pad(explode('.', $left, 2), 2, '');
        [$rightInteger, $rightFraction] = array_pad(explode('.', $right, 2), 2, '');
        $leftInteger = ltrim($leftInteger, '0') ?: '0';
        $rightInteger = ltrim($rightInteger, '0') ?: '0';
        $integer = strlen($leftInteger) <=> strlen($rightInteger);

        if ($integer === 0) {
            $integer = strcmp($leftInteger, $rightInteger) <=> 0;
        }

        if ($integer !== 0) {
            return $integer;
        }

        return strcmp(str_pad($leftFraction, $scale, '0'), str_pad($rightFraction, $scale, '0')) <=> 0;
    }
}
