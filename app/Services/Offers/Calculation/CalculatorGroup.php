<?php

namespace App\Services\Offers\Calculation;

use App\Enums\OptionGroupKind;

/**
 * Input option group for OfferCalculator (alternative or optional, with min/max to select).
 */
final readonly class CalculatorGroup
{
    public function __construct(
        public int|string $key,
        public OptionGroupKind $kind,
        public int $minSelect,
        public int $maxSelect,
    ) {}
}
