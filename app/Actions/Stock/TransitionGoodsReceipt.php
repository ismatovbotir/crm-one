<?php

namespace App\Actions\Stock;

use App\Authorization\PermissionMap;
use App\Enums\GoodsReceiptStatus;
use App\Models\DocumentLineSerial;
use App\Models\GoodsReceipt;
use App\Models\StatusTransition;
use App\Models\User;
use App\Services\OneC\Outbox\OneCOutbox;
use App\Services\Stock\Exceptions\StockActionException;
use Illuminate\Support\Facades\DB;

/**
 * The only place a goods receipt's status changes (Spec 06 section 26.4), except posting
 * (PostGoodsReceipt, which records its transition here). Checks the transition map and who may
 * move it, writes `status_transitions` (the activity log records the change itself). Who moves what:
 * - start, mark checked, reopen, put on hold, post: the stock manager (`stock.receive`);
 * - release from hold, cancel a 1C receipt, post over the tolerance: the warehouse head (`stock.adjust_approve`);
 * - cancel a CRM-created receipt (return, opening balance): the stock manager.
 * A user-cancelled receipt is available for 1C to pull (`goods_receipt.cancelled`).
 */
class TransitionGoodsReceipt
{
    /** Transitions that need a written reason. */
    public const NEEDS_REASON = [GoodsReceiptStatus::OnHold, GoodsReceiptStatus::Cancelled];

    public function __construct(protected PostGoodsReceipt $post, protected OneCOutbox $outbox) {}

    /**
     * Permission key the user needs to move the receipt to `$to` (null: the move is not a user action).
     */
    public static function ability(GoodsReceipt $receipt, GoodsReceiptStatus $to): ?string
    {
        $from = $receipt->status;

        if (! $from->canTransitionTo($to)) {
            return null;
        }

        return match (true) {
            $to === GoodsReceiptStatus::Posted => $receipt->isFromOneC() && $from !== GoodsReceiptStatus::Checked ? null : 'stock.receive',
            $to === GoodsReceiptStatus::Checked => $receipt->isFromOneC() ? 'stock.receive' : null,
            $to === GoodsReceiptStatus::InProgress && $from === GoodsReceiptStatus::OnHold => 'stock.adjust_approve',
            $to === GoodsReceiptStatus::Cancelled => $receipt->isFromOneC() ? 'stock.adjust_approve' : 'stock.receive',
            default => 'stock.receive',
        };
    }

    public static function allows(User $user, GoodsReceipt $receipt, GoodsReceiptStatus $to): bool
    {
        $ability = self::ability($receipt, $to);

        return $ability !== null && PermissionMap::allows($user, $ability);
    }

    public function handle(GoodsReceipt $receipt, GoodsReceiptStatus $to, User $user, ?string $reason = null): GoodsReceipt
    {
        if ($to === GoodsReceiptStatus::Posted) {
            return $this->post->handle($receipt, $user);
        }

        $reason = $reason !== null && trim($reason) !== '' ? mb_substr(trim($reason), 0, 500) : null;

        return DB::transaction(function () use ($receipt, $to, $user, $reason): GoodsReceipt {
            $receipt = GoodsReceipt::query()->lockForUpdate()->findOrFail($receipt->id);
            $from = $receipt->status;

            if (! $from->canTransitionTo($to) || self::ability($receipt, $to) === null) {
                throw new StockActionException('receipt_invalid_transition', ['from' => $from->label(), 'to' => $to->label()]);
            }

            if (! self::allows($user, $receipt, $to)) {
                throw new StockActionException('receipt_transition_forbidden', ['to' => $to->label()]);
            }

            if ($reason === null && in_array($to, self::NEEDS_REASON, true)) {
                throw new StockActionException('receipt_reason_required');
            }

            if ($to === GoodsReceiptStatus::Checked) {
                // Same completeness checks as posting; the head approval is asked for when posting.
                $this->post->guard($receipt, $receipt->lines()->with('item')->get(), $user, false);
            }

            $attributes = ['status' => $to];

            if ($to === GoodsReceiptStatus::Cancelled) {
                DocumentLineSerial::query()->where('document_type', GoodsReceipt::DOCUMENT_TYPE)->where('document_id', $receipt->id)->where('status', DocumentLineSerial::DRAFT)
                    ->update(['status' => DocumentLineSerial::VOIDED, 'active_key' => null]);
                $attributes += ['needs_review' => false, 'review_reason' => null];
            }

            $receipt->forceFill($attributes)->save();
            self::record($receipt, $from, $to, $user, $reason);

            if ($to === GoodsReceiptStatus::Cancelled && $receipt->isFromOneC()) {
                $this->outbox->publishGoodsReceipt($receipt->fresh(), 'goods_receipt.cancelled');
            }

            return $receipt;
        });
    }

    public static function record(GoodsReceipt $receipt, ?GoodsReceiptStatus $from, GoodsReceiptStatus $to, ?User $user, ?string $reason = null): void
    {
        StatusTransition::query()->create([
            'subject_type' => $receipt->getMorphClass(),
            'subject_id' => $receipt->id,
            'from_status' => $from?->value,
            'to_status' => $to->value,
            'user_id' => $user?->id,
            'reason' => $reason,
            'created_at' => now(),
        ]);
    }
}
