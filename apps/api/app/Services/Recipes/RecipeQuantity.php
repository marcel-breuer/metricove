<?php

namespace App\Services\Recipes;

final class RecipeQuantity
{
    public static function normalize(mixed $value): ?string
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }

        $value = trim((string) $value);

        if (preg_match('/\A\d{1,10}(?:\.\d{1,4})?\z/', $value) !== 1) {
            return null;
        }

        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $whole = ltrim($whole, '0');
        $whole = $whole === '' ? '0' : $whole;
        $fraction = str_pad($fraction, 4, '0');

        if ($whole === '0' && trim($fraction, '0') === '') {
            return null;
        }

        return $whole.'.'.$fraction;
    }
}
