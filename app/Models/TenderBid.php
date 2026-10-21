<?php

namespace App\Models;

use App\Enums\BidChannel;
use App\Enums\BidStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use LogicException;

/**
 * One version of a sealed bid (Spec 03 7.3, BR-T.4/T.5). Content (lots, lines, amounts,
 * attachments) is readable only through TenderBidPolicy::viewContent: before `opened_at` only by
 * the employee who entered it (Spec 08 BR-B.4), never by an admin. Deliberately not activity
 * logged: amounts must not reach the log before opening; SubmitBid logs metadata on the tender.
 * Never deleted.
 *
 * @property BidStatus $status
 * @property BidChannel $channel
 * @property CarbonImmutable|null $submitted_at
 * @property CarbonImmutable|null $valid_until
 */
#[Fillable([
    'tender_id', 'counterparty_id', 'invitation_id', 'round_no', 'version', 'status', 'channel', 'link_id', 'submission_uuid', 'entered_by', 'currency',
    'valid_until', 'payment_days', 'prepayment_bp', 'subcontracting', 'subcontractor_name', 'notes', 'exclusions',
    'conditions_accepted', 'submitted_at', 'payload_hash', 'needs_confirmation', 'correction_reason',
])]
class TenderBid extends Model
{
    use HasUuids;

    protected static function booted(): void
    {
        static::deleting(fn (): never => throw new LogicException('Bids are never deleted.'));
    }

    /**
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => BidStatus::class,
            'channel' => BidChannel::class,
            'round_no' => 'integer',
            'version' => 'integer',
            'valid_until' => 'immutable_date',
            'payment_days' => 'integer',
            'prepayment_bp' => 'integer',
            'subcontracting' => 'boolean',
            'conditions_accepted' => 'boolean',
            'needs_confirmation' => 'boolean',
            'submitted_at' => 'immutable_datetime',
            'withdrawn_at' => 'immutable_datetime',
            'disqualified_at' => 'immutable_datetime',
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
     * @return BelongsTo<TenderInvitation, $this>
     */
    public function invitation(): BelongsTo
    {
        return $this->belongsTo(TenderInvitation::class, 'invitation_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function enteredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'entered_by');
    }

    /**
     * @return HasOne<TenderBidSource, $this>
     */
    public function source(): HasOne
    {
        return $this->hasOne(TenderBidSource::class, 'bid_id');
    }

    /**
     * @return HasMany<TenderBidLot, $this>
     */
    public function lots(): HasMany
    {
        return $this->hasMany(TenderBidLot::class, 'bid_id')->orderBy('lot_id')->orderBy('variant_no');
    }

    /**
     * @return MorphMany<Attachment, $this>
     */
    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable')->orderBy('id');
    }
}
