<?php

namespace App\Services\Vehicles;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Validation\ValidationException;

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

    public static function fuelQuantity(mixed $value): ?string
    {
        return self::normalize($value, 11, 3);
    }

    public static function fuelUnitPrice(mixed $value): ?string
    {
        return self::normalize($value, 10, 4);
    }

    /** @return array{amount: string, liters: ?string, unit_price: ?string} */
    public static function calculateFuelValues(mixed $amount, mixed $liters, mixed $unitPrice): array
    {
        $rawAmount = $amount;
        $rawLiters = $liters;
        $rawUnitPrice = $unitPrice;
        $amount = $amount === null || $amount === '' ? null : self::money($amount);
        $liters = $liters === null || $liters === '' ? null : self::fuelQuantity($liters);
        $unitPrice = $unitPrice === null || $unitPrice === '' ? null : self::fuelUnitPrice($unitPrice);

        if ($rawAmount !== null && $rawAmount !== '' && $amount === null) {
            throw ValidationException::withMessages(['amount' => 'Enter a non-negative amount with up to 16 whole and 2 fractional digits.']);
        }
        if ($rawLiters !== null && $rawLiters !== '' && $liters === null) {
            throw ValidationException::withMessages(['fuel_liters' => 'Enter a positive fuel quantity with up to 11 whole and 3 fractional digits.']);
        }
        if ($rawUnitPrice !== null && $rawUnitPrice !== '' && $unitPrice === null) {
            throw ValidationException::withMessages(['fuel_unit_price' => 'Enter a positive unit price with up to 10 whole and 4 fractional digits.']);
        }
        $provided = (int) ($amount !== null) + (int) ($liters !== null) + (int) ($unitPrice !== null);

        if ($amount !== null && $liters === null && $unitPrice === null) {
            return ['amount' => $amount, 'liters' => null, 'unit_price' => null];
        }

        if ($provided < 2) {
            throw ValidationException::withMessages([
                'amount' => 'Enter any two of total amount, fuel quantity and unit price.',
            ]);
        }
        if ($liters !== null && BigDecimal::of($liters)->isZero()) {
            throw ValidationException::withMessages(['fuel_liters' => 'Fuel quantity must be greater than zero.']);
        }
        if ($unitPrice !== null && BigDecimal::of($unitPrice)->isZero()) {
            throw ValidationException::withMessages(['fuel_unit_price' => 'Fuel unit price must be greater than zero.']);
        }

        if ($amount === null) {
            $amount = BigDecimal::of($liters)->multipliedBy($unitPrice)->toScale(2, RoundingMode::HalfUp)->__toString();
        } elseif ($liters === null) {
            $liters = BigDecimal::of($amount)->dividedBy($unitPrice, 3, RoundingMode::HalfUp)->__toString();
        } elseif ($unitPrice === null) {
            $unitPrice = BigDecimal::of($amount)->dividedBy($liters, 4, RoundingMode::HalfUp)->__toString();
        }

        if (self::money($amount) === null) {
            throw ValidationException::withMessages(['amount' => 'The calculated total exceeds the supported precision.']);
        }
        if ($liters !== null && (self::fuelQuantity($liters) === null || BigDecimal::of($liters)->isZero())) {
            throw ValidationException::withMessages(['fuel_liters' => 'The calculated fuel quantity is outside the supported precision.']);
        }
        if ($unitPrice !== null && (self::fuelUnitPrice($unitPrice) === null || BigDecimal::of($unitPrice)->isZero())) {
            throw ValidationException::withMessages(['fuel_unit_price' => 'The calculated unit price is outside the supported precision.']);
        }

        return ['amount' => $amount, 'liters' => $liters, 'unit_price' => $unitPrice];
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
