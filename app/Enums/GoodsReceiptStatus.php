<?php

namespace App\Enums;

/**
 * Goods receipt states (Spec 06 section 26.4). A receipt pushed by 1C starts at
 * `received_from_1c`; the stock manager starts it (`in_progress`: received / damaged quantities
 * and serials), marks it `checked` and posts it. `on_hold` parks a receipt with a discrepancy
 * until the warehouse head releases it. Customer returns, opening balances and serial
 * registrations are created by the CRM at `in_progress` and may be posted directly (short path).
 * `posted` and `cancelled` are final; 1C updates are accepted only before `posted`.
 * Delivery notes keep StockDocumentStatus.
 */
enum GoodsReceiptStatus: string
{
    case ReceivedFromOneC = 'received_from_1c';
    case InProgress = 'in_progress';
    case Checked = 'checked';
    case OnHold = 'on_hold';
    case Posted = 'posted';
    case Cancelled = 'cancelled';

    /**
     * @return array<string, list<self>>
     */
    public static function transitions(): array
    {
        return [
            self::ReceivedFromOneC->value => [self::InProgress, self::OnHold, self::Cancelled],
            self::InProgress->value => [self::Checked, self::OnHold, self::Posted, self::Cancelled],
            self::Checked->value => [self::InProgress, self::OnHold, self::Posted, self::Cancelled],
            self::OnHold->value => [self::InProgress, self::Cancelled],
            self::Posted->value => [],
            self::Cancelled->value => [],
        ];
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, self::transitions()[$this->value], true);
    }

    /** Lines, quantities and serials change only while the receipt is being worked on. */
    public function isEditable(): bool
    {
        return $this === self::InProgress;
    }

    /** Not posted and not cancelled: 1C may still update it. */
    public function isOpen(): bool
    {
        return ! in_array($this, [self::Posted, self::Cancelled], true);
    }

    /**
     * Stepper of the main path (on hold and cancelled are shown as a badge, not a step).
     *
     * @return list<self>
     */
    public static function steps(bool $fromOneC): array
    {
        return $fromOneC
            ? [self::ReceivedFromOneC, self::InProgress, self::Checked, self::Posted]
            : [self::InProgress, self::Posted];
    }

    /**
     * @return array{0: string, 1: string|null}
     */
    public function badge(): array
    {
        return match ($this) {
            self::ReceivedFromOneC => ['outline', 'sync'],
            self::InProgress => ['silver', 'clock'],
            self::Checked => ['silver', 'check'],
            self::OnHold => ['outline', 'warning'],
            self::Posted => ['dark', 'check'],
            self::Cancelled => ['outline', 'close'],
        };
    }

    public function label(): string
    {
        return __('stock.receipt_status.'.$this->value);
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
