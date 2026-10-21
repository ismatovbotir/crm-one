<?php

namespace App\Actions\Stock;

use App\Enums\SerialStatus;
use App\Models\ItemSerial;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Stock\Exceptions\StockActionException;
use App\Services\Stock\SerialLog;
use App\Services\Stock\SerialNormalizer;
use App\Support\BusinessSettings;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Item page serial tools (Spec 06 section 21.10): typo correction of a serial number, its warehouse,
 * warranty date and note, and moving several available serials to another warehouse. Only serials
 * that are available or quarantined can be edited (sold/reserved serials need a stock adjustment);
 * every change needs a reason, is normalized and uniqueness-checked (BR-SN), and writes a
 * `serial_events` row (`corrected` / `transferred`) plus an audit entry on the item. Stock roles
 * only (serials.register; admins stay without it, SoD).
 */
class CorrectSerial
{
    public const EDITABLE = [SerialStatus::Available, SerialStatus::Quarantined];

    public function __construct(protected SerialNormalizer $normalizer, protected SerialLog $log) {}

    /**
     * @param  array{serial?: string|null, warehouse_id?: int|null, warranty_until?: string|null, note?: string|null}  $data
     */
    public function handle(ItemSerial $serial, array $data, string $reason, User $user): ItemSerial
    {
        $this->authorize($user);
        $reason = $this->reason($reason);

        try {
            return DB::transaction(function () use ($serial, $data, $reason, $user): ItemSerial {
                $fresh = ItemSerial::query()->lockForUpdate()->findOrFail($serial->id);

                if (! in_array($fresh->status, self::EDITABLE, true)) {
                    throw new StockActionException('serial_not_editable', ['status' => $fresh->status->label()]);
                }

                $old = ['serial' => $fresh->serial_normalized, 'warehouse_id' => $fresh->warehouse_id, 'warranty_until' => $fresh->warranty_until?->toDateString()];
                $changes = [];

                if (filled($data['serial'] ?? null)) {
                    $normalized = $this->normalizer->normalize((string) $data['serial']);

                    if ($normalized['error'] !== null) {
                        throw new StockActionException('serial_invalid', ['detail' => __('stock.results.'.$normalized['error'])]);
                    }

                    if ($normalized['value'] !== $fresh->serial_normalized) {
                        $this->assertUnique($fresh, $normalized['value']);
                        $global = BusinessSettings::bool('serials.global_uniqueness');
                        $changes += ['serial_normalized' => $normalized['value'], 'serial_raw' => $normalized['raw'], 'global_key' => $global ? $normalized['value'] : $fresh->global_key];
                    }
                }

                if (array_key_exists('warehouse_id', $data) && $data['warehouse_id'] !== null && (int) $data['warehouse_id'] !== $fresh->warehouse_id) {
                    Warehouse::query()->findOrFail((int) $data['warehouse_id']);
                    $changes['warehouse_id'] = (int) $data['warehouse_id'];
                }

                if (array_key_exists('warranty_until', $data) && (string) ($data['warranty_until'] ?? '') !== (string) $old['warranty_until']) {
                    $changes['warranty_until'] = filled($data['warranty_until']) ? (string) $data['warranty_until'] : null;
                }

                $note = filled($data['note'] ?? null) ? trim((string) $data['note']) : null;

                if ($changes === [] && $note === null) {
                    throw new StockActionException('serial_nothing_changed');
                }

                $fresh->forceFill($changes)->save();
                $this->log->record($fresh, 'corrected', $fresh->status, $user, ['reason' => mb_substr($reason.($note !== null ? ' — '.$note : ''), 0, 500), 'warehouse_from_id' => $old['warehouse_id']]);
                $fresh->item?->logActivity('serial_corrected', ['old' => $old, 'new' => array_intersect_key(['serial' => $fresh->serial_normalized, 'warehouse_id' => $fresh->warehouse_id, 'warranty_until' => $fresh->warranty_until?->toDateString()], array_flip(array_keys($old))), 'reason' => $reason, 'by' => $user->name]);

                return $fresh;
            });
        } catch (UniqueConstraintViolationException) {
            throw new StockActionException('serial_duplicate');
        }
    }

    /**
     * Moves available serials to another warehouse (bulk action on the item page).
     *
     * @param  Collection<int, ItemSerial>  $serials
     */
    public function move(Collection $serials, Warehouse $to, string $reason, User $user): int
    {
        $this->authorize($user);
        $reason = $this->reason($reason);

        return DB::transaction(function () use ($serials, $to, $reason, $user): int {
            $moved = 0;

            foreach (ItemSerial::query()->whereKey($serials->modelKeys())->lockForUpdate()->get() as $serial) {
                if ($serial->status !== SerialStatus::Available || $serial->warehouse_id === $to->id) {
                    continue;
                }

                $from = $serial->warehouse_id;
                $serial->forceFill(['warehouse_id' => $to->id])->save();
                $this->log->record($serial, 'transferred', $serial->status, $user, ['reason' => mb_substr($reason, 0, 500), 'warehouse_from_id' => $from]);
                $moved++;
            }

            $serials->first()?->item?->logActivity('serials_moved', ['count' => $moved, 'warehouse' => $to->name, 'reason' => $reason, 'by' => $user->name]);

            return $moved;
        });
    }

    protected function authorize(User $user): void
    {
        if (! $user->can('register', ItemSerial::class)) {
            throw new AuthorizationException;
        }
    }

    protected function reason(string $reason): string
    {
        $reason = trim($reason);

        if (mb_strlen($reason) < 5) {
            throw new StockActionException('serial_reason_required');
        }

        return $reason;
    }

    protected function assertUnique(ItemSerial $serial, string $value): void
    {
        $sameItem = ItemSerial::query()->where('item_id', $serial->item_id)->where('serial_normalized', $value)->whereKeyNot($serial->id)->exists();
        $global = BusinessSettings::bool('serials.global_uniqueness') && ItemSerial::query()->where('global_key', $value)->whereKeyNot($serial->id)->exists();

        if ($sameItem || $global) {
            throw new StockActionException('serial_duplicate');
        }
    }
}
