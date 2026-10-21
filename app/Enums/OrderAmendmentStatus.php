<?php

namespace App\Enums;

/**
 * Order amendment states (Spec 06 section 6.4): draft → pending_approval → approved → applied, or
 * rejected / cancelled. Without a discount above the requester's limit a draft goes straight to
 * approved and applied. The approver's decision applies the amendment in the same step.
 */
enum OrderAmendmentStatus: string
{
    case Draft = 'draft';
    case PendingApproval = 'pending_approval';
    case Approved = 'approved';
    case Applied = 'applied';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    /**
     * @return array<string, list<self>>
     */
    public static function transitions(): array
    {
        return [
            self::Draft->value => [self::PendingApproval, self::Approved, self::Cancelled],
            self::PendingApproval->value => [self::Approved, self::Rejected, self::Cancelled],
            self::Approved->value => [self::Applied],
            self::Applied->value => [],
            self::Rejected->value => [],
            self::Cancelled->value => [],
        ];
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, self::transitions()[$this->value], true);
    }

    /** Still being worked on: only one such amendment exists per order. */
    public function isOpen(): bool
    {
        return in_array($this, [self::Draft, self::PendingApproval], true);
    }

    /**
     * @return array{0: string, 1: string|null}
     */
    public function badge(): array
    {
        return match ($this) {
            self::Draft => ['outline', null],
            self::PendingApproval => ['silver', 'clock'],
            self::Approved => ['silver', 'check'],
            self::Applied => ['dark', 'lock'],
            self::Rejected => ['outline', 'warning'],
            self::Cancelled => ['outline', 'close'],
        };
    }

    public function label(): string
    {
        return __('order_amendments.status.'.$this->value);
    }
}
