<?php

namespace App\Services\Orders;

use App\Enums\HoldReason;
use App\Models\Order;
use App\Models\User;

/**
 * Order hold flags (Spec 06 6.2, DEC-7): `hold_reasons` json [{reason, set_at, overridden_by,
 * overridden_at, override_reason}]. An override is per order and reason and stays in force;
 * `on_hold` mirrors "a non-overridden hold exists". Callers save the order.
 */
class OrderHolds
{
    /** Adds the hold unless it is already present (active or overridden). Returns true when active. */
    public function set(Order $order, HoldReason $reason): bool
    {
        $holds = $order->hold_reasons ?? [];

        foreach ($holds as $hold) {
            if ($hold['reason'] === $reason->value) {
                return empty($hold['overridden_at']);
            }
        }

        $holds[] = ['reason' => $reason->value, 'set_at' => now()->toIso8601String(), 'overridden_by' => null, 'overridden_at' => null, 'override_reason' => null];
        $this->store($order, $holds);

        return true;
    }

    /** Removes an active (not overridden) hold; overrides stay as history. */
    public function clear(Order $order, HoldReason $reason): void
    {
        $holds = array_values(array_filter($order->hold_reasons ?? [], fn (array $hold): bool => $hold['reason'] !== $reason->value || ! empty($hold['overridden_at'])));
        $this->store($order, $holds);
    }

    public function isActive(Order $order, HoldReason $reason): bool
    {
        return in_array($reason, $order->activeHolds(), true);
    }

    public function isOverridden(Order $order, HoldReason $reason): bool
    {
        foreach ($order->hold_reasons ?? [] as $hold) {
            if ($hold['reason'] === $reason->value && ! empty($hold['overridden_at'])) {
                return true;
            }
        }

        return false;
    }

    public function override(Order $order, HoldReason $reason, User $user, string $text): void
    {
        $holds = $order->hold_reasons ?? [];
        $found = false;

        foreach ($holds as $index => $hold) {
            if ($hold['reason'] === $reason->value) {
                $holds[$index] = [...$hold, 'overridden_by' => $user->id, 'overridden_at' => now()->toIso8601String(), 'override_reason' => $text];
                $found = true;
            }
        }

        if (! $found) {
            $holds[] = ['reason' => $reason->value, 'set_at' => now()->toIso8601String(), 'overridden_by' => $user->id, 'overridden_at' => now()->toIso8601String(), 'override_reason' => $text];
        }

        $this->store($order, $holds);
    }

    /**
     * @param  list<array<string, mixed>>  $holds
     */
    protected function store(Order $order, array $holds): void
    {
        $order->hold_reasons = $holds === [] ? null : $holds;
        $order->on_hold = $order->activeHolds() !== [];
    }
}
