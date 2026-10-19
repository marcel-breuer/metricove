<?php

namespace Tests\Unit;

use App\Services\Vehicles\VehicleDecimalValue;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class VehicleDecimalValueTest extends TestCase
{
    public function test_distance_accepts_precision_and_compares_without_float_conversion(): void
    {
        $this->assertSame('99999999999999999.999', VehicleDecimalValue::distance('99999999999999999.999'));
        $this->assertSame(0, VehicleDecimalValue::compareDistance('120000.1', '120000.100'));
        $this->assertSame(-1, VehicleDecimalValue::compareDistance('0.001', '0.002'));
    }

    public function test_money_uses_two_fractional_digits_and_keeps_values_as_strings(): void
    {
        $this->assertSame('9999999999999999.99', VehicleDecimalValue::money('9999999999999999.99'));
        $this->assertSame('123.45', VehicleDecimalValue::money('123.45'));
    }

    #[DataProvider('invalidDistances')]
    public function test_distance_rejects_invalid_and_float_input(mixed $value): void
    {
        $this->assertNull(VehicleDecimalValue::distance($value));
    }

    public static function invalidDistances(): iterable
    {
        yield 'negative' => ['-1'];
        yield 'exponent' => ['1e3'];
        yield 'too many whole digits' => ['100000000000000000'];
        yield 'too many fractional digits' => ['1.0001'];
        yield 'float payload' => [0.1];
    }

    public function test_money_rejects_rounding_and_out_of_range_values(): void
    {
        $this->assertNull(VehicleDecimalValue::money('12.345'));
        $this->assertNull(VehicleDecimalValue::money('10000000000000000'));
        $this->assertNull(VehicleDecimalValue::money('-0.01'));
    }
}
