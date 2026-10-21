<?php

namespace App\Enums;

/**
 * Bid version states (Spec 03 6.2): a revision supersedes the previous submitted version;
 * withdrawal only before the deadline; after opening a bid is disqualified, awarded or not.
 */
enum BidStatus: string
{
    case Submitted = 'submitted';
    case Superseded = 'superseded';
    case Withdrawn = 'withdrawn';
    case Disqualified = 'disqualified';
    case Awarded = 'awarded';
    case NotAwarded = 'not_awarded';

    /** The current, valid version of a counterparty's bid. */
    public function isCurrent(): bool
    {
        return in_array($this, [self::Submitted, self::Awarded, self::NotAwarded], true);
    }

    public function label(): string
    {
        return __('tenders.bid_status.'.$this->value);
    }

    /**
     * @return array{0: string, 1: string|null}
     */
    public function badge(): array
    {
        return match ($this) {
            self::Submitted => ['silver', 'check'],
            self::Superseded, self::Withdrawn => ['outline', null],
            self::Disqualified => ['outline', 'close'],
            self::Awarded => ['dark', 'star'],
            self::NotAwarded => ['outline', null],
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
