<?php

namespace App\Models;

use App\Enums\ScheduleItemKind;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One instalment of an order's payment schedule (Spec 06 7.7). Superseded, never deleted.
 *
 * @property ScheduleItemKind $kind
 * @property CarbonImmutable|null $due_on
 */
#[Fillable(['order_id', 'agreement_id', 'kind', 'label', 'delivery_id', 'percent_bp', 'amount_minor', 'currency', 'due_on', 'position', 'created_by'])]
class PaymentScheduleItem extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => ScheduleItemKind::class,
            'amount_minor' => 'integer',
            'percent_bp' => 'integer',
            'position' => 'integer',
            'due_on' => 'immutable_date',
            'superseded_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @param  Builder<PaymentScheduleItem>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull($query->qualifyColumn('superseded_at'));
    }
}
