<?php

namespace App\Enums;

/**
 * Award per lot (Spec 03 7.3, BR-T.10): proposed → approved (chain complete) → accepted by the
 * winner, or rejected by an approver / declined by the winner (the lot goes back to evaluation).
 */
enum AwardStatus: string
{
    case Proposed = 'proposed';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Accepted = 'accepted';
    case Declined = 'declined';

    /** Occupies the lot (unique active award per lot). */
    public function isActive(): bool
    {
        return in_array($this, [self::Proposed, self::Approved, self::Accepted], true);
    }

    public function label(): string
    {
        return __('tenders.award_status.'.$this->value);
    }

    /**
     * @return array{0: string, 1: string|null}
     */
    public function badge(): array
    {
        return match ($this) {
            self::Proposed => ['silver', 'clock'],
            self::Approved => ['silver', 'check'],
            self::Accepted => ['dark', 'check'],
            self::Rejected, self::Declined => ['outline', 'close'],
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
