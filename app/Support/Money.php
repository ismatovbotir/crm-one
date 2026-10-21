<?php

namespace App\Support;

use App\Documents\DocumentFormatter;
use App\Enums\Locale;
use InvalidArgumentException;

/**
 * Immutable amount of money in integer minor units of an ISO 4217 currency (Spec 01 BR-1).
 * Never uses floats: parsing and multiplication go through BCMath, rounding half-up.
 */
final class Money
{
    public function __construct(public readonly int $amount, public readonly string $currency)
    {
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new InvalidArgumentException("Invalid currency code [{$currency}].");
        }
    }

    public static function of(int $minor, string $currency): self
    {
        return new self($minor, strtoupper($currency));
    }

    /**
     * Number of minor-unit decimals of a currency (config crm.currencies, default 2).
     */
    public static function exponent(string $currency): int
    {
        return (int) (config('crm.currencies')[strtoupper($currency)] ?? 2);
    }

    /**
     * Parse a major-unit decimal string ("1250000.50", "1 250 000,50") into minor units.
     * Rejects more decimals than the currency allows (AC-2.3) and anything that is not a number.
     */
    public static function fromMajor(string $value, string $currency): self
    {
        $currency = strtoupper($currency);
        $normalized = str_replace([' ', "\u{00A0}", ','], ['', '', '.'], trim($value));

        if (preg_match('/^-?\d+(\.\d+)?$/', $normalized) !== 1) {
            throw new InvalidArgumentException("Not a decimal amount: [{$value}].");
        }

        $exponent = self::exponent($currency);
        $fraction = explode('.', $normalized)[1] ?? '';

        if (strlen(rtrim($fraction, '0')) > $exponent) {
            throw new InvalidArgumentException("[{$value}] has more than {$exponent} decimals for {$currency}.");
        }

        $minor = bcmul($normalized, bcpow('10', (string) $exponent), 0);

        return new self((int) $minor, $currency);
    }

    /**
     * Parse a non-negative major-unit decimal string, rounding extra decimals half-up
     * (Spec 02 BR-I.3: "100.505" UZS => 10051). Returns the amount and whether it was rounded.
     *
     * @return array{0: self, 1: bool}
     */
    public static function fromMajorRoundedHalfUp(string $value, string $currency): array
    {
        $currency = strtoupper($currency);

        if (preg_match('/^\d+(\.\d+)?$/', $value) !== 1) {
            throw new InvalidArgumentException("Not a non-negative decimal amount: [{$value}].");
        }

        $exponent = self::exponent($currency);
        $fraction = rtrim(explode('.', $value)[1] ?? '', '0');
        $scaled = bcmul($value, bcpow('10', (string) $exponent), strlen($fraction));
        $minor = bcadd($scaled, '0.5', 0);

        return [new self((int) $minor, $currency), strlen($fraction) > $exponent];
    }

    /**
     * Whether a major-unit string can be parsed for the currency.
     */
    public static function isValidMajor(string $value, string $currency): bool
    {
        try {
            self::fromMajor($value, $currency);

            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    public function add(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->amount + $other->amount, $this->currency);
    }

    public function subtract(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->amount - $other->amount, $this->currency);
    }

    /**
     * Multiply by a decimal quantity string, rounding half-up (away from zero) to the minor unit.
     */
    public function multiplyByQty(string $quantity): self
    {
        $product = bcmul((string) $this->amount, DocumentFormatter::decimal($quantity), 6);

        return new self((int) DocumentFormatter::round($product, 0), $this->currency);
    }

    /**
     * Major-unit decimal string, e.g. 125000050 UZS => "1250000.50".
     */
    public function toMajor(): string
    {
        $exponent = self::exponent($this->currency);

        return bcdiv((string) $this->amount, bcpow('10', (string) $exponent), $exponent);
    }

    public function equals(self $other): bool
    {
        return $this->currency === $other->currency && $this->amount === $other->amount;
    }

    /**
     * Locale-aware display, e.g. "1 250 000,50 UZS".
     */
    public function format(?Locale $locale = null): string
    {
        return (new DocumentFormatter($locale ?? Locale::current()))->money($this->toMajor(), $this->currency, self::exponent($this->currency));
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new CurrencyMismatch("Cannot combine {$this->currency} with {$other->currency}.");
        }
    }
}
