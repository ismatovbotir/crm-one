<?php

namespace App\Actions\Orders;

use App\Authorization\PermissionMap;
use App\Models\Order;
use App\Models\User;
use App\Services\Orders\Exceptions\OrderActionException;

/**
 * Power of attorney checkbox of an order (Spec 06 section 28): "power of attorney received" with
 * who ticked it and when plus an optional note; untick with a reason; change whether it is
 * required (with a reason); override the tax-invoice gate with a reason. Every change lands in
 * the order's activity log (attribute changes) with the reason as a separate entry.
 */
class RecordPowerOfAttorney
{
    public function tick(Order $order, User $user, ?string $note = null): Order
    {
        $this->assertOpen($order);

        if (! PermissionMap::allowsFor($user, 'orders.poa_tick', $order->owner_id)) {
            throw new OrderActionException('poa_forbidden');
        }

        $order->forceFill(['poa_received_at' => now(), 'poa_received_by' => $user->id, 'poa_note' => $this->text($note, 255)])->save();

        return $order;
    }

    public function untick(Order $order, User $user, string $reason): Order
    {
        $this->assertOpen($order);

        if (! PermissionMap::allows($user, 'orders.poa_untick')) {
            throw new OrderActionException('poa_forbidden');
        }

        $reason = $this->required($reason);
        $order->forceFill(['poa_received_at' => null, 'poa_received_by' => null])->save();
        $order->logActivity('poa_unticked', ['reason' => $reason]);

        return $order;
    }

    public function setRequired(Order $order, bool $required, User $user, string $reason): Order
    {
        $this->assertOpen($order);

        if (! PermissionMap::allows($user, 'orders.poa_requirement')) {
            throw new OrderActionException('poa_forbidden');
        }

        $reason = $this->required($reason);
        $order->forceFill(['poa_required' => $required])->save();
        $order->logActivity('poa_requirement_changed', ['required' => $required, 'reason' => $reason]);

        return $order;
    }

    /** Lets the tax invoice be registered without the power of attorney (reason required, audited). */
    public function override(Order $order, User $user, string $reason): Order
    {
        $this->assertOpen($order);

        if (! PermissionMap::allows($user, 'orders.poa_override')) {
            throw new OrderActionException('poa_forbidden');
        }

        $order->forceFill(['poa_waived_at' => now(), 'poa_waived_by' => $user->id, 'poa_waiver_reason' => $this->required($reason)])->save();

        return $order;
    }

    protected function assertOpen(Order $order): void
    {
        if ($order->status->isFinal()) {
            throw new OrderActionException('order_final');
        }
    }

    protected function required(string $reason): string
    {
        $reason = trim($reason);

        if (mb_strlen($reason) < 3) {
            throw new OrderActionException('poa_reason_required');
        }

        return mb_substr($reason, 0, 500);
    }

    protected function text(?string $value, int $max): ?string
    {
        $value = $value !== null ? trim($value) : '';

        return $value === '' ? null : mb_substr($value, 0, $max);
    }
}
