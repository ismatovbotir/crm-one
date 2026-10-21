<?php

namespace App\Enums;

/**
 * Tender lifecycle for both types (Spec 03 6.1). Changes only through
 * App\Actions\Tenders\TransitionTender (status_transitions + activity log). Spec 08 adds `bafo`.
 */
enum TenderStatus: string
{
    case Draft = 'draft';
    case PendingApproval = 'pending_approval';
    case Approved = 'approved';
    case Published = 'published';
    case Closed = 'closed';
    case Evaluation = 'evaluation';
    case AwardPendingApproval = 'award_pending_approval';
    case Awarded = 'awarded';
    case Contracted = 'contracted';
    case InExecution = 'in_execution';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    /**
     * @return array<string, list<self>>
     */
    public static function transitions(): array
    {
        return [
            self::Draft->value => [self::PendingApproval, self::Cancelled],
            self::PendingApproval->value => [self::Approved, self::Draft, self::Cancelled],
            self::Approved->value => [self::Published, self::Draft, self::Cancelled],
            self::Published->value => [self::Closed, self::Cancelled],
            self::Closed->value => [self::Evaluation, self::Cancelled],
            self::Evaluation->value => [self::AwardPendingApproval, self::Cancelled],
            self::AwardPendingApproval->value => [self::Awarded, self::Evaluation, self::Cancelled],
            self::Awarded->value => [self::Contracted, self::Evaluation],
            self::Contracted->value => [self::InExecution, self::Completed],
            self::InExecution->value => [self::Completed],
            self::Completed->value => [],
            self::Cancelled->value => [],
        ];
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, self::transitions()[$this->value], true);
    }

    public function isFinal(): bool
    {
        return in_array($this, [self::Completed, self::Cancelled], true);
    }

    /** Header, lots, criteria and invitees are editable only before approval. */
    public function isEditable(): bool
    {
        return $this === self::Draft;
    }

    /** Bids exist and are sealed or opened: published or later, not cancelled before opening. */
    public function acceptsBids(): bool
    {
        return $this === self::Published;
    }

    public function label(): string
    {
        return __('tenders.status.'.$this->value);
    }

    /**
     * @return array{0: string, 1: string|null}
     */
    public function badge(): array
    {
        return match ($this) {
            self::Draft => ['outline', null],
            self::PendingApproval, self::AwardPendingApproval => ['silver', 'clock'],
            self::Approved => ['silver', 'check'],
            self::Published => ['dark', 'clock'],
            self::Closed => ['silver', 'lock'],
            self::Evaluation => ['silver', 'star'],
            self::Awarded, self::Contracted, self::InExecution => ['dark', 'check'],
            self::Completed => ['dark', 'lock'],
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
