<?php

namespace App\Services\Stock;

use App\Enums\SerialStatus;
use App\Models\ItemSerial;
use App\Models\SerialEvent;
use App\Models\User;

/**
 * Writes `serial_events` (Spec 06 21.5): who changed which serial, from/to status and warehouse,
 * by which document line.
 */
class SerialLog
{
    /**
     * @param  array{document_type?: string|null, document_id?: int|null, line_id?: int|null, customer_id?: int|null, reason?: string|null, import_batch_id?: int|null, warehouse_from_id?: int|null}  $context
     */
    public function record(ItemSerial $serial, string $event, ?SerialStatus $from, ?User $causer, array $context = []): void
    {
        SerialEvent::query()->create([
            'item_serial_id' => $serial->id,
            'event' => $event,
            'from_status' => $from?->value,
            'to_status' => $serial->status->value,
            'warehouse_from_id' => $context['warehouse_from_id'] ?? null,
            'warehouse_to_id' => $serial->warehouse_id,
            'document_type' => $context['document_type'] ?? null,
            'document_id' => $context['document_id'] ?? null,
            'line_id' => $context['line_id'] ?? null,
            'customer_id' => $context['customer_id'] ?? null,
            'reason' => $context['reason'] ?? null,
            'causer_id' => $causer?->id,
            'import_batch_id' => $context['import_batch_id'] ?? null,
            'created_at' => now(),
        ]);
    }
}
