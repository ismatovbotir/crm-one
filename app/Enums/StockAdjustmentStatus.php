<?php

namespace App\Enums;

/**
 * Stock adjustment states (Spec 06 section 21.4): draft → pending_approval → posted (the approver
 * approves and posts in one step) | rejected → draft (rework); draft / pending_approval / rejected → cancelled.
 */
enum StockAdjustmentStatus: string
{
    case Draft = 'draft';
    case PendingApproval = 'pending_approval';
    case Posted = 'posted';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    /**
     * @return array<string, list<self>>
     */
    public static function transitions(): array
    {
        return [
            self::Draft->value => [self::PendingApproval, self::Cancelled],
            self::PendingApproval->value => [self::Posted, self::Rejected, self::Cancelled],
            self::Rejected->value => [self::Draft, self::Cancelled],
            self::Posted->value => [],
            self::Cancelled->value => [],
        ];
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, self::transitions()[$this->value], true);
    }

    /** Lines change only in draft. */
    public function isEditable(): bool
    {
        return $this === self::Draft;
    }

    /** Serials on an open adjustment cannot sit on another one. */
    public function isOpen(): bool
    {
        return in_array($this, [self::Draft, self::PendingApproval, self::Rejected], true);
    }

    /**
     * @return list<self>
     */
    public static function steps(): array
    {
        return [self::Draft, self::PendingApproval, self::Posted];
    }

    /**
     * @return array{0: string, 1: string|null}
     */
    public function badge(): array
    {
        return match ($this) {
            self::Draft => ['outline', null],
            self::PendingApproval => ['silver', 'clock'],
            self::Posted => ['dark', 'lock'],
            self::Rejected => ['outline', 'warning'],
            self::Cancelled => ['outline', 'close'],
        };
    }

    public function label(): string
    {
        return __('stock_adjustments.status.'.$this->value);
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
