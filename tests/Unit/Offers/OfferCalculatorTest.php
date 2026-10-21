<?php

namespace Tests\Unit\Offers;

use App\Enums\OptionGroupKind;
use App\Services\Offers\Calculation\CalculatorGroup;
use App\Services\Offers\Calculation\CalculatorLine;
use App\Services\Offers\OfferCalculator;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Spec 05 section 8 / AC-6..AC-8: pure offer math, per-line half-up rounding, alternatives never
 * added together, range and recommended totals, worst-case discount.
 */
class OfferCalculatorTest extends TestCase
{
    private OfferCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->calculator = new OfferCalculator;
    }

    /**
     * @return array{0: list<CalculatorLine>, 1: list<CalculatorGroup>}
     */
    private function fixture(): array
    {
        return [[
            new CalculatorLine('fixed', '1', 100000, 0, 1200),
            new CalculatorLine('A', '1', 50000, 0, 1200, 'alt'),
            new CalculatorLine('B', '1', 80000, 0, 1200, 'alt', isRecommended: true),
            new CalculatorLine('C', '1', 65000, 0, 1200, 'alt'),
            new CalculatorLine('X', '1', 10000, 0, 1200, 'opt'),
            new CalculatorLine('Y', '1', 5000, 0, 1200, 'opt'),
        ], [
            new CalculatorGroup('alt', OptionGroupKind::Alternative, 1, 1),
            new CalculatorGroup('opt', OptionGroupKind::Optional, 0, 2),
        ]];
    }

    /** AC-6 */
    public function test_without_choices_the_offer_shows_fixed_range_and_recommended_never_the_sum(): void
    {
        [$lines, $groups] = $this->fixture();
        $result = $this->calculator->calculate($lines, $groups, false);

        $this->assertSame(112000, $result->fixedGross);
        $this->assertSame([168000, 218400], [$result->rangeMinGross, $result->rangeMaxGross]);
        $this->assertSame(201600, $result->recommendedGross);
        $this->assertFalse($result->selectionComplete);
        $this->assertSame(112000, $result->selectedGross, 'Selected = fixed only while nothing is chosen.');
        $this->assertNotContains(347200, [$result->fixedGross, $result->selectedGross, $result->rangeMinGross, $result->rangeMaxGross, $result->recommendedGross], 'The sum of all lines (3 100.00 net) appears nowhere.');
        $this->assertSame(201600, $result->pipelineValue());
    }

    /** AC-7 */
    public function test_selected_totals_and_range_follow_the_choices(): void
    {
        [$lines, $groups] = $this->fixture();

        $withC = $this->calculator->calculate($lines, $groups, false, ['C']);
        $this->assertSame([165000, 184800], [$withC->selectedNet, $withC->selectedGross]);
        $this->assertTrue($withC->selectionComplete, 'Optional group min 0 is complete without choices.');
        $this->assertSame([184800, 201600], [$withC->rangeMinGross, $withC->rangeMaxGross]);

        $withCX = $this->calculator->calculate($lines, $groups, false, ['C', 'X']);
        $this->assertSame(196000, $withCX->selectedGross);
        $this->assertSame([196000, 201600], [$withCX->rangeMinGross, $withCX->rangeMaxGross]);
        $this->assertSame(196000, $withCX->pipelineValue());
    }

    /** AC-8 */
    public function test_gross_basis_extracts_vat_and_rounds_half_up_per_line(): void
    {
        $single = $this->calculator->line(new CalculatorLine('g', '1', 112000, 0, 1200), true);
        $this->assertSame([100000, 12000, 112000], [$single->net, $single->vat, $single->gross]);

        // 3 x 1 120.00 = 3 360.00; 3.33% = 111.888 -> 111.89; 3 248.11 gross; VAT 12/112 = 348.0118 -> 348.01.
        $discounted = $this->calculator->line(new CalculatorLine('d', '3', 112000, 333, 1200), true);
        $this->assertSame([336000, 11189, 324811, 34801, 290010], [$discounted->base, $discounted->discount, $discounted->gross, $discounted->vat, $discounted->net]);

        $offer = $this->calculator->calculate([new CalculatorLine('a', '3', 112000, 333, 1200), new CalculatorLine('b', '1', 112000, 0, 1200)], [], true);
        $this->assertSame(324811 + 112000, $offer->selectedGross, 'The total equals the sum of the rounded line totals.');
        $this->assertSame(34801 + 12000, $offer->selectedVat);
    }

    /**
     * @return array<string, array{string, int, int}>
     */
    public static function roundingCases(): array
    {
        return [
            'half up on .5' => ['1.5', 333, 500],
            'below half' => ['1.001', 333, 333],
            'three decimals' => ['0.125', 100, 13],
            'large amount' => ['1', 1_000_000_000_000_000, 1_000_000_000_000_000],
        ];
    }

    #[DataProvider('roundingCases')]
    public function test_line_base_is_rounded_half_up(string $quantity, int $price, int $expectedBase): void
    {
        $this->assertSame($expectedBase, $this->calculator->line(new CalculatorLine('r', $quantity, $price), false)->base);
    }

    public function test_vat_exempt_lines_have_no_vat(): void
    {
        $line = $this->calculator->line(new CalculatorLine('e', '2', 50000, 0, 0), false);

        $this->assertSame([100000, 0, 100000], [$line->net, $line->vat, $line->gross]);
    }

    public function test_choose_up_to_two_alternatives_range(): void
    {
        $lines = [
            new CalculatorLine('A', '1', 10000, 0, 0, 'g'),
            new CalculatorLine('B', '1', 30000, 0, 0, 'g'),
            new CalculatorLine('C', '1', 20000, 0, 0, 'g'),
        ];
        $groups = [new CalculatorGroup('g', OptionGroupKind::Alternative, 1, 2)];

        $none = $this->calculator->calculate($lines, $groups, false);
        $this->assertSame([10000, 50000], [$none->rangeMinGross, $none->rangeMaxGross]);
        $this->assertNull($none->recommendedGross, 'A required group without a recommended option has no recommended total.');

        $withA = $this->calculator->calculate($lines, $groups, false, ['A']);
        $this->assertSame([10000, 40000], [$withA->rangeMinGross, $withA->rangeMaxGross]);
        $this->assertTrue($withA->selectionComplete);

        $tooMany = $this->calculator->calculate($lines, $groups, false, ['A', 'B', 'C']);
        $this->assertFalse($tooMany->selectionComplete);
    }

    /** DEC-6 worst case: every option counts, recommended or not. */
    public function test_max_discount_is_the_worst_case_over_every_line(): void
    {
        [$lines, $groups] = $this->fixture();
        $lines[3] = new CalculatorLine('C', '1', 65000, 800, 1200, 'alt');

        $this->assertSame(800, $this->calculator->calculate($lines, $groups, false)->maxDiscountBp);
    }

    public function test_manual_price_below_list_counts_as_discount(): void
    {
        $line = $this->calculator->line(new CalculatorLine('m', '2', 90000, 0, 1200, listUnitPriceMinor: 100000), false);
        $this->assertSame(1000, $line->effectiveDiscountBp);

        $both = $this->calculator->line(new CalculatorLine('m', '1', 90000, 1000, 1200, listUnitPriceMinor: 100000), false);
        $this->assertSame(1900, $both->effectiveDiscountBp);

        $above = $this->calculator->line(new CalculatorLine('m', '1', 110000, 0, 1200, listUnitPriceMinor: 100000), false);
        $this->assertSame(0, $above->effectiveDiscountBp);
    }

    public function test_invalid_input_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->calculator->calculate([new CalculatorLine('x', '1', 100, 0, 0, 'missing')], [], false);
    }
}
