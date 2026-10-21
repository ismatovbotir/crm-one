<?php

namespace App\Models;

use App\Enums\InvitationStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Invited supplier/carrier (Spec 03 7.3, Spec 08 6.3): one per tender and counterparty; status
 * follows bid events (submitted, withdrawn, declined, no_response at close).
 *
 * @property InvitationStatus $status
 */
#[Fillable([
    'tender_id', 'counterparty_id', 'invited_by', 'invited_at', 'status', 'channel_preference', 'contact_email', 'notice_locale',
    'first_viewed_at', 'declined_at', 'decline_reason', 'needs_addendum_confirmation',
])]
class TenderInvitation extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => InvitationStatus::class,
            'invited_at' => 'immutable_datetime',
            'first_viewed_at' => 'immutable_datetime',
            'declined_at' => 'immutable_datetime',
            'needs_addendum_confirmation' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Tender, $this>
     */
    public function tender(): BelongsTo
    {
        return $this->belongsTo(Tender::class)->withTrashed();
    }

    /**
     * @return BelongsTo<Counterparty, $this>
     */
    public function counterparty(): BelongsTo
    {
        return $this->belongsTo(Counterparty::class)->withTrashed();
    }

    /**
     * @return HasMany<TenderInvitationLink, $this>
     */
    public function links(): HasMany
    {
        return $this->hasMany(TenderInvitationLink::class)->latest('id');
    }

    public function activeLink(): ?TenderInvitationLink
    {
        return $this->links->first(fn (TenderInvitationLink $link): bool => $link->isActive());
    }

    /**
     * @return HasMany<TenderBid, $this>
     */
    public function bids(): HasMany
    {
        return $this->hasMany(TenderBid::class, 'invitation_id')->orderBy('round_no')->orderBy('version');
    }
}
