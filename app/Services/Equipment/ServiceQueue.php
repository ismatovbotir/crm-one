<?php

namespace App\Services\Equipment;

use App\Enums\ServiceTicketStatus;
use App\Models\GoodsReceipt;
use App\Models\ServiceTicket;
use App\Models\User;
use App\Notifications\ServiceStockArrived;
use App\Services\Equipment\Exceptions\EquipmentException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * "Waiting in queue (out of stock)" (Spec 09 section 15): tickets whose spare part, consumable or
 * replacement unit is not available wait in one queue, FIFO within priority (critical, high,
 * normal, low), with an optional manual order set by staff (with a reason). The customer sees the
 * position and, when staff set one, the estimated date. A posted goods receipt with a needed item
 * alerts the service team about the next queued tickets; statuses never change automatically.
 */
class ServiceQueue
{
    public const URGENCY_ORDER = ['critical', 'high', 'normal', 'low'];

    /**
     * @return Builder<ServiceTicket>
     */
    public function ordered(): Builder
    {
        return ServiceTicket::query()->where('status', ServiceTicketStatus::WaitingForParts->value)
            ->orderByRaw("case urgency when 'critical' then 0 when 'high' then 1 when 'normal' then 2 else 3 end")
            ->orderByRaw('case when queue_rank is null then 1 else 0 end')
            ->orderBy('queue_rank')
            ->orderBy('queued_at')
            ->orderBy('id');
    }

    /** 1-based position in the whole queue, or null when the ticket is not queued. */
    public function position(ServiceTicket $ticket): ?int
    {
        if ($ticket->status !== ServiceTicketStatus::WaitingForParts) {
            return null;
        }

        $index = $this->ordered()->pluck('id')->search($ticket->id);

        return $index === false ? null : $index + 1;
    }

    /**
     * Moves the ticket to a position within its priority group (1 = first) and records why.
     */
    public function reorder(ServiceTicket $ticket, int $position, string $reason, User $user): void
    {
        $reason = trim($reason);

        if ($ticket->status !== ServiceTicketStatus::WaitingForParts) {
            throw new EquipmentException('not_queued');
        }

        if (mb_strlen($reason) < 5) {
            throw new EquipmentException('reorder_reason_required');
        }

        DB::transaction(function () use ($ticket, $position, $reason, $user): void {
            $group = $this->ordered()->where('urgency', $ticket->urgency->value)->lockForUpdate()->pluck('id')->reject(fn (int $id): bool => $id === $ticket->id)->values();
            $position = max(1, min($position, $group->count() + 1));
            $group->splice($position - 1, 0, [$ticket->id]);

            foreach ($group->values() as $rank => $id) {
                ServiceTicket::query()->whereKey($id)->update(['queue_rank' => $rank + 1]);
            }

            $ticket->logActivity('queue_reordered', ['position' => $position, 'reason' => $reason, 'by' => $user->name]);
        });
    }

    /**
     * Goods receipt posted: for each received item that queued tickets need, tell the service team
     * which tickets are next (no status change).
     *
     * @return Collection<int, ServiceTicket> the tickets surfaced
     */
    public function stockArrived(GoodsReceipt $receipt): Collection
    {
        $itemIds = $receipt->lines()->where('received_qty', '>', 0)->pluck('item_id')->filter()->unique()->values();

        if ($itemIds->isEmpty()) {
            return collect();
        }

        $tickets = $this->ordered()->whereIn('queue_item_id', $itemIds)->get();

        if ($tickets->isEmpty()) {
            return $tickets;
        }

        foreach (ServiceTeam::members() as $member) {
            $member->notify(new ServiceStockArrived($receipt, $tickets->take(10)->pluck('number')->all()));
        }

        foreach ($tickets as $ticket) {
            $ticket->logActivity('queue_stock_arrived', ['receipt' => $receipt->number]);
        }

        return $tickets;
    }

    /**
     * Length and oldest wait (dashboard tile).
     *
     * @return array{length: int, oldest_days: int|null}
     */
    public function summary(): array
    {
        $oldest = ServiceTicket::query()->where('status', ServiceTicketStatus::WaitingForParts->value)->min('queued_at');

        return [
            'length' => ServiceTicket::query()->where('status', ServiceTicketStatus::WaitingForParts->value)->count(),
            'oldest_days' => $oldest !== null ? (int) now()->diffInDays($oldest, true) : null,
        ];
    }
}
