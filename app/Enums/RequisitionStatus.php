<?php

namespace App\Enums;

/**
 * Customer requisition workflow (Spec 10 §4): client side draft → submitted → approved (or
 * returned / rejected / merged / cancelled), then sent to us (`received`, shown to the customer as
 * "Sent to supplier") → quoting → offered → ordered (or declined).
 */
enum RequisitionStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Returned = 'returned';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Merged = 'merged';
    case Cancelled = 'cancelled';
    case Received = 'received';
    case Quoting = 'quoting';
    case Offered = 'offered';
    case Ordered = 'ordered';
    case Declined = 'declined';

    /**
     * @return list<self>
     */
    public function allowedTargets(): array
    {
        return match ($this) {
            self::Draft => [self::Submitted, self::Approved, self::Cancelled],
            self::Submitted => [self::Returned, self::Approved, self::Rejected, self::Merged, self::Cancelled],
            self::Returned => [self::Submitted, self::Cancelled],
            self::Approved => [self::Received, self::Returned, self::Merged, self::Cancelled],
            self::Received => [self::Quoting, self::Declined],
            self::Quoting => [self::Offered, self::Declined],
            self::Offered => [self::Ordered, self::Quoting, self::Declined],
            self::Rejected, self::Merged, self::Cancelled, self::Ordered, self::Declined => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTargets(), true);
    }

    /** Lines may still be edited by the client (requester while draft/returned, PM while submitted/approved). */
    public function isClientEditable(): bool
    {
        return in_array($this, [self::Draft, self::Returned, self::Submitted, self::Approved], true);
    }

    /** Visible to us (sent by the customer). */
    public function isWithSupplier(): bool
    {
        return in_array($this, [self::Received, self::Quoting, self::Offered, self::Ordered, self::Declined], true);
    }

    public function isFinal(): bool
    {
        return $this->allowedTargets() === [];
    }

    public function label(): string
    {
        return __('requisitions.statuses.'.$this->value);
    }

    /**
     * @return array{0: string, 1: string}
     */
    public function badge(): array
    {
        return match ($this) {
            self::Draft => ['outline', 'clock'],
            self::Submitted, self::Received => ['red', 'bell'],
            self::Returned => ['danger-outline', 'warning'],
            self::Approved, self::Quoting => ['silver', 'clock'],
            self::Offered => ['silver', 'offers'],
            self::Ordered => ['dark', 'check'],
            self::Rejected, self::Declined, self::Cancelled, self::Merged => ['outline', 'close'],
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
