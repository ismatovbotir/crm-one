<?php

namespace App\Actions\OrderAmendments;

use App\Enums\OrderAmendmentStatus;
use App\Enums\OrderAmendmentType;
use App\Models\Order;
use App\Models\OrderAmendment;
use App\Models\User;
use App\Services\Orders\Exceptions\OrderAmendmentException;
use App\Services\Orders\OrderAmendmentRules;
use Illuminate\Support\Facades\DB;

/**
 * Order amendment drafts (Spec 06 section 6.4): start one for a started order (one open amendment
 * per order), add changes (checked against the order as it is now), remove a change, change the
 * reason. Submitting, approving and applying are in TransitionOrderAmendment.
 */
class ManageOrderAmendment
{
    public function __construct(protected OrderAmendmentRules $rules) {}

    public function start(Order $order, User $user, string $reason): OrderAmendment
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new OrderAmendmentException('reason_required');
        }

        return DB::transaction(function () use ($order, $user, $reason): OrderAmendment {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            $this->rules->assertAmendable($order);

            $open = $order->amendments()->get()->first(fn (OrderAmendment $amendment): bool => $amendment->status->isOpen());

            if ($open !== null) {
                throw new OrderAmendmentException('open_amendment_exists', ['number' => $open->number]);
            }

            $amendment = OrderAmendment::query()->create([
                'order_id' => $order->id,
                'number' => (int) OrderAmendment::query()->where('order_id', $order->id)->max('number') + 1,
                'status' => OrderAmendmentStatus::Draft,
                'changes' => [],
                'reason' => mb_substr($reason, 0, 500),
                'requested_by' => $user->id,
            ]);
            TransitionOrderAmendment::record($amendment, null, OrderAmendmentStatus::Draft, $user);

            return $amendment;
        });
    }

    public function updateReason(OrderAmendment $amendment, string $reason): OrderAmendment
    {
        $this->assertDraft($amendment);

        if (trim($reason) === '') {
            throw new OrderAmendmentException('reason_required');
        }

        $amendment->forceFill(['reason' => mb_substr(trim($reason), 0, 500)])->save();

        return $amendment;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function addChange(OrderAmendment $amendment, array $input): OrderAmendment
    {
        $this->assertDraft($amendment);

        return DB::transaction(function () use ($amendment, $input): OrderAmendment {
            $amendment = OrderAmendment::query()->lockForUpdate()->findOrFail($amendment->id);
            $this->assertDraft($amendment);
            $change = $this->rules->normalize($amendment->order, $input);
            $changes = $amendment->changes;

            if ($change['type'] !== OrderAmendmentType::AddLine->value && collect($changes)->contains(fn (array $stored): bool => ($stored['order_line_id'] ?? null) === $change['order_line_id'])) {
                throw new OrderAmendmentException('line_already_changed', ['line' => (string) $change['label']]);
            }

            $changes[] = $change;
            $amendment->forceFill(['changes' => array_values($changes)])->save();

            return $amendment;
        });
    }

    public function removeChange(OrderAmendment $amendment, int $index): OrderAmendment
    {
        $this->assertDraft($amendment);
        $changes = $amendment->changes;

        if (! isset($changes[$index])) {
            throw new OrderAmendmentException('change_not_found');
        }

        unset($changes[$index]);
        $amendment->forceFill(['changes' => array_values($changes)])->save();

        return $amendment;
    }

    protected function assertDraft(OrderAmendment $amendment): void
    {
        if ($amendment->status !== OrderAmendmentStatus::Draft) {
            throw new OrderAmendmentException('not_editable');
        }
    }
}
