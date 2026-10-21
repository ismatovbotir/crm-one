<?php

namespace App\Actions\StockOff;

use App\Enums\SerialStatus;
use App\Enums\StockOffReason;
use App\Enums\StockOffStatus;
use App\Models\Attachment;
use App\Models\Item;
use App\Models\ItemSerial;
use App\Models\ItemStock;
use App\Models\NumberSequence;
use App\Models\StockOffAct;
use App\Models\StockOffLine;
use App\Models\StockOffLineSerial;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Accounting\AccountingPeriods;
use App\Services\Attachments\StoreAttachment;
use App\Services\Stock\Exceptions\StockOffException;
use App\Services\Stock\SerialNormalizer;
use App\Support\BusinessSettings;
use App\Support\Money;
use App\Support\Quantity;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Stock-off act drafts (Spec 06 section 27): create, header, lines (item + quantity, or picked
 * serials for serial-tracked items), evidence files, estimated value from the warehouse stock cost
 * and the approval level (warehouse head, or CEO / finance from the value limit). Supplier claim
 * fields follow up receipt damage in any status except cancelled.
 */
class ManageStockOffAct
{
    /** Serial states that may be written off (on hand, not sold or reserved). */
    public const PICKABLE = [SerialStatus::Available, SerialStatus::Quarantined, SerialStatus::Returned];

    /**
     * @param  array{warehouse_id: int, reason: string, note?: string|null, goods_receipt_id?: int|null, supplier_partner_id?: int|null}  $data
     */
    public function create(array $data, User $user): StockOffAct
    {
        $reason = StockOffReason::tryFrom($data['reason']) ?? throw new StockOffException('reason_required');
        $warehouse = Warehouse::query()->findOrFail($data['warehouse_id']);

        return DB::transaction(function () use ($data, $user, $reason, $warehouse): StockOffAct {
            $year = (int) AccountingPeriods::today()->year;
            $act = StockOffAct::query()->create([
                'number' => NumberSequence::format('SO', $year, NumberSequence::next('SO', $year)),
                'status' => StockOffStatus::Draft,
                'reason' => $reason,
                'note' => $this->text($data['note'] ?? null),
                'warehouse_id' => $warehouse->id,
                'goods_receipt_id' => $data['goods_receipt_id'] ?? null,
                'supplier_partner_id' => $data['supplier_partner_id'] ?? null,
                'claim_status' => 'none',
                'created_by' => $user->id,
            ]);
            TransitionStockOffAct::record($act, null, StockOffStatus::Draft, $user, isset($data['goods_receipt_id']) ? 'from_receipt' : null);

            return $act;
        });
    }

    /**
     * @param  array{reason?: string, note?: string|null}  $data
     */
    public function updateHeader(StockOffAct $act, array $data): StockOffAct
    {
        $this->assertDraft($act);
        $attributes = [];

        if (array_key_exists('reason', $data)) {
            $attributes['reason'] = StockOffReason::tryFrom((string) $data['reason']) ?? throw new StockOffException('reason_required');
        }

        if (array_key_exists('note', $data)) {
            $attributes['note'] = $this->text($data['note']);
        }

        $act->forceFill($attributes)->save();

        return $act;
    }

    /** Non-serialized: the quantity; serial-tracked items get their quantity from picked serials. */
    public function addLine(StockOffAct $act, Item $item, string $quantity, ?string $note = null, ?int $receiptLineId = null): StockOffLine
    {
        $this->assertDraft($act);
        $serialized = (bool) $item->is_serialized;
        $qty = $serialized ? '0' : $this->quantity($quantity);

        return DB::transaction(function () use ($act, $item, $qty, $note, $serialized, $receiptLineId): StockOffLine {
            $line = $act->lines()->create([
                'position' => (int) $act->lines()->max('position') + 1,
                'item_id' => $item->id,
                'is_serialized' => $serialized,
                'quantity' => $qty,
                'receipt_line_id' => $receiptLineId,
                'note' => $note !== null ? mb_substr(trim($note), 0, 500) : null,
            ]);
            $this->revalue($act);

            return $line;
        });
    }

    public function updateQuantity(StockOffLine $line, string $quantity): StockOffLine
    {
        $this->assertDraft($line->act);

        if ($line->is_serialized) {
            throw new StockOffException('quantity_from_serials');
        }

        $line->forceFill(['quantity' => $this->quantity($quantity)])->save();
        $this->revalue($line->act);

        return $line;
    }

    public function removeLine(StockOffLine $line): void
    {
        $this->assertDraft($line->act);

        DB::transaction(function () use ($line): void {
            $act = $line->act;
            $line->serials()->delete();
            $line->delete();
            $this->revalue($act);
        });
    }

    /**
     * Picks serials of the line's item at the act warehouse (available, quarantined or returned).
     *
     * @param  list<string>  $inputs
     * @return list<array{serial: string, result: string, message: string}>
     */
    public function addSerials(StockOffLine $line, array $inputs): array
    {
        $act = $line->act;
        $this->assertDraft($act);

        if (! $line->is_serialized) {
            throw new StockOffException('line_not_serialized');
        }

        $normalizer = app(SerialNormalizer::class);
        $results = [];

        foreach ($inputs as $input) {
            $normalized = $normalizer->normalize($input);
            $serial = $normalized['value'] ?? null;

            if ($normalized['error'] !== null || $serial === null) {
                $results[] = ['serial' => trim($input), 'result' => 'invalid', 'message' => __('stock_off.results.invalid')];

                continue;
            }

            $itemSerial = ItemSerial::query()->where('item_id', $line->item_id)->where('serial_normalized', $serial)->first();
            $result = match (true) {
                $itemSerial === null => 'not_found',
                ! in_array($itemSerial->status, self::PICKABLE, true) => 'not_on_hand',
                $itemSerial->warehouse_id !== $act->warehouse_id => 'wrong_warehouse',
                default => 'ok',
            };

            if ($result === 'ok') {
                try {
                    // Savepoint: a taken serial must not abort an outer transaction.
                    DB::transaction(fn () => StockOffLineSerial::query()->create([
                        'line_id' => $line->id,
                        'item_serial_id' => $itemSerial->id,
                        'serial_number' => $serial,
                        'active_key' => StockOffLineSerial::activeKey($itemSerial->id),
                        'created_at' => now(),
                    ]));
                } catch (UniqueConstraintViolationException) {
                    $result = 'on_other_act';
                }
            }

            $results[] = ['serial' => $serial, 'result' => $result, 'message' => $result === 'ok' ? '' : __('stock_off.results.'.$result, ['status' => $itemSerial?->status->label() ?? ''])];
        }

        $this->syncSerialQuantity($line);

        return $results;
    }

    public function removeSerial(StockOffLineSerial $row): void
    {
        $line = $row->line;
        $this->assertDraft($line->act);
        $row->delete();
        $this->syncSerialQuantity($line);
    }

    public function attachEvidence(StockOffAct $act, UploadedFile $file, User $user): Attachment
    {
        if (! $act->status->isOpen()) {
            throw new StockOffException('act_locked');
        }

        $attachment = app(StoreAttachment::class)->handle($file, $act, 'stock_off_evidence', $user, BusinessSettings::int('stock_off.evidence_max_kb'));
        $act->logActivity('evidence_added', ['file' => $attachment->original_name]);

        return $attachment;
    }

    /**
     * Supplier claim for receipt damage: open (with reference), settled or rejected. Allowed on
     * posted acts too (the claim follows the write-off), never on cancelled ones.
     *
     * @param  array{claim_status: string, claim_ref?: string|null, claim_note?: string|null}  $data
     */
    public function updateClaim(StockOffAct $act, array $data): StockOffAct
    {
        if ($act->status === StockOffStatus::Cancelled || ! in_array($data['claim_status'], StockOffAct::CLAIM_STATUSES, true)) {
            throw new StockOffException('claim_invalid');
        }

        $ref = $this->text($data['claim_ref'] ?? $act->claim_ref, 60);

        if ($data['claim_status'] !== 'none' && $ref === null) {
            throw new StockOffException('claim_ref_required');
        }

        $act->forceFill([
            'claim_status' => $data['claim_status'],
            'claim_ref' => $ref,
            'claim_note' => $this->text($data['claim_note'] ?? $act->claim_note),
            'claim_settled_at' => in_array($data['claim_status'], ['settled', 'rejected'], true) ? ($act->claim_settled_at ?? now()) : null,
        ])->save();

        return $act;
    }

    /**
     * Estimated value per line from the warehouse stock cost (1C push: total cost of the quantity),
     * totals per currency and the approval level against the value limit.
     */
    public function revalue(StockOffAct $act): void
    {
        $totals = [];

        foreach ($act->lines()->get() as $line) {
            $stock = ItemStock::query()->where('item_id', $line->item_id)->where('warehouse_id', $act->warehouse_id)->first();
            $unit = null;
            $currency = null;

            if ($stock !== null && $stock->cost_minor !== null && bccomp((string) $stock->quantity, '0', Quantity::SCALE) > 0) {
                $currency = (string) ($stock->cost_currency ?: config('crm.base_currency', 'UZS'));
                // Half-up division of the total cost by the quantity (integers only).
                $unit = (int) bcdiv(bcadd(bcmul((string) $stock->cost_minor, '2'), (string) $stock->quantity, Quantity::SCALE), bcmul((string) $stock->quantity, '2', Quantity::SCALE), 0);
            }

            $value = $unit !== null ? Money::of($unit, (string) $currency)->multiplyByQty((string) $line->quantity)->amount : null;
            $line->forceFill(['unit_cost_minor' => $unit, 'value_minor' => $value, 'currency' => $currency])->save();

            if ($value !== null) {
                $totals[(string) $currency] = ($totals[(string) $currency] ?? 0) + $value;
            }
        }

        $limits = BusinessSettings::array('stock_off.high_approval_from_minor');
        $high = collect($totals)->contains(fn (int $total, string $currency): bool => isset($limits[$currency]) && $total >= (int) $limits[$currency]);
        $act->forceFill(['totals' => $totals === [] ? null : $totals, 'approval_level' => $high ? 'high' : 'head'])->save();
    }

    protected function syncSerialQuantity(StockOffLine $line): void
    {
        $line->forceFill(['quantity' => Quantity::toStorage((string) $line->serials()->count())])->save();
        $this->revalue($line->act);
    }

    public function assertDraft(StockOffAct $act): void
    {
        if (! $act->status->isEditable()) {
            throw new StockOffException('act_locked');
        }
    }

    protected function quantity(string $value): string
    {
        $value = Quantity::normalize($value);

        if (! Quantity::isDecimal($value) || bccomp($value, '0', Quantity::SCALE) <= 0) {
            throw new StockOffException('quantity_invalid');
        }

        return Quantity::toStorage($value);
    }

    protected function text(mixed $value, int $max = 2000): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        return $value === '' ? null : mb_substr($value, 0, $max);
    }
}
