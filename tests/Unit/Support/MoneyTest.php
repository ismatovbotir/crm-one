<?php

namespace Tests\Unit\Support;

use App\Enums\Locale;
use App\Support\CurrencyMismatch;
use App\Support\Money;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Spec 01 BR-1, AC-2.3, AC-4.1, AC-4.2.
 */
class MoneyTest extends TestCase
{
    public function test_parses_major_units_into_minor_units(): void
    {
        $this->assertSame(125000050, Money::fromMajor('1250000.50', 'UZS')->amount);
        $this->assertSame(125000050, Money::fromMajor('1 250 000,50', 'uzs')->amount);
        $this->assertSame(100, Money::fromMajor('1', 'USD')->amount);
        $this->assertSame(10000, Money::fromMajor('100.000', 'USD')->amount, 'Trailing zeros are not extra precision.');
        $this->assertSame('UZS', Money::fromMajor('1', 'uzs')->currency);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidAmounts(): array
    {
        return [
            'too many decimals (AC-2.3)' => ['1250000.505'],
            'letters' => ['12a'],
            'empty' => [''],
            'float notation' => ['1e5'],
        ];
    }

    #[DataProvider('invalidAmounts')]
    public function test_rejects_invalid_amounts(string $value): void
    {
        $this->assertFalse(Money::isValidMajor($value, 'UZS'));

        $this->expectException(InvalidArgumentException::class);
        Money::fromMajor($value, 'UZS');
    }

    /** AC-4.1 */
    public function test_multiplication_rounds_half_up_to_the_minor_unit(): void
    {
        $this->assertSame(501, Money::of(1001, 'UZS')->multiplyByQty('0.5')->amount);
        $this->assertSame(33, Money::of(100, 'UZS')->multiplyByQty('0.333')->amount);
        $this->assertSame(-501, Money::of(-1001, 'UZS')->multiplyByQty('0.5')->amount);
        $this->assertSame(1_000_000_000_000_000, Money::of(1_000_000_000_000, 'UZS')->multiplyByQty('1000')->amount);
    }

    /** AC-4.2 */
    public function test_combining_currencies_throws(): void
    {
        $this->expectException(CurrencyMismatch::class);

        Money::of(100, 'UZS')->add(Money::of(100, 'USD'));
    }

    public function test_add_subtract_and_format(): void
    {
        $sum = Money::of(150, 'UZS')->add(Money::of(50, 'UZS'))->subtract(Money::of(25, 'UZS'));

        $this->assertTrue($sum->equals(Money::of(175, 'UZS')));
        $this->assertSame('1.75', $sum->toMajor());
        $this->assertSame('1,250,000.50 UZS', str_replace("\u{00A0}", ' ', Money::of(125000050, 'UZS')->format(Locale::En)));
    }
}
