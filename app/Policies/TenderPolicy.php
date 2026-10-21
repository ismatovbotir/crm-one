<?php

namespace App\Policies;

use App\Authorization\PermissionMap;
use App\Enums\AwardStatus;
use App\Enums\DepartmentRole;
use App\Enums\TenderStatus;
use App\Enums\TenderType;
use App\Enums\UserRole;
use App\Models\Tender;
use App\Models\TenderAward;
use App\Models\User;
use App\Services\Tenders\ApprovalRouter;
use App\Services\Tenders\TenderAccess;

/**
 * Spec 03 4.10 via config/permissions.php (`tenders.*`) and TenderAccess: department owners
 * manage their tenders, heads/CEO/finance approve through ApprovalRouter (maker ≠ checker),
 * committee members score after declaring no conflict of interest, admins see metadata only
 * (never bid content, never approvals — DEC-10). Tenders are never hard-deleted.
 */
class TenderPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->viewType($user, TenderType::Purchase) || $this->viewType($user, TenderType::Transport);
    }

    public function viewType(User $user, TenderType|string $type): bool
    {
        $type = $type instanceof TenderType ? $type : TenderType::from($type);

        return PermissionMap::allows($user, 'tenders.'.$type->value.'.view');
    }

    public function view(User $user, Tender $tender): bool
    {
        return Tender::query()->whereKey($tender->id)->visibleTo($user)->exists();
    }

    public function create(User $user, TenderType|string $type): bool
    {
        $type = $type instanceof TenderType ? $type : TenderType::from($type);

        return PermissionMap::allows($user, 'tenders.'.$type->value.'.create');
    }

    /** Raise a transport request (draft without owner) from a customer order. */
    public function request(User $user): bool
    {
        return PermissionMap::allows($user, 'tenders.transport.request');
    }

    public function takeOwnership(User $user, Tender $tender): bool
    {
        return $tender->owner_id === null && $tender->status === TenderStatus::Draft && $this->create($user, $tender->type);
    }

    public function update(User $user, Tender $tender): bool
    {
        return $tender->status === TenderStatus::Draft && $tender->owner_id !== null && TenderAccess::canManage($user, $tender);
    }

    public function submit(User $user, Tender $tender): bool
    {
        return $this->update($user, $tender);
    }

    public function approvePublication(User $user, Tender $tender): bool
    {
        if ($tender->status !== TenderStatus::PendingApproval) {
            return false;
        }

        $router = app(ApprovalRouter::class);

        return collect($router->publishLevels($tender)['levels'])->contains(fn ($level): bool => $router->canDecide($user, $tender, $level));
    }

    public function publish(User $user, Tender $tender): bool
    {
        return $tender->status === TenderStatus::Approved && TenderAccess::canManage($user, $tender);
    }

    /** Invitees (until the deadline), declines. */
    public function manageInvitations(User $user, Tender $tender): bool
    {
        return in_array($tender->status, [TenderStatus::Draft, TenderStatus::PendingApproval, TenderStatus::Approved, TenderStatus::Published], true)
            && TenderAccess::canManage($user, $tender);
    }

    public function manageCommittee(User $user, Tender $tender): bool
    {
        return ! $tender->isOpened() && ! $tender->status->isFinal() && TenderAccess::canManage($user, $tender);
    }

    public function manageQuestions(User $user, Tender $tender): bool
    {
        return $tender->status === TenderStatus::Published && TenderAccess::canManage($user, $tender);
    }

    public function addAddendum(User $user, Tender $tender): bool
    {
        return $tender->status === TenderStatus::Published && $tender->bids_due_at?->isFuture() && TenderAccess::canManage($user, $tender);
    }

    /** Enter a bid received off-platform (BR-T.14). */
    public function enterBid(User $user, Tender $tender): bool
    {
        return $tender->status === TenderStatus::Published && $tender->bids_due_at?->isFuture() && TenderAccess::canManage($user, $tender);
    }

    public function close(User $user, Tender $tender): bool
    {
        return $tender->status === TenderStatus::Published && $tender->bids_due_at !== null && ! $tender->bids_due_at->isFuture() && TenderAccess::canManage($user, $tender);
    }

    public function setFx(User $user, Tender $tender): bool
    {
        return in_array($tender->status, [TenderStatus::Closed, TenderStatus::Evaluation], true)
            && ! $user->hasRole(UserRole::Admin)
            && PermissionMap::allows($user, 'tenders.fx.set')
            && $this->view($user, $tender);
    }

    /** Estimates, disqualification, recomputation. */
    public function evaluate(User $user, Tender $tender): bool
    {
        return $tender->status === TenderStatus::Evaluation && TenderAccess::canManage($user, $tender);
    }

    public function score(User $user, Tender $tender): bool
    {
        if ($tender->status !== TenderStatus::Evaluation || $user->hasRole(UserRole::Admin)) {
            return false;
        }

        return $tender->committee()->exists() ? $tender->isCommitteeMember($user) : TenderAccess::canManage($user, $tender);
    }

    public function declareNoConflict(User $user, Tender $tender): bool
    {
        return $tender->isCommitteeMember($user) && ! $tender->status->isFinal();
    }

    /** Bid amounts, lines and evidence after opening (BR-T.5). */
    public function viewBids(User $user, Tender $tender): bool
    {
        return TenderAccess::canViewOpenedBids($user, $tender);
    }

    public function proposeAward(User $user, Tender $tender): bool
    {
        return $tender->status === TenderStatus::Evaluation && TenderAccess::canManage($user, $tender);
    }

    public function decideAward(User $user, Tender $tender): bool
    {
        if ($tender->status !== TenderStatus::AwardPendingApproval) {
            return false;
        }

        $router = app(ApprovalRouter::class);

        return TenderAward::query()->where('tender_id', $tender->id)->where('status', AwardStatus::Proposed)->get()
            ->contains(fn (TenderAward $award): bool => collect($award->pendingLevels())->contains(fn ($level): bool => $router->canDecide($user, $tender, $level, $award)));
    }

    public function recordAcceptance(User $user, Tender $tender): bool
    {
        return $tender->status === TenderStatus::Awarded && TenderAccess::canManage($user, $tender);
    }

    public function execute(User $user, Tender $tender): bool
    {
        return in_array($tender->status, [TenderStatus::Contracted, TenderStatus::InExecution], true) && TenderAccess::canManage($user, $tender);
    }

    /**
     * Before publishing: the owner side; after publishing: the department head or a manager
     * (Spec 03 4.10 "Cancel tender (before / after publish): owner / head").
     */
    public function cancel(User $user, Tender $tender): bool
    {
        if (! $tender->status->canTransitionTo(TenderStatus::Cancelled) || $user->hasRole(UserRole::Admin)) {
            return false;
        }

        if (in_array($tender->status, [TenderStatus::Draft, TenderStatus::PendingApproval, TenderStatus::Approved], true)) {
            return TenderAccess::canManage($user, $tender) || ($tender->owner_id === null && $tender->requested_by === $user->id);
        }

        return $user->hasRole(UserRole::Manager) || TenderAccess::isDepartmentMember($user, $tender, DepartmentRole::Head);
    }

    public function retender(User $user, Tender $tender): bool
    {
        return $tender->status === TenderStatus::Cancelled && $this->create($user, $tender->type);
    }

    public function print(User $user, Tender $tender): bool
    {
        return $this->view($user, $tender);
    }

    public function delete(User $user, Tender $tender): bool
    {
        return false;
    }
}
