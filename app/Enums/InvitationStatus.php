<?php

namespace App\Enums;

/**
 * Invitation board status (Spec 08 6.3), derived from bid events.
 */
enum InvitationStatus: string
{
    case Invited = 'invited';
    case LinkSent = 'link_sent';
    case Opened = 'opened';
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Withdrawn = 'withdrawn';
    case Declined = 'declined';
    case NoResponse = 'no_response';

    public function label(): string
    {
        return __('tenders.invitation_status.'.$this->value);
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
