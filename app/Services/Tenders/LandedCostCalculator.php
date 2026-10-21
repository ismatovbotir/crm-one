<?php

namespace App\Services\Tenders;

use App\Enums\ChargeInclusion;
use App\Enums\TenderType;
use App\Models\Tender;
use App\Models\TenderBid;
use App\Models\TenderBidLot;
use App\Support\BusinessSettings;

/**
 * Landed cost of one bid lot in the tender evaluation currency (BR-T.8, transport-tender skill
 * section 5, Spec 08 7.2), BCMath with half-up rounding once per component:
 *
 *   transport: Σ included charges (bid FX) + Σ owner estimates for not-included charges (marked)
 *              + expected downtime days × downtime rate
 *              + prepayment × cost of capital × transit days / 365
 *              + cargo value × carrying rate × transit days / 365
 *   purchase:  Σ quoted item lines + prepayment × cost of capital × lead time days / 365
 *
 * "Not included" components are never zero: without an estimate the result is incomplete.
 * Charges a bidder did not list are deemed included (notice condition 3).
 */
class LandedCostCalculator
{
    /** Bid charge code → the tender's payer matrix key (Spec 08 7.2). */
    public const PAYER_KEYS = ['customs_brokerage' => 'brokerage', 'terminal_storage' => 'terminal'];

    public function __construct(protected TenderFx $fx) {}

    /**
     * @return array{total: int|null, uses_estimates: bool, missing_fx: list<string>, missing_estimates: list<string>, components: list<array{code: string, kind: string, amount: int|null}>}
     */
    public function calculate(Tender $tender, TenderBid $bid, TenderBidLot $bidLot): array
    {
        $components = [];
        $missingFx = [];
        $missingEstimates = [];
        $usesEstimates = false;
        $convert = function (int $amount, string $currency) use ($tender, &$missingFx): ?int {
            $converted = $this->fx->toEvaluation($tender, $amount, $currency);

            if ($converted === null) {
                $missingFx[] = strtoupper($currency);
            }

            return $converted;
        };

        foreach ($bidLot->lines as $line) {
            if ($tender->type === TenderType::Purchase) {
                $components[] = ['code' => 'item:'.$line->tender_item_id, 'kind' => 'item', 'amount' => $convert($line->amount_minor, $bid->currency)];

                continue;
            }

            $code = (string) $line->charge_code?->value;
            $payerKey = self::PAYER_KEYS[$code] ?? null;
            $sellerScope = $payerKey !== null && (($bidLot->lot?->transportSpec?->charges_payer ?? [])[$payerKey] ?? null) === 'seller';

            if ($sellerScope) {
                // Spec 08 7.2: a charge the seller pays under the Incoterm is excluded (F13 when quoted).
                $components[] = ['code' => $code, 'kind' => $line->inclusion === ChargeInclusion::Included ? 'seller_scope' : 'na', 'amount' => 0];

                continue;
            }

            if ($line->inclusion === ChargeInclusion::Included) {
                $components[] = ['code' => $code, 'kind' => 'included', 'amount' => $convert($line->amount_minor, $bid->currency)];
            } elseif ($line->inclusion === ChargeInclusion::NotIncluded) {
                if ($line->estimate_minor === null) {
                    $missingEstimates[] = $code;
                    $components[] = ['code' => $code, 'kind' => 'estimate', 'amount' => null];
                } else {
                    $usesEstimates = true;
                    $components[] = ['code' => $code, 'kind' => 'estimate', 'amount' => $line->estimate_minor];
                }
            } else {
                $components[] = ['code' => $code, 'kind' => 'na', 'amount' => 0];
            }
        }

        $days = $tender->type === TenderType::Transport ? (int) $bidLot->transit_days : (int) $bidLot->lead_time_days;

        if ($tender->type === TenderType::Transport && $bidLot->downtime_rate_minor_per_day !== null) {
            $downtime = self::roundHalfUp(bcmul((string) $bidLot->downtime_rate_minor_per_day, (string) $tender->expected_downtime_days, 6));
            $components[] = ['code' => 'downtime', 'kind' => 'downtime', 'amount' => $convert($downtime, $bid->currency)];
        }

        $bidTotal = $convert($bidLot->total_minor, $bid->currency);

        if ($bid->prepayment_bp > 0 && $days > 0 && $bidTotal !== null) {
            $financing = bcdiv(bcmul(bcmul(bcmul((string) $bidTotal, (string) $bid->prepayment_bp), (string) BusinessSettings::int('tenders.cost_of_capital_bp')), (string) $days), bcmul('100000000', '365'), 6);
            $components[] = ['code' => 'financing', 'kind' => 'financing', 'amount' => self::roundHalfUp($financing)];
        }

        $spec = $bidLot->lot?->transportSpec;

        if ($tender->type === TenderType::Transport && $spec !== null && $spec->cargo_value_minor !== null && filled($spec->cargo_currency) && $days > 0) {
            $value = $convert($spec->cargo_value_minor, (string) $spec->cargo_currency);

            if ($value !== null) {
                $carrying = bcdiv(bcmul(bcmul((string) $value, (string) BusinessSettings::int('tenders.carrying_rate_bp')), (string) $days), bcmul('10000', '365'), 6);
                $components[] = ['code' => 'carrying', 'kind' => 'carrying', 'amount' => self::roundHalfUp($carrying)];
            }
        }

        $complete = $missingFx === [] && $missingEstimates === [];
        $total = $complete ? array_sum(array_map(fn (array $component): int => (int) $component['amount'], $components)) : null;

        return [
            'total' => $total,
            'uses_estimates' => $usesEstimates,
            'missing_fx' => array_values(array_unique($missingFx)),
            'missing_estimates' => $missingEstimates,
            'components' => $components,
        ];
    }

    public static function roundHalfUp(string $value): int
    {
        return (int) (str_starts_with($value, '-') ? bcsub($value, '0.5', 0) : bcadd($value, '0.5', 0));
    }
}
