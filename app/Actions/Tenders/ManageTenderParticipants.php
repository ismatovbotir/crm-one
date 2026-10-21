<?php

namespace App\Actions\Tenders;

use App\Enums\InvitationStatus;
use App\Enums\TenderStatus;
use App\Enums\TenderType;
use App\Enums\UserRole;
use App\Models\Counterparty;
use App\Models\Tender;
use App\Models\TenderCommitteeMember;
use App\Models\TenderInvitation;
use App\Models\User;
use App\Services\Tenders\Exceptions\TenderActionException;

/**
 * Evaluation committee and invitees (Spec 03 7.3). Invitees: suppliers for purchase tenders,
 * carriers for transport tenders; suspended or blacklisted counterparties cannot be invited.
 * Invitations can be added until the deadline (late invitees still get the minimum period
 * through an addendum); removed only while the tender is a draft. Committee: internal users
 * other than admins; changes until bids are opened; each member declares no conflict of
 * interest before seeing bids (BR-T.9).
 */
class ManageTenderParticipants
{
    public function invite(Tender $tender, Counterparty $counterparty, User $user): TenderInvitation
    {
        if (! in_array($tender->status, [TenderStatus::Draft, TenderStatus::PendingApproval, TenderStatus::Approved, TenderStatus::Published], true)) {
            throw new TenderActionException('invitations_closed');
        }

        if ($tender->status === TenderStatus::Published && $tender->bids_due_at?->isPast()) {
            throw new TenderActionException('deadline_passed');
        }

        if (! $counterparty->status->canBid()) {
            throw new TenderActionException('counterparty_cannot_bid');
        }

        if (($tender->type === TenderType::Transport && ! $counterparty->is_carrier) || ($tender->type === TenderType::Purchase && ! $counterparty->is_supplier)) {
            throw new TenderActionException('counterparty_wrong_role');
        }

        if ($tender->invitations()->where('counterparty_id', $counterparty->id)->exists()) {
            throw new TenderActionException('already_invited');
        }

        $contact = $counterparty->contacts()->first();
        $invitation = $tender->invitations()->create([
            'counterparty_id' => $counterparty->id,
            'invited_by' => $user->id,
            'invited_at' => $tender->status === TenderStatus::Published ? now() : null,
            'status' => InvitationStatus::Invited,
            'channel_preference' => 'employee',
            'contact_email' => $contact?->email ?? $counterparty->email,
            'notice_locale' => $contact?->preferred_locale ?? $counterparty->preferred_locale ?? $tender->notice_locale,
        ]);

        $tender->logActivity('counterparty_invited', ['counterparty' => $counterparty->name]);

        return $invitation;
    }

    public function removeInvitation(TenderInvitation $invitation): void
    {
        if (! $invitation->tender->status->isEditable()) {
            throw new TenderActionException('not_draft');
        }

        $invitation->tender->logActivity('invitation_removed', ['counterparty' => $invitation->counterparty?->name]);
        $invitation->delete();
    }

    public function recordDecline(TenderInvitation $invitation, string $reason): void
    {
        if ($invitation->tender->status !== TenderStatus::Published || $invitation->tender->bids_due_at?->isPast()) {
            throw new TenderActionException('deadline_passed');
        }

        if (trim($reason) === '') {
            throw new TenderActionException('reason_required');
        }

        $invitation->update(['status' => InvitationStatus::Declined, 'declined_at' => now(), 'decline_reason' => $reason]);
        $invitation->tender->logActivity('invitation_declined', ['counterparty' => $invitation->counterparty?->name]);
    }

    public function addCommitteeMember(Tender $tender, User $member, string $role = 'member'): TenderCommitteeMember
    {
        if ($tender->isOpened() || $tender->status->isFinal()) {
            throw new TenderActionException('committee_locked');
        }

        if (! $member->isInternal() || ! $member->is_active || $member->hasRole(UserRole::Admin)) {
            throw new TenderActionException('committee_member_invalid');
        }

        if ($tender->committee()->where('user_id', $member->id)->exists()) {
            throw new TenderActionException('committee_member_exists');
        }

        $row = $tender->committee()->create(['user_id' => $member->id, 'role' => $role === 'chair' ? 'chair' : 'member']);
        $tender->logActivity('committee_member_added', ['user' => $member->name]);

        return $row;
    }

    public function removeCommitteeMember(TenderCommitteeMember $member): void
    {
        $tender = Tender::query()->findOrFail($member->tender_id);

        if ($tender->isOpened()) {
            throw new TenderActionException('committee_locked');
        }

        $tender->logActivity('committee_member_removed', ['user' => $member->user?->name]);
        $member->delete();
    }

    public function declareNoConflict(Tender $tender, User $user): void
    {
        $member = $tender->committee()->where('user_id', $user->id)->first() ?? throw new TenderActionException('not_committee_member');

        if ($member->coi_declared_at === null) {
            $member->update(['coi_declared_at' => now()]);
            $tender->logActivity('coi_declared', ['user' => $user->name]);
        }
    }
}
