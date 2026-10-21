<?php

namespace App\Services\Offers\Calculation;

/**
 * Amounts of one line in minor units (Spec 05 BR-1), rounded half-up per line.
 */
final readonly class LineAmounts
{
    public function __construct(
        public int $base,
        public int $discount,
        public int $net,
        public int $vat,
        public int $gross,
        public int $effectiveDiscountBp,
    ) {}
}
