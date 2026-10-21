<?php

namespace App\Models;

use App\Enums\AssetStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only history of a customer asset (Spec 09 §3.2): who, when, what, comment.
 */
#[Fillable(['customer_asset_id', 'event', 'from_status', 'to_status', 'comment', 'service_ticket_id', 'user_id', 'created_at'])]
class AssetEvent extends Model
{
    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['from_status' => AssetStatus::class, 'to_status' => AssetStatus::class, 'created_at' => 'immutable_datetime'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<ServiceTicket, $this>
     */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(ServiceTicket::class, 'service_ticket_id');
    }

    public function label(): string
    {
        return __('service.events.'.$this->event);
    }
}
