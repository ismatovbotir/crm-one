<?php

namespace App\Enums;

/**
 * Offer lifecycle (Spec 05 section 6.1) with the explicit allowed-transition map. Status changes
 * happen only in App\Actions\Offers\TransitionOffer, which checks canTransitionTo().
 */
enum OfferStatus: string
{
    case Draft = 'draft';
    case PendingApproval = 'pending_approval';
    case Approved = 'approved';
    case Sent = 'sent';
    case Viewed = 'viewed';
    case PartiallySelected = 'partially_selected';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Expired = 'expired';
    case Superseded = 'superseded';
    case Cancelled = 'cancelled';

    /**
     * @return array<string, list<self>>
     */
    public static function transitions(): array
    {
        return [
            self::Draft->value => [self::PendingApproval, self::Sent, self::Cancelled],
            self::PendingApproval->value => [self::Approved, self::Draft, self::Cancelled],
            self::Approved->value => [self::Sent, self::Draft, self::Cancelled],
            self::Sent->value => [self::Viewed, self::PartiallySelected, self::Accepted, self::Rejected, self::Expired, self::Superseded, self::Cancelled],
            self::Viewed->value => [self::PartiallySelected, self::Accepted, self::Rejected, self::Expired, self::Superseded, self::Cancelled],
            self::PartiallySelected->value => [self::Accepted, self::Rejected, self::Expired, self::Superseded, self::Cancelled],
            self::Expired->value => [self::Sent, self::Superseded],
            self::Accepted->value => [],
            self::Rejected->value => [],
            self::Superseded->value => [],
            self::Cancelled->value => [],
        ];
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, self::transitions()[$this->value], true);
    }

    /** Sent to the customer and awaiting a decision. */
    public function isOpen(): bool
    {
        return in_array($this, [self::Sent, self::Viewed, self::PartiallySelected], true);
    }

    /** Content may be changed (approved content changes return the offer to draft). */
    public function isEditable(): bool
    {
        return in_array($this, [self::Draft, self::Approved], true);
    }

    public function isFinal(): bool
    {
        return in_array($this, [self::Accepted, self::Rejected, self::Superseded, self::Cancelled], true);
    }

    /** Statuses a new version can be revised from (Spec 05 BR-18). */
    public function isRevisable(): bool
    {
        return $this->isOpen() || in_array($this, [self::Expired, self::Rejected], true);
    }

    public function label(): string
    {
        return __('offers.status.'.$this->value);
    }

    /**
     * Badge tone + icon: meaning is never colour alone (text label always shown).
     *
     * @return array{0: string, 1: string|null}
     */
    public function badge(): array
    {
        return match ($this) {
            self::Draft => ['outline', null],
            self::PendingApproval => ['danger-outline', 'clock'],
            self::Approved => ['silver', 'check'],
            self::Sent, self::Viewed => ['silver', 'offers'],
            self::PartiallySelected => ['silver', 'star'],
            self::Accepted => ['dark', 'check'],
            self::Rejected => ['red', 'close'],
            self::Expired => ['outline', 'clock'],
            self::Superseded => ['outline', 'archive'],
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

    /**
     * @return list<self>
     */
    public static function open(): array
    {
        return [self::Sent, self::Viewed, self::PartiallySelected];
    }
}
