<?php

namespace App\Enums;

/**
 * Service (repair) ticket workflow (Spec 09 §5.2). "Out of stock" of a spare part or replacement
 * unit is `waiting_for_parts` (interpretation I-3 a).
 */
enum ServiceTicketStatus: string
{
    case New = 'new';
    case Accepted = 'accepted';
    case Diagnosing = 'diagnosing';
    case WaitingForParts = 'waiting_for_parts';
    case InRepair = 'in_repair';
    case Ready = 'ready';
    case Closed = 'closed';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    /**
     * @return list<self>
     */
    public function allowedTargets(): array
    {
        return match ($this) {
            self::New => [self::Accepted, self::Rejected, self::Cancelled],
            self::Accepted => [self::Diagnosing, self::Rejected, self::Cancelled],
            self::Diagnosing => [self::WaitingForParts, self::InRepair, self::Ready, self::Rejected],
            self::WaitingForParts => [self::InRepair, self::Diagnosing],
            self::InRepair => [self::Ready, self::WaitingForParts],
            self::Ready => [self::Closed],
            self::Closed, self::Rejected, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTargets(), true);
    }

    public function isOpen(): bool
    {
        return ! in_array($this, [self::Closed, self::Rejected, self::Cancelled], true);
    }

    /** The customer may cancel before the service team started working on the unit. */
    public function cancellableByCustomer(): bool
    {
        return $this === self::New || $this === self::Accepted;
    }

    /**
     * @return list<string>
     */
    public static function openValues(): array
    {
        return array_values(array_map(fn (self $s): string => $s->value, array_filter(self::cases(), fn (self $s): bool => $s->isOpen())));
    }

    public function label(): string
    {
        return __('service.ticket_statuses.'.$this->value);
    }

    /**
     * @return array{0: string, 1: string}
     */
    public function badge(): array
    {
        return match ($this) {
            self::New => ['red', 'bell'],
            self::Accepted, self::Diagnosing => ['outline', 'clock'],
            self::WaitingForParts => ['danger-outline', 'warning'],
            self::InRepair => ['outline', 'settings'],
            self::Ready => ['silver', 'check'],
            self::Closed => ['dark', 'check'],
            self::Rejected, self::Cancelled => ['outline', 'close'],
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
