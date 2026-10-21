<?php

namespace App\Actions\StockOff;

use App\Authorization\PermissionMap;
use App\Enums\SerialStatus;
use App\Enums\StockOffStatus;
use App\Models\ItemSerial;
use App\Models\ItemStock;
use App\Models\StockOffAct;
use App\Models\StockOffLineSerial;
use App\Models\User;
use App\Services\OneC\Outbox\OneCOutbox;
use App\Services\Stock\Exceptions\StockOffException;
use App\Services\Stock\SerialLog;
use App\Support\Quantity;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * Posts an approved stock-off act (Spec 06 section 27.4): picked serials → `written_off` (from
 * available, quarantined or returned; event `written_off` with the act), the CRM stock mirror of
 * the warehouse is lowered by the quantity (1C stays the quantity truth and overwrites it on its
 * next push), outbox `stock_off.posted` (feed `warehouse`) becomes available for 1C to pull.
 * Posted acts are immutable.
 */
class PostStockOffAct
{
    public function __construct(protected SerialLog $log, protected OneCOutbox $outbox) {}

    public function handle(StockOffAct $act, User $user): StockOffAct
    {
        return DB::transaction(function () use ($act, $user): StockOffAct {
            $act = StockOffAct::query()->lockForUpdate()->findOrFail($act->id);

            if ($act->status !== StockOffStatus::Approved) {
                throw new StockOffException('not_approved');
            }

            if (! PermissionMap::allows($user, 'stock_off.post')) {
                throw new StockOffException('forbidden', ['to' => StockOffStatus::Posted->label()]);
            }

            $lines = $act->lines()->with('serials')->get();
            $serials = ItemSerial::query()
                ->whereIn('id', $lines->flatMap(fn ($line) => $line->serials->pluck('item_serial_id')))
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            foreach ($lines as $line) {
                foreach ($line->serials as $row) {
                    $serial = $serials->get($row->item_serial_id) ?? throw (new ModelNotFoundException)->setModel(ItemSerial::class, [$row->item_serial_id]);

                    if (! in_array($serial->status, ManageStockOffAct::PICKABLE, true) || $serial->warehouse_id !== $act->warehouse_id) {
                        throw new StockOffException('serial_changed', ['serial' => $serial->serial_normalized, 'status' => $serial->status->label()]);
                    }

                    $from = $serial->status;
                    $serial->forceFill(['status' => SerialStatus::WrittenOff, 'lock_version' => $serial->lock_version + 1])->save();
                    $this->log->record($serial, 'written_off', $from, $user, [
                        'document_type' => StockOffAct::DOCUMENT_TYPE, 'document_id' => $act->id, 'line_id' => $line->id, 'reason' => $act->reason->value,
                    ]);
                }

                $stock = ItemStock::query()->where('item_id', $line->item_id)->where('warehouse_id', $act->warehouse_id)->lockForUpdate()->first();
                $stock?->forceFill(['quantity' => bcsub((string) $stock->quantity, (string) $line->quantity, Quantity::SCALE)])->save();
            }

            StockOffLineSerial::query()->whereIn('line_id', $act->lines()->select('id'))->update(['active_key' => null]);
            $act->forceFill(['status' => StockOffStatus::Posted, 'posted_by' => $user->id, 'posted_at' => now()])->save();
            TransitionStockOffAct::record($act, StockOffStatus::Approved, StockOffStatus::Posted, $user);
            $this->outbox->publishStockOff($act->fresh());

            return $act;
        });
    }
}
