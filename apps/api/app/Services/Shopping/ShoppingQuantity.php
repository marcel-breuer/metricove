<?php

namespace App\Services\Shopping;

use Illuminate\Validation\ValidationException;

class ShoppingQuantity
{
    /** @return array{quantity: ?string, unit: ?string} */
    public static function normalize(mixed $quantity, mixed $unit): array
    {
        $quantityBlank = $quantity === null || $quantity === '';
        $unitBlank = $unit === null || $unit === '';

        if ($quantityBlank && $unitBlank) {
            return ['quantity' => null, 'unit' => null];
        }

        if ($quantityBlank || $unitBlank) {
            throw ValidationException::withMessages([
                'quantity' => 'Enter both a quantity and its unit, or leave both empty.',
            ]);
        }

        if (! is_string($quantity)
            || ! preg_match('/\A(?:0|[1-9]\d{0,10})(?:\.\d{1,3})?\z/', $quantity)) {
            throw ValidationException::withMessages([
                'quantity' => 'Enter a positive quantity with up to 11 whole and 3 fractional digits.',
            ]);
        }

        $parts = explode('.', $quantity, 2);
        $fraction = str_pad($parts[1] ?? '', 3, '0');
        if ($parts[0] === '0' && $fraction === '000') {
            throw ValidationException::withMessages(['quantity' => 'Quantity must be greater than zero.']);
        }

        if (! is_string($unit) || trim($unit) === '' || mb_strlen(trim($unit)) > 32) {
            throw ValidationException::withMessages(['unit' => 'Enter a unit of up to 32 characters.']);
        }

        return [
            'quantity' => $parts[0].'.'.$fraction,
            'unit' => trim($unit),
        ];
    }
}
