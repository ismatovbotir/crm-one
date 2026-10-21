<?php

namespace App\Enums;

/**
 * Agreement lifecycle (Spec 06 section 6.5, DEC-9) with the allowed-transition map. Changes only
 * through App\Actions\Agreements\TransitionAgreement.
 */
enum AgreementStatus: string
{
    case Draft = 'draft';
    case InReview = 'in_review';
    case SentForSignature = 'sent_for_signature';
    case Signed = 'signed';
    case Active = 'active';
    case Expired = 'expired';
    case Terminated = 'terminated';
    case Renewed = 'renewed';
    case Cancelled = 'cancelled';

    /**
     * @return array<string, list<self>>
     */
    public static function transitions(): array
    {
        return [
            self::Draft->value => [self::InReview, self::SentForSignature, self::Cancelled],
            self::InReview->value => [self::Draft, self::SentForSignature, self::Cancelled],
            // → draft = re-issue: version + 1, the sent version stays stored and printable (AC-16).
            self::SentForSignature->value => [self::Signed, self::Draft, self::Cancelled],
            self::Signed->value => [self::Active, self::Terminated],
            self::Active->value => [self::Expired, self::Terminated, self::Renewed],
            self::Expired->value => [],
            self::Terminated->value => [],
            self::Renewed->value => [],
            self::Cancelled->value => [],
        ];
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, self::transitions()[$this->value], true);
    }

    /** Signed or active: satisfies the "agreement required" rule. */
    public function isBinding(): bool
    {
        return in_array($this, [self::Signed, self::Active], true);
    }

    public function isEditable(): bool
    {
        return $this === self::Draft;
    }

    public function label(): string
    {
        return __('agreements.status.'.$this->value);
    }

    /**
     * @return array{0: string, 1: string|null}
     */
    public function badge(): array
    {
        return match ($this) {
            self::Draft => ['outline', null],
            self::InReview => ['danger-outline', 'clock'],
            self::SentForSignature => ['silver', 'clock'],
            self::Signed => ['dark', 'check'],
            self::Active => ['dark', 'check'],
            self::Expired => ['outline', 'clock'],
            self::Terminated => ['red', 'close'],
            self::Renewed => ['outline', 'archive'],
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
