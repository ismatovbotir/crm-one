<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Unique bid link of one invitation (Spec 08 8.2, DEC-3): only sha256 of the 256-bit token is
 * stored; one active link per invitation (BidLinks revokes the previous one under a row lock).
 * Usable until revoked or `expires_at` (deadline + grace); submissions only before the deadline.
 *
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $revoked_at
 */
#[Fillable([
    'tender_invitation_id', 'token_hash', 'created_by', 'sent_via', 'sent_to', 'sent_at', 'expires_at', 'revoked_at', 'revoked_by',
    'revoke_reason', 'first_opened_at', 'last_opened_at', 'open_count', 'distinct_ip_count', 'verified_at',
])]
class TenderInvitationLink extends Model
{
    /** @var list<string> */
    protected $hidden = ['token_hash'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sent_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
            'first_opened_at' => 'immutable_datetime',
            'last_opened_at' => 'immutable_datetime',
            'verified_at' => 'immutable_datetime',
            'open_count' => 'integer',
            'distinct_ip_count' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<TenderInvitation, $this>
     */
    public function invitation(): BelongsTo
    {
        return $this->belongsTo(TenderInvitation::class, 'tender_invitation_id');
    }

    /**
     * @return HasMany<TenderLinkEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(TenderLinkEvent::class, 'link_id')->latest('id');
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    /** More than 3 distinct IPs: the board shows "possibly forwarded" (Spec 08 8.2). */
    public function possiblyForwarded(): bool
    {
        return $this->distinct_ip_count > 3;
    }
}
