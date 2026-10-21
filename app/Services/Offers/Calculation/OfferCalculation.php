<?php

namespace App\Services\Offers\Calculation;

/**
 * Result of OfferCalculator (Spec 05 section 8). Alternatives are never added together.
 */
final readonly class OfferCalculation
{
    /**
     * @param  array<int|string, LineAmounts>  $lines  line key => amounts
     * @param  array<int|string, array{selected: int, satisfied: bool}>  $groups  group key => selection state
     */
    public function __construct(
        public array $lines,
        public array $groups,
        public int $fixedNet,
        public int $fixedVat,
        public int $fixedGross,
        public int $selectedNet,
        public int $selectedVat,
        public int $selectedGross,
        public int $rangeMinGross,
        public int $rangeMaxGross,
        public ?int $recommendedGross,
        public int $maxDiscountBp,
        public bool $selectionComplete,
    ) {}

    /** Selected if complete, else recommended, else the range minimum (Spec 05 Q-18). */
    public function pipelineValue(): int
    {
        return $this->selectionComplete ? $this->selectedGross : ($this->recommendedGross ?? $this->rangeMinGross);
    }

    public function hasRange(): bool
    {
        return $this->rangeMinGross !== $this->rangeMaxGross;
    }
}
