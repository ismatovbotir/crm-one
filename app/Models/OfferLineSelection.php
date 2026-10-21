<?php

namespace App\Models;

use App\Enums\SelectionChannel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Current choice: one row per selected option line (Spec 05 7.5, DEC-4).
 *
 * @property SelectionChannel $channel
 */
#[Fillable(['offer_id', 'option_group_id', 'offer_line_id', 'selected_by_actor', 'selected_by_user_id', 'channel', 'selected_at'])]
class OfferLineSelection extends Model
{
    public const ACTOR_INTERNAL = 'internal';

    /** Chosen by the customer in the portal (Spec 03 P-4). */
    public const ACTOR_CUSTOMER = 'customer';

    public const ACTOR_CARRY_OVER = 'carry_over';

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'channel' => SelectionChannel::class,
            'selected_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<OfferLine, $this>
     */
    public function line(): BelongsTo
    {
        return $this->belongsTo(OfferLine::class, 'offer_line_id');
    }
}
