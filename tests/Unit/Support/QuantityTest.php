<?php

namespace Tests\Unit\Support;

use App\Support\Quantity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Spec 01 BR-3 / AC-2.1: quantity decimals never exceed the unit precision; no negatives.
 */
class QuantityTest extends TestCase
{
    /**
     * @return array<string, array{string, int, bool}>
     */
    public static function precisionCases(): array
    {
        return [
            'pcs whole' => ['2', 0, true],
            'pcs fraction (AC-2.1)' => ['1.5', 0, false],
            'pcs trailing zeros' => ['3.000', 0, true],
            'kg 1.5 (AC-2.1)' => ['1.5', 3, true],
            'kg 1.2345 (AC-2.1)' => ['1.2345', 3, false],
            'decimal comma' => ['1,25', 2, true],
            'negative' => ['-1', 3, false],
            'not a number' => ['abc', 3, false],
        ];
    }

    #[DataProvider('precisionCases')]
    public function test_fits_precision(string $value, int $precision, bool $expected): void
    {
        $this->assertSame($expected, Quantity::fitsPrecision($value, $precision));
    }

    public function test_multiple_of_step_and_storage_form(): void
    {
        $this->assertTrue(Quantity::isMultipleOf('7.5', '2.5'));
        $this->assertFalse(Quantity::isMultipleOf('7', '2.5'));
        $this->assertSame('1.500', Quantity::toStorage('1,5'));
        $this->assertSame(-1, Quantity::compare('9.999', '10'));
    }
}
