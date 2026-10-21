<?php

namespace App\Enums;

/**
 * How a customer decision (choice, acceptance) or a sent offer reached us (Spec 05 7.5). Portal
 * and public link are reserved for increment 05-8.
 */
enum SelectionChannel: string
{
    case Phone = 'phone';
    case Email = 'email';
    case Messenger = 'messenger';
    case Meeting = 'meeting';
    case Paper = 'paper';
    case ESign = 'e_sign';
    case Portal = 'portal';
    case PublicLink = 'public_link';

    public function label(): string
    {
        return __('offers.channels.'.$this->value);
    }

    /** A note is mandatory when recording on behalf through this channel (BR-12). */
    public function requiresNote(): bool
    {
        return in_array($this->value, (array) config('offers.note_required_channels'), true);
    }

    /** Evidence is mandatory (no attachments yet: an evidence description, BR-12). */
    public function requiresEvidence(): bool
    {
        return in_array($this->value, (array) config('offers.evidence_required_channels'), true);
    }

    /**
     * Channels staff can record on behalf of the customer.
     *
     * @return list<self>
     */
    public static function internal(): array
    {
        return [self::Phone, self::Email, self::Messenger, self::Meeting, self::Paper, self::ESign];
    }

    /**
     * Channels an offer can be sent through (delivery itself is out of scope: stub).
     *
     * @return list<self>
     */
    public static function sending(): array
    {
        return [self::Email, self::Messenger, self::Paper, self::Meeting];
    }
}
