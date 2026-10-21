<?php

namespace App\Actions\OrderAmendments;

use App\Authorization\PermissionMap;
use App\Enums\OrderAmendmentStatus;
use App\Models\OrderAmendment;
use App\Models\StatusTransition;
use App\Models\User;
use App\Services\Offers\DiscountRules;
use App\Services\Orders\Exceptions\OrderAmendmentException;
use App\Services\Orders\OrderAmendmentRules;
use Illuminate\Support\Facades\DB;

/**
 * The only place an order amendment's status changes (Spec 06 section 6.4):
 * - submit (requester, `orders.amend` on the order): a discount within the requester's limit needs no
 *   approval, so the amendment is approved and applied at once; a larger one waits for an approver;
 * - approve / reject: a user with `orders.amend` on the order whose discount limit covers the request,
 *   never the requester (maker != checker); approving applies the amendment in the same step;
 * - cancel a draft or waiting amendment: the requester, or an approver while it waits.
 * Reject and cancel need a reason. Every move is written to `status_transitions`.
 */
class TransitionOrderAmendment
{
    public function __construct(protected ApplyOrderAmendment $apply, protected OrderAmendmentRules $rules, protected DiscountRules $discounts) {}

    /** Whether the user may decide (approve / reject) the waiting amendment. */
    public function canDecide(User $user, OrderAmendment $amendment): bool
    {
        return $amendment->status === OrderAmendmentStatus::PendingApproval
            && $amendment->requested_by !== $user->id
            && PermissionMap::allowsFor($user, 'orders.amend', $amendment->order->owner_id)
            && $this->discounts->limitFor($user) >= $amendment->required_discount_bp;
    }

    /** Rejecting needs no discount limit: anyone who may amend the order, except the requester. */
    public function canReject(User $user, OrderAmendment $amendment): bool
    {
        return $amendment->status === OrderAmendmentStatus::PendingApproval
            && $amendment->requested_by !== $user->id
            && PermissionMap::allowsFor($user, 'orders.amend', $amendment->order->owner_id);
    }

    public function canRequest(User $user, OrderAmendment $amendment): bool
    {
        return PermissionMap::allowsFor($user, 'orders.amend', $amendment->order->owner_id);
    }

    public function submit(OrderAmendment $amendment, User $user): OrderAmendment
    {
        return DB::transaction(function () use ($amendment, $user): OrderAmendment {
            $amendment = OrderAmendment::query()->lockForUpdate()->with('order')->findOrFail($amendment->id);
            $this->expect($amendment, OrderAmendmentStatus::PendingApproval);

            if (! $this->canRequest($user, $amendment)) {
                throw new OrderAmendmentException('forbidden');
            }

            if ($amendment->changes === []) {
                throw new OrderAmendmentException('no_changes');
            }

            $order = $amendment->order;
            $this->rules->assertAmendable($order);

            foreach ($amendment->changes as $change) {
                $this->rules->normalize($order, $change);
            }

            $required = (int) collect($amendment->changes)->map(fn (array $change): int => $this->rules->discountOf($change))->max();
            $needs = $required > $this->discounts->limitFor($user);
            $amendment->forceFill(['needs_approval' => $needs, 'required_discount_bp' => $needs ? $required : 0, 'submitted_at' => now()])->save();

            if ($needs) {
                $amendment->forceFill(['status' => OrderAmendmentStatus::PendingApproval])->save();
                self::record($amendment, OrderAmendmentStatus::Draft, OrderAmendmentStatus::PendingApproval, $user);

                return $amendment;
            }

            return $this->approveAndApply($amendment, OrderAmendmentStatus::Draft, null, null, $user);
        });
    }

    public function approve(OrderAmendment $amendment, User $user, ?string $note = null): OrderAmendment
    {
        return DB::transaction(function () use ($amendment, $user, $note): OrderAmendment {
            $amendment = OrderAmendment::query()->lockForUpdate()->with('order')->findOrFail($amendment->id);
            $this->expect($amendment, OrderAmendmentStatus::Approved);
            $this->assertCanDecide($user, $amendment);

            return $this->approveAndApply($amendment, OrderAmendmentStatus::PendingApproval, $user, $note, $user);
        });
    }

    public function reject(OrderAmendment $amendment, User $user, ?string $reason): OrderAmendment
    {
        $reason = $this->reason($reason);

        return DB::transaction(function () use ($amendment, $user, $reason): OrderAmendment {
            $amendment = OrderAmendment::query()->lockForUpdate()->with('order')->findOrFail($amendment->id);
            $this->expect($amendment, OrderAmendmentStatus::Rejected);

            if (! $this->canReject($user, $amendment)) {
                throw new OrderAmendmentException($amendment->requested_by === $user->id ? 'own_amendment' : 'forbidden');
            }

            $amendment->forceFill(['status' => OrderAmendmentStatus::Rejected, 'decided_by' => $user->id, 'decided_at' => now(), 'decision_note' => $reason])->save();
            self::record($amendment, OrderAmendmentStatus::PendingApproval, OrderAmendmentStatus::Rejected, $user, $reason);

            return $amendment;
        });
    }

    public function cancel(OrderAmendment $amendment, User $user, ?string $reason): OrderAmendment
    {
        $reason = $this->reason($reason);

        return DB::transaction(function () use ($amendment, $user, $reason): OrderAmendment {
            $amendment = OrderAmendment::query()->lockForUpdate()->with('order')->findOrFail($amendment->id);
            $from = $amendment->status;
            $this->expect($amendment, OrderAmendmentStatus::Cancelled);

            $allowed = $amendment->requested_by === $user->id
                || ($from === OrderAmendmentStatus::PendingApproval && $this->canReject($user, $amendment));

            if (! $allowed) {
                throw new OrderAmendmentException('forbidden');
            }

            $amendment->forceFill(['status' => OrderAmendmentStatus::Cancelled])->save();
            self::record($amendment, $from, OrderAmendmentStatus::Cancelled, $user, $reason);

            return $amendment;
        });
    }

    protected function approveAndApply(OrderAmendment $amendment, OrderAmendmentStatus $from, ?User $approver, ?string $note, User $applier): OrderAmendment
    {
        $amendment->forceFill(['status' => OrderAmendmentStatus::Approved, 'decided_by' => $approver?->id, 'decided_at' => now(), 'decision_note' => $note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, 500) : null])->save();
        self::record($amendment, $from, OrderAmendmentStatus::Approved, $approver, $approver === null ? 'within_limit' : $note);

        $this->apply->handle($amendment, $applier);
        $amendment->forceFill(['status' => OrderAmendmentStatus::Applied])->save();
        self::record($amendment, OrderAmendmentStatus::Approved, OrderAmendmentStatus::Applied, $applier);

        return $amendment;
    }

    protected function expect(OrderAmendment $amendment, OrderAmendmentStatus $to): void
    {
        $from = $amendment->status;
        $target = $to === OrderAmendmentStatus::PendingApproval ? OrderAmendmentStatus::PendingApproval : $to;

        // A draft is submitted (to pending or straight to approved); only a waiting one is approved or rejected.
        $ok = match ($to) {
            OrderAmendmentStatus::PendingApproval => $from === OrderAmendmentStatus::Draft,
            OrderAmendmentStatus::Approved => $from === OrderAmendmentStatus::PendingApproval,
            default => $from->canTransitionTo($target),
        };

        if (! $ok) {
            throw new OrderAmendmentException('invalid_transition', ['from' => $from->label(), 'to' => $to->label()]);
        }
    }

    protected function assertCanDecide(User $user, OrderAmendment $amendment): void
    {
        if ($this->canDecide($user, $amendment)) {
            return;
        }

        $mayAmend = PermissionMap::allowsFor($user, 'orders.amend', $amendment->order->owner_id);

        if (! $mayAmend) {
            throw new OrderAmendmentException('forbidden');
        }

        throw new OrderAmendmentException($amendment->requested_by === $user->id ? 'own_amendment' : 'approver_limit');
    }

    protected function reason(?string $reason): string
    {
        $reason = $reason !== null ? trim($reason) : '';

        if ($reason === '') {
            throw new OrderAmendmentException('reason_required');
        }

        return mb_substr($reason, 0, 500);
    }

    public static function record(OrderAmendment $amendment, ?OrderAmendmentStatus $from, OrderAmendmentStatus $to, ?User $user, ?string $reason = null): void
    {
        StatusTransition::query()->create([
            'subject_type' => $amendment->getMorphClass(),
            'subject_id' => $amendment->id,
            'from_status' => $from?->value,
            'to_status' => $to->value,
            'user_id' => $user?->id,
            'reason' => $reason,
            'created_at' => now(),
        ]);
    }
}
