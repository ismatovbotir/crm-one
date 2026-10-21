<?php

namespace App\Services\Accounting;

use App\Models\FxRate;
use App\Support\Money;
use DateTimeInterface;

/**
 * Exchange rates (Spec 06 BR-AC.8): `fx_rates` stores UZS per 1 unit (CBU, entered manually).
 * An allocation rate is "order currency per 1 payment currency unit"; conversion rounds half up.
 */
class FxRates
{
    /** UZS per unit on the date (latest rate on or before it), or null. */
    public function uzsPer(string $currency, DateTimeInterface|string $date): ?string
    {
        $currency = strtoupper($currency);

        if ($currency === 'UZS') {
            return '1';
        }

        $rate = FxRate::query()
            ->where('currency', $currency)
            ->whereDate('rate_date', '<=', $date instanceof DateTimeInterface ? $date->format('Y-m-d') : $date)
            ->orderByDesc('rate_date')
            ->value('rate');

        return $rate === null ? null : (string) $rate;
    }

    /** Proposed rate: order currency per 1 payment currency unit, via UZS. */
    public function propose(string $paymentCurrency, string $orderCurrency, DateTimeInterface|string $date): ?string
    {
        if (strtoupper($paymentCurrency) === strtoupper($orderCurrency)) {
            return '1';
        }

        $from = $this->uzsPer($paymentCurrency, $date);
        $to = $this->uzsPer($orderCurrency, $date);

        if ($from === null || $to === null || bccomp($to, '0', 8) === 0) {
            return null;
        }

        return bcdiv($from, $to, 8);
    }

    /** round_half_up(amount × rate) between currency exponents (AC-26). */
    public static function convert(int $amountMinor, string $rate, string $fromCurrency, string $toCurrency): int
    {
        $shift = Money::exponent($toCurrency) - Money::exponent($fromCurrency);
        $value = bcmul((string) $amountMinor, $rate, 10);
        $value = $shift >= 0 ? bcmul($value, bcpow('10', (string) $shift), 10) : bcdiv($value, bcpow('10', (string) -$shift), 10);

        return (int) bcadd($value, '0.5', 0);
    }
}
