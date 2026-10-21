<?php

namespace App\Enums;

/**
 * Stock-off act states (Spec 06 section 27.3): draft → submitted → approved → posted (immutable);
 * submitted → rejected → draft (rework); draft / submitted / approved → cancelled.
 */
enum StockOffStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Approved = 'approved';
    case Posted = 'posted';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    /**
     * @return array<string, list<self>>
     */
    public static function transitions(): array
    {
        return [
            self::Draft->value => [self::Submitted, self::Cancelled],
            self::Submitted->value => [self::Approved, self::Rejected, self::Cancelled],
            self::Approved->value => [self::Posted, self::Cancelled],
            self::Rejected->value => [self::Draft, self::Cancelled],
            self::Posted->value => [],
            self::Cancelled->value => [],
        ];
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, self::transitions()[$this->value], true);
    }

    /** Lines, serials and evidence change only in draft. */
    public function isEditable(): bool
    {
        return $this === self::Draft;
    }

    /** Serials on an open act are held (cannot sit on a second act). */
    public function isOpen(): bool
    {
        return in_array($this, [self::Draft, self::Submitted, self::Approved, self::Rejected], true);
    }

    /**
     * @return list<self>
     */
    public static function steps(): array
    {
        return [self::Draft, self::Submitted, self::Approved, self::Posted];
    }

    /**
     * @return array{0: string, 1: string|null}
     */
    public function badge(): array
    {
        return match ($this) {
            self::Draft => ['outline', null],
            self::Submitted => ['silver', 'clock'],
            self::Approved => ['silver', 'check'],
            self::Posted => ['dark', 'lock'],
            self::Rejected => ['outline', 'warning'],
            self::Cancelled => ['outline', 'close'],
        };
    }

    public function label(): string
    {
        return __('stock_off.status.'.$this->value);
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
