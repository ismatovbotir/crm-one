<?php

namespace App\Services\Tenders;

use App\Models\Tender;
use App\Services\Accounting\FxRates;
use Carbon\CarbonImmutable;

/**
 * Evaluation FX (BR-T.8): `tenders.fx_rates` holds UZS per 1 unit per currency, frozen by finance at
 * close (CBU rate of the closing date). Conversions round half up once per component. Threshold
 * checks before close fall back to the accounting rates (`fx_rates`, latest on or before today).
 */
class TenderFx
{
    public function __construct(protected FxRates $rates) {}

    /** UZS per unit from the tender's frozen rates, or null when missing. */
    public function frozenRate(Tender $tender, string $currency): ?string
    {
        $currency = strtoupper($currency);

        if ($currency === 'UZS') {
            return '1';
        }

        $rate = $tender->fx_rates[$currency]['rate'] ?? null;

        return $rate === null || bccomp((string) $rate, '0', 6) <= 0 ? null : (string) $rate;
    }

    /**
     * Currencies used by current bids (and the evaluation currency) that lack a frozen rate.
     *
     * @param  list<string>  $currencies
     * @return list<string>
     */
    public function missing(Tender $tender, array $currencies): array
    {
        $currencies[] = $tender->evaluation_currency;

        return array_values(array_unique(array_filter(
            array_map('strtoupper', $currencies),
            fn (string $currency): bool => $this->frozenRate($tender, $currency) === null,
        )));
    }

    /** Amount in the tender's evaluation currency with the frozen rates, or null when a rate is missing. */
    public function toEvaluation(Tender $tender, int $amountMinor, string $currency): ?int
    {
        $currency = strtoupper($currency);
        $target = strtoupper($tender->evaluation_currency);

        if ($currency === $target) {
            return $amountMinor;
        }

        $from = $this->frozenRate($tender, $currency);
        $to = $this->frozenRate($tender, $target);

        if ($from === null || $to === null) {
            return null;
        }

        return FxRates::convert($amountMinor, bcdiv($from, $to, 10), $currency, $target);
    }

    /**
     * Amount in UZS for thresholds: frozen tender rate first, else the accounting rate of today;
     * null when no rate is known (callers then route to the higher approval level).
     */
    public function toUzs(?Tender $tender, int $amountMinor, string $currency): ?int
    {
        $currency = strtoupper($currency);

        if ($currency === 'UZS') {
            return $amountMinor;
        }

        $rate = ($tender !== null ? $this->frozenRate($tender, $currency) : null)
            ?? $this->rates->uzsPer($currency, CarbonImmutable::now(TenderClock::timezone())->toDateString());

        return $rate === null ? null : FxRates::convert($amountMinor, $rate, $currency, 'UZS');
    }
}
