<?php

namespace App\Actions\Orders;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\StatusTransition;
use App\Models\User;
use App\Services\Orders\Exceptions\OrderActionException;
use Illuminate\Support\Facades\DB;

/**
 * The only place an order's status changes (Spec 06 6.1): checks the allowed-transition map and
 * writes `status_transitions` (who/from/to/when/why) plus the activity log (`status_changed`).
 */
class TransitionOrder
{
    /**
     * @param  array<string, mixed>  $attributes  columns written together with the status
     */
    public function handle(Order $order, OrderStatus $to, ?User $user, ?string $reason = null, array $attributes = []): Order
    {
        $from = $order->status;

        if (! $from->canTransitionTo($to)) {
            throw new OrderActionException('invalid_transition', ['from' => $from->label(), 'to' => $to->label()]);
        }

        return DB::transaction(function () use ($order, $from, $to, $user, $reason, $attributes): Order {
            $order->forceFill([...$attributes, 'status' => $to])->save();
            self::record($order, $from, $to, $user, $reason);

            return $order;
        });
    }

    public static function record(Order $order, ?OrderStatus $from, OrderStatus $to, ?User $user, ?string $reason = null): void
    {
        StatusTransition::query()->create([
            'subject_type' => $order->getMorphClass(),
            'subject_id' => $order->id,
            'from_status' => $from?->value,
            'to_status' => $to->value,
            'user_id' => $user?->id,
            'reason' => $reason,
            'created_at' => now(),
        ]);
    }
}
