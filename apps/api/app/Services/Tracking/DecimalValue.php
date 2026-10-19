<?php

namespace App\Services\Tracking;

final class DecimalValue
{
    private const PATTERN = '/\A-?(?:0|[1-9]\d{0,13})(?:\.\d{1,6})?\z/';

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

        if ($leftParts['negative'] !== $rightParts['negative']) {
            return $leftParts['negative'] ? -1 : 1;
        }

        $comparison = strlen($leftParts['integer']) <=> strlen($rightParts['integer']);

        if ($comparison === 0) {
            $comparison = strcmp($leftParts['integer'], $rightParts['integer']) <=> 0;
        }

        if ($comparison === 0) {
            $comparison = strcmp($leftParts['fraction'], $rightParts['fraction']) <=> 0;
        }

        return $leftParts['negative'] ? -$comparison : $comparison;
    }

    /** @return array{negative: bool, integer: string, fraction: string} */
    private static function parts(string $value): array
    {
        $negative = str_starts_with($value, '-');
        $unsigned = $negative ? substr($value, 1) : $value;
        [$integer, $fraction] = array_pad(explode('.', $unsigned, 2), 2, '');
        $integer = ltrim($integer, '0');

        return [
            'negative' => $negative && ($integer !== '' || trim($fraction, '0') !== ''),
            'integer' => $integer === '' ? '0' : $integer,
            'fraction' => str_pad($fraction, 6, '0'),
        ];
    }
}
