<?php

namespace App\Enums;

use App\Support\BusinessSettings;

/**
 * Payment states (Spec 06 6.7). CRM payments below the approval threshold start `recorded`
 * ("pending 1C"), above it `pending_approval`; 1C acks confirm them; 1C pushes arrive
 * `confirmed`. Only `recorded` (setting DEC-13) and `confirmed` count toward balances.
 */
enum PaymentStatus: string
{
    case PendingApproval = 'pending_approval';
    case Recorded = 'recorded';
    case Confirmed = 'confirmed';
    case OnecRejected = 'onec_rejected';
    case Voided = 'voided';
    case Rejected = 'rejected';

    /**
     * @return array<string, list<self>>
     */
    public static function transitions(): array
    {
        return [
            self::PendingApproval->value => [self::Recorded, self::Rejected],
            self::Recorded->value => [self::Confirmed, self::OnecRejected, self::Voided],
            self::Confirmed->value => [self::Voided],
            self::OnecRejected->value => [self::Voided],
            self::Voided->value => [],
            self::Rejected->value => [],
        ];
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, self::transitions()[$this->value], true);
    }

    /** Counts toward order payment status and balances (DEC-13). */
    public function counts(): bool
    {
        return $this === self::Confirmed || ($this === self::Recorded && BusinessSettings::bool('accounting.count_unconfirmed_payments'));
    }

    /** May be allocated to orders. */
    public function isAllocatable(): bool
    {
        return in_array($this, [self::Recorded, self::Confirmed], true);
    }

    /**
     * Statuses that count (for queries).
     *
     * @return list<self>
     */
    public static function counting(): array
    {
        return BusinessSettings::bool('accounting.count_unconfirmed_payments') ? [self::Recorded, self::Confirmed] : [self::Confirmed];
    }

    public function label(): string
    {
        return __('accounting.payment_status.'.$this->value);
    }

    /**
     * @return array{0: string, 1: string|null}
     */
    public function badge(): array
    {
        return match ($this) {
            self::PendingApproval => ['danger-outline', 'clock'],
            self::Recorded => ['silver', 'sync'],
            self::Confirmed => ['dark', 'check'],
            self::OnecRejected => ['red', 'warning'],
            self::Voided => ['outline', 'close'],
            self::Rejected => ['outline', 'close'],
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
