<?php

namespace App\Enums;

/**
 * Lead lifecycle (Spec 04 section 5) with the explicit allowed-transition map. Status changes
 * happen only in App\Actions\Leads\TransitionLead, which checks canTransitionTo().
 */
enum LeadStatus: string
{
    case New = 'new';
    case Contacted = 'contacted';
    case Qualified = 'qualified';
    case Converted = 'converted';
    case Disqualified = 'disqualified';
    case Lost = 'lost';

    /**
     * @return array<string, list<self>>
     */
    public static function transitions(): array
    {
        return [
            // Spec 04 section 18: "Convert to customer" works from any open status.
            self::New->value => [self::Contacted, self::Qualified, self::Converted, self::Disqualified, self::Lost],
            self::Contacted->value => [self::Qualified, self::Converted, self::Disqualified, self::Lost],
            self::Qualified->value => [self::Converted, self::Contacted, self::Disqualified, self::Lost],
            self::Disqualified->value => [self::Contacted],
            self::Lost->value => [self::Contacted],
            self::Converted->value => [],
        ];
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, self::transitions()[$this->value], true);
    }

    /** Still being worked (counts for stale detection and the default list filter). */
    public function isOpen(): bool
    {
        return in_array($this, [self::New, self::Contacted, self::Qualified], true);
    }

    /** Closed as disqualified or lost (may be reopened). */
    public function isClosed(): bool
    {
        return in_array($this, [self::Disqualified, self::Lost], true);
    }

    /**
     * @return list<string>
     */
    public static function openValues(): array
    {
        return [self::New->value, self::Contacted->value, self::Qualified->value];
    }

    public function label(): string
    {
        return __('leads.status.'.$this->value);
    }

    /**
     * Badge tone + icon: meaning is never colour alone (text label always shown).
     *
     * @return array{0: string, 1: string|null}
     */
    public function badge(): array
    {
        return match ($this) {
            self::New => ['outline', 'star'],
            self::Contacted => ['silver', 'contact'],
            self::Qualified => ['silver', 'check'],
            self::Converted => ['dark', 'offers'],
            self::Disqualified => ['outline', 'close'],
            self::Lost => ['red', 'close'],
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
