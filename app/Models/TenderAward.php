<?php

namespace App\Models;

use App\Enums\ApprovalLevel;
use App\Enums\AwardStatus;
use App\Support\Money;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Award of one lot (Spec 03 BR-T.10/T.11): proposed with the routed approval levels
 * (`required_levels`, AwardRouter), approved when every level approved, accepted by the winner
 * (→ purchase order for purchase tenders, exactly one per award). `active_lot_id` keeps one active
 * award per lot at the database level.
 *
 * @property AwardStatus $status
 * @property list<string> $required_levels
 */
#[Fillable([
    'tender_id', 'lot_id', 'bid_id', 'bid_lot_id', 'comparison_id', 'counterparty_id', 'amount_minor', 'currency', 'landed_cost_minor',
    'evaluation_currency', 'rank_at_proposal', 'justification', 'status', 'required_levels', 'active_lot_id', 'proposed_by',
    'proposed_at', 'approved_at', 'accept_due_at', 'accepted_at', 'declined_at', 'decline_reason', 'acceptance_note',
    'acceptance_recorded_by',
])]
class TenderAward extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => AwardStatus::class,
            'required_levels' => 'array',
            'amount_minor' => 'integer',
            'landed_cost_minor' => 'integer',
            'rank_at_proposal' => 'integer',
            'proposed_at' => 'immutable_datetime',
            'approved_at' => 'immutable_datetime',
            'accept_due_at' => 'immutable_datetime',
            'accepted_at' => 'immutable_datetime',
            'declined_at' => 'immutable_datetime',
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
     * @return BelongsTo<TenderLot, $this>
     */
    public function lot(): BelongsTo
    {
        return $this->belongsTo(TenderLot::class, 'lot_id');
    }

    /**
     * @return BelongsTo<TenderBid, $this>
     */
    public function bid(): BelongsTo
    {
        return $this->belongsTo(TenderBid::class, 'bid_id');
    }

    /**
     * @return BelongsTo<TenderBidLot, $this>
     */
    public function bidLot(): BelongsTo
    {
        return $this->belongsTo(TenderBidLot::class, 'bid_lot_id');
    }

    /**
     * @return BelongsTo<Counterparty, $this>
     */
    public function counterparty(): BelongsTo
    {
        return $this->belongsTo(Counterparty::class)->withTrashed();
    }

    /**
     * @return BelongsTo<TenderComparison, $this>
     */
    public function comparison(): BelongsTo
    {
        return $this->belongsTo(TenderComparison::class, 'comparison_id');
    }

    /**
     * @return HasMany<TenderDecision, $this>
     */
    public function decisions(): HasMany
    {
        return $this->hasMany(TenderDecision::class, 'award_id')->orderBy('id');
    }

    /**
     * @return HasOne<PurchaseOrder, $this>
     */
    public function purchaseOrder(): HasOne
    {
        return $this->hasOne(PurchaseOrder::class, 'tender_award_id');
    }

    /**
     * Levels still waiting for an approval in the current proposal round.
     *
     * @return list<ApprovalLevel>
     */
    public function pendingLevels(): array
    {
        $approved = $this->decisions()->where('decision', 'approve')->toBase()->pluck('level')->all();

        return array_values(array_map(
            fn (string $level): ApprovalLevel => ApprovalLevel::from($level),
            array_filter($this->required_levels, fn (string $level): bool => ! in_array($level, $approved, true)),
        ));
    }

    public function money(): Money
    {
        return Money::of($this->amount_minor, $this->currency);
    }
}
