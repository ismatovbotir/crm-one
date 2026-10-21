<?php

namespace App\Enums;

/**
 * Supplier/carrier qualification (Spec 03 6.2): prospect → pending_qualification → qualified →
 * suspended | blacklisted; qualified falls back to pending_qualification when a document expires.
 */
enum CounterpartyStatus: string
{
    case Prospect = 'prospect';
    case PendingQualification = 'pending_qualification';
    case Qualified = 'qualified';
    case Suspended = 'suspended';
    case Blacklisted = 'blacklisted';

    /**
     * @return array<string, list<self>>
     */
    public static function transitions(): array
    {
        return [
            self::Prospect->value => [self::PendingQualification, self::Qualified, self::Blacklisted],
            self::PendingQualification->value => [self::Qualified, self::Prospect, self::Suspended, self::Blacklisted],
            self::Qualified->value => [self::PendingQualification, self::Suspended, self::Blacklisted],
            self::Suspended->value => [self::PendingQualification, self::Qualified, self::Blacklisted],
            self::Blacklisted->value => [self::PendingQualification],
        ];
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, self::transitions()[$this->value], true);
    }

    /** May be invited and bid (quick-created prospects may bid, flagged until qualified). */
    public function canBid(): bool
    {
        return ! in_array($this, [self::Suspended, self::Blacklisted], true);
    }

    public function label(): string
    {
        return __('counterparties.status.'.$this->value);
    }

    /**
     * @return array{0: string, 1: string|null}
     */
    public function badge(): array
    {
        return match ($this) {
            self::Prospect => ['outline', null],
            self::PendingQualification => ['silver', 'clock'],
            self::Qualified => ['dark', 'check'],
            self::Suspended => ['outline', 'lock'],
            self::Blacklisted => ['outline', 'close'],
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
