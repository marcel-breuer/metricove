<?php

namespace Tests\Unit;

use App\Services\Utilities\DecimalValue;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class UtilityDecimalValueTest extends TestCase
{
    public function test_decimal_subtraction_keeps_precision_without_float_rounding(): void
    {
        $this->assertSame('0.000001', DecimalValue::subtract('1000', '999.999999'));
        $this->assertSame('99999999999999.999999', DecimalValue::subtract('99999999999999.999999', '0'));
        $this->assertSame('0.000000', DecimalValue::subtract('42.1', '42.100000'));
        $this->assertSame(0, DecimalValue::compare('7.000000', '7'));
    }

    #[DataProvider('invalidDecimals')]
    public function test_decimal_input_rejects_out_of_range_and_non_string_numeric_forms(mixed $value): void
    {
        $this->assertNull(DecimalValue::normalize($value));
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidDecimals(): iterable
    {
        yield 'negative' => ['-0.1'];
        yield 'exponent' => ['1e3'];
        yield 'too many whole digits' => ['100000000000000'];
        yield 'too many fractional digits' => ['1.0000001'];
        yield 'float input' => [0.1];
    }
}
