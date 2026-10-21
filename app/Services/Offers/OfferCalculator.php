<?php

namespace App\Services\Offers;

use App\Documents\DocumentFormatter;
use App\Enums\OptionGroupKind;
use App\Services\Offers\Calculation\CalculatorGroup;
use App\Services\Offers\Calculation\CalculatorLine;
use App\Services\Offers\Calculation\LineAmounts;
use App\Services\Offers\Calculation\OfferCalculation;
use InvalidArgumentException;

/**
 * Pure, server-side offer math (Spec 05 section 8). BCMath on decimal strings, rounding half-up
 * per line, then sums. No database access; callers persist the result.
 *
 *  - BR-1 base = round(qty x unit price); discount = round(base x bp / 10000); amount = base - discount.
 *    Net basis: vat = round(net x rate / 10000). Gross basis: vat = round(gross x rate / (10000 + rate)).
 *  - BR-3/4 fixed totals; selected totals = fixed + selected lines (never all lines).
 *  - BR-5 gross range given current choices; BR-6 recommended total; DEC-6 worst-case discount.
 */
class OfferCalculator
{
    /**
     * @param  list<CalculatorLine>  $lines
     * @param  list<CalculatorGroup>  $groups
     * @param  list<int|string>  $selectedKeys  keys of selected group lines
     */
    public function calculate(array $lines, array $groups, bool $pricesIncludeVat, array $selectedKeys = []): OfferCalculation
    {
        $groupsByKey = [];

        foreach ($groups as $group) {
            $groupsByKey[$group->key] = $group;
        }

        $amounts = [];
        $maxDiscount = 0;
        $fixed = ['net' => 0, 'vat' => 0, 'gross' => 0];
        $selected = $fixed;
        $byGroup = [];

        foreach ($lines as $line) {
            $lineAmounts = $this->line($line, $pricesIncludeVat);
            $amounts[$line->key] = $lineAmounts;
            $maxDiscount = max($maxDiscount, $lineAmounts->effectiveDiscountBp);

            if ($line->groupKey === null) {
                $fixed = $this->add($fixed, $lineAmounts);
                $selected = $this->add($selected, $lineAmounts);

                continue;
            }

            if (! isset($groupsByKey[$line->groupKey])) {
                throw new InvalidArgumentException("Line [{$line->key}] references unknown group [{$line->groupKey}].");
            }

            $isSelected = in_array($line->key, $selectedKeys, true);
            $byGroup[$line->groupKey][] = ['line' => $line, 'gross' => $lineAmounts->gross, 'selected' => $isSelected];

            if ($isSelected) {
                $selected = $this->add($selected, $lineAmounts);
            }
        }

        $rangeMin = $fixed['gross'];
        $rangeMax = $fixed['gross'];
        $recommended = $fixed['gross'];
        $recommendedAvailable = true;
        $complete = true;
        $groupStates = [];

        foreach ($groupsByKey as $key => $group) {
            $members = $byGroup[$key] ?? [];
            $chosen = array_values(array_filter($members, fn (array $member): bool => $member['selected']));
            $rest = array_values(array_filter($members, fn (array $member): bool => ! $member['selected']));
            $count = count($chosen);
            $chosenGross = array_sum(array_column($chosen, 'gross'));
            $restGross = array_column($rest, 'gross');
            sort($restGross);

            $rangeMin += $chosenGross + ($count < $group->minSelect ? array_sum(array_slice($restGross, 0, $group->minSelect - $count)) : 0);
            $rangeMax += $chosenGross + ($count < $group->maxSelect ? array_sum(array_slice(array_reverse($restGross), 0, $group->maxSelect - $count)) : 0);

            $satisfied = $count >= $group->minSelect && $count <= $group->maxSelect;
            $complete = $complete && $satisfied;
            $groupStates[$key] = ['selected' => $count, 'satisfied' => $satisfied];

            if ($group->kind === OptionGroupKind::Alternative) {
                $pick = array_values(array_filter($members, fn (array $member): bool => $member['line']->isRecommended));

                if ($pick !== []) {
                    $recommended += $pick[0]['gross'];
                } elseif ($group->minSelect >= 1) {
                    $recommendedAvailable = false;
                }
            }
        }

        return new OfferCalculation(
            lines: $amounts,
            groups: $groupStates,
            fixedNet: $fixed['net'],
            fixedVat: $fixed['vat'],
            fixedGross: $fixed['gross'],
            selectedNet: $selected['net'],
            selectedVat: $selected['vat'],
            selectedGross: $selected['gross'],
            rangeMinGross: $rangeMin,
            rangeMaxGross: $rangeMax,
            recommendedGross: $recommendedAvailable ? $recommended : null,
            maxDiscountBp: $maxDiscount,
            selectionComplete: $complete,
        );
    }

    /**
     * BR-1 amounts of a single line.
     */
    public function line(CalculatorLine $line, bool $pricesIncludeVat): LineAmounts
    {
        if (preg_match('/^\d+(\.\d+)?$/', $line->quantity) !== 1) {
            throw new InvalidArgumentException("Invalid quantity [{$line->quantity}].");
        }

        if ($line->discountBp < 0 || $line->discountBp > 10000 || $line->unitPriceMinor < 0 || $line->taxRateBp < 0) {
            throw new InvalidArgumentException("Line [{$line->key}] has out-of-range values.");
        }

        $base = $this->roundToInt(bcmul($line->quantity, (string) $line->unitPriceMinor, 6));
        $discount = $this->roundToInt(bcdiv(bcmul((string) $base, (string) $line->discountBp, 0), '10000', 6));
        $amount = $base - $discount;

        if ($pricesIncludeVat) {
            $gross = $amount;
            $vat = $this->roundToInt(bcdiv(bcmul((string) $gross, (string) $line->taxRateBp, 0), (string) (10000 + $line->taxRateBp), 6));
            $net = $gross - $vat;
        } else {
            $net = $amount;
            $vat = $this->roundToInt(bcdiv(bcmul((string) $net, (string) $line->taxRateBp, 0), '10000', 6));
            $gross = $net + $vat;
        }

        return new LineAmounts($base, $discount, $net, $vat, $gross, $this->effectiveDiscount($line, $amount));
    }

    /**
     * Effective discount vs the list price (Spec 05 4.3): the line discount plus any manual unit
     * price below the resolved list price, in basis points of the list amount.
     */
    protected function effectiveDiscount(CalculatorLine $line, int $amount): int
    {
        if ($line->listUnitPriceMinor === null || $line->listUnitPriceMinor <= 0) {
            return $line->discountBp;
        }

        $listBase = $this->roundToInt(bcmul($line->quantity, (string) $line->listUnitPriceMinor, 6));

        if ($listBase <= 0 || $amount >= $listBase) {
            return 0;
        }

        return min(10000, $this->roundToInt(bcdiv(bcmul((string) ($listBase - $amount), '10000', 0), (string) $listBase, 6)));
    }

    /**
     * @param  array{net: int, vat: int, gross: int}  $sum
     * @return array{net: int, vat: int, gross: int}
     */
    protected function add(array $sum, LineAmounts $amounts): array
    {
        return ['net' => $sum['net'] + $amounts->net, 'vat' => $sum['vat'] + $amounts->vat, 'gross' => $sum['gross'] + $amounts->gross];
    }

    protected function roundToInt(string $value): int
    {
        return (int) DocumentFormatter::round($value, 0);
    }
}
