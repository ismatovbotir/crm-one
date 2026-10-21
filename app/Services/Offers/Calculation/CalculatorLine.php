<?php

namespace App\Services\Offers\Calculation;

/**
 * Input line for OfferCalculator. `groupKey` null = fixed line.
 */
final readonly class CalculatorLine
{
    public function __construct(
        public int|string $key,
        public string $quantity,
        public int $unitPriceMinor,
        public int $discountBp = 0,
        public int $taxRateBp = 0,
        public int|string|null $groupKey = null,
        public bool $isRecommended = false,
        public ?int $listUnitPriceMinor = null,
    ) {}
}
