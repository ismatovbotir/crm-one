<?php

namespace App\Enums;

/**
 * Supplier purchase order (Spec 08 6.1). Spec 03 builds draft → confirmed / cancelled from awards;
 * transit and receipt states are set by Spec 08 shipments and Spec 06 goods receipts.
 */
enum PurchaseOrderStatus: string
{
    case Draft = 'draft';
    case Confirmed = 'confirmed';
    case InTransit = 'in_transit';
    case PartiallyReceived = 'partially_received';
    case Received = 'received';
    case Closed = 'closed';
    case Cancelled = 'cancelled';

    /**
     * @return array<string, list<self>>
     */
    public static function transitions(): array
    {
        return [
            self::Draft->value => [self::Confirmed, self::Cancelled],
            self::Confirmed->value => [self::InTransit, self::PartiallyReceived, self::Received, self::Cancelled],
            self::InTransit->value => [self::PartiallyReceived, self::Received],
            self::PartiallyReceived->value => [self::Received],
            self::Received->value => [self::Closed],
            self::Closed->value => [],
            self::Cancelled->value => [],
        ];
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, self::transitions()[$this->value], true);
    }

    public function label(): string
    {
        return __('purchase_orders.status.'.$this->value);
    }

    /**
     * @return array{0: string, 1: string|null}
     */
    public function badge(): array
    {
        return match ($this) {
            self::Draft => ['outline', null],
            self::Confirmed => ['silver', 'check'],
            self::InTransit, self::PartiallyReceived => ['silver', 'clock'],
            self::Received => ['dark', 'check'],
            self::Closed => ['dark', 'lock'],
            self::Cancelled => ['outline', 'close'],
        };
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
