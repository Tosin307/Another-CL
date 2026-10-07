<?php

namespace Tests\Unit;

use App\Support\Money;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public function test_money_is_converted_to_and_from_integer_kobo_exactly(): void
    {
        $this->assertSame(1000000001, Money::toMinor('10000000.01'));
        $this->assertSame('10000000.01', Money::toDecimal(1000000001));
        $this->assertSame('-5000000.01', Money::toDecimal(-500000001));
        $this->assertSame('15000000.02', Money::add('10000000.01', '5000000.01'));
        $this->assertSame('5000000.00', Money::subtract('10000000.01', '5000000.01'));
    }

    public function test_percentage_calculation_uses_integer_basis_points(): void
    {
        $this->assertSame(85000000, Money::percentageMinor(Money::toMinor('10000000.00'), 850));
        $this->assertSame(1, Money::percentageMinor(6, 850));
    }

    public function test_money_rejects_excess_decimal_places(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::toMinor('100.001');
    }

    public function test_money_rejects_non_finite_numbers(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::toMinor(INF);
    }

    public function test_money_rejects_values_outside_the_database_precision(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::toMinor('10000000000000.00');
    }

    public function test_money_rejects_arithmetic_that_overflows_the_database_precision(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::add('9999999999999.99', '0.01');
    }
}
