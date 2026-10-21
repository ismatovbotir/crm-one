<?php

namespace App\Enums;

/**
 * Order lifecycle (Spec 06 section 6.1, target flow section 25) with the allowed-transition map:
 * confirmed → packing (payment rule and agreement gate) → packed (packing list complete, every
 * serial registered) → partially delivered / delivered → invoiced (B2B tax document) → closed.
 * Retail orders close from delivered (sales receipt instead of a tax document). Changes only
 * through App\Actions\Orders\TransitionOrder.
 */
enum OrderStatus: string
{
    case New = 'new';
    case Confirmed = 'confirmed';
    case Packing = 'packing';
    case Packed = 'packed';
    case PartiallyDelivered = 'partially_delivered';
    case Delivered = 'delivered';
    case Invoiced = 'invoiced';
    case Closed = 'closed';
    case Cancelled = 'cancelled';

    /**
     * @return array<string, list<self>>
     */
    public static function transitions(): array
    {
        return [
            self::New->value => [self::Confirmed, self::Cancelled],
            self::Confirmed->value => [self::Packing, self::Cancelled],
            self::Packing->value => [self::Packed, self::Cancelled],
            self::Packed->value => [self::Packing, self::PartiallyDelivered, self::Delivered, self::Cancelled],
            self::PartiallyDelivered->value => [self::Packing, self::Delivered],
            self::Delivered->value => [self::Invoiced, self::Closed],
            self::Invoiced->value => [self::Closed],
            self::Closed->value => [],
            self::Cancelled->value => [],
        ];
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, self::transitions()[$this->value], true);
    }

    public function isFinal(): bool
    {
        return in_array($this, [self::Closed, self::Cancelled], true);
    }

    /** Confirmed and not finished: counts toward credit exposure (BR-AC.19). */
    public function isActive(): bool
    {
        return ! in_array($this, [self::New, self::Closed, self::Cancelled], true);
    }

    public function label(): string
    {
        return __('orders.status.'.$this->value);
    }

    /**
     * @return array{0: string, 1: string|null}
     */
    public function badge(): array
    {
        return match ($this) {
            self::New => ['outline', null],
            self::Confirmed => ['silver', 'check'],
            self::Packing => ['silver', 'clock'],
            self::Packed => ['silver', 'archive'],
            self::PartiallyDelivered => ['silver', 'orders'],
            self::Delivered => ['silver', 'check'],
            self::Invoiced => ['dark', 'check'],
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
