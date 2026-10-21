<?php

namespace App\Models;

use App\Enums\LeadCloseReason;
use App\Enums\OfferRejectReason;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use LogicException;

/**
 * Append-only status history of a workflow record (who, from, to, when, why).
 */
#[Fillable(['subject_type', 'subject_id', 'from_status', 'to_status', 'user_id', 'reason', 'created_at'])]
class StatusTransition extends Model
{
    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Status transitions are append-only.'));
        static::deleting(fn (): never => throw new LogicException('Status transitions are append-only.'));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime'];
    }

    /**
     * System reasons are stored as codes ("approval_invalidated", "reject:price",
     * "revision_of:OF-2026-00001") and translated here (offers.history.*); free text as is.
     */
    public function reasonLabel(): ?string
    {
        if ($this->reason === null || $this->reason === '') {
            return null;
        }

        $namespace = match ($this->subject_type) {
            'order' => 'orders.history.',
            'agreement' => 'agreements.history.',
            'tender' => 'tenders.history.',
            'purchase_order' => 'purchase_orders.history.',
            'lead' => 'leads.history.',
            'goods_receipt' => 'stock.receipt_history.',
            'stock_off_act' => 'stock_off.history_reasons.',
            default => 'offers.history.',
        };

        if (preg_match('/^([a-z_]+)(?::(.*))?$/s', $this->reason, $matches) === 1 && trans()->has($namespace.$matches[1])) {
            $value = trim($matches[2] ?? '');

            if ($matches[1] === 'reject') {
                $value = OfferRejectReason::tryFrom($value)?->label() ?? $value;
            }

            if ($matches[1] === 'close' && $namespace === 'leads.history.') {
                [$code, $note] = array_pad(explode(' — ', $value, 2), 2, null);
                $value = (LeadCloseReason::tryFrom((string) $code)?->label() ?? $code).($note !== null ? ' — '.$note : '');
            }

            return __($namespace.$matches[1], ['value' => $value]);
        }

        return $this->reason;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
