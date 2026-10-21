<?php

namespace App\Models;

use App\Enums\DunningStage;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One payment reminder sent for a schedule item at a stage over a channel (Spec 06 7.14). The unique
 * (schedule item, stage, channel) key makes the nightly run idempotent.
 *
 * @property DunningStage $stage
 */
#[Fillable(['schedule_item_id', 'order_id', 'customer_id', 'stage', 'channel', 'recipient', 'locale', 'amount_minor', 'currency', 'sent_at'])]
class DunningEvent extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['stage' => DunningStage::class, 'amount_minor' => 'integer', 'sent_at' => 'immutable_datetime'];
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<PaymentScheduleItem, $this>
     */
    public function scheduleItem(): BelongsTo
    {
        return $this->belongsTo(PaymentScheduleItem::class, 'schedule_item_id');
    }
}
