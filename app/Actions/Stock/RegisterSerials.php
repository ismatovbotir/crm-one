<?php

namespace App\Actions\Stock;

use App\Enums\ReceiptSource;
use App\Models\GoodsReceipt;
use App\Models\Item;
use App\Models\ItemSerial;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Stock\Exceptions\StockActionException;
use App\Services\Stock\SerialChecker;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * "Add / import serials" from the catalogue (user requirement 2026-10-18, Spec 06 21.4 rules):
 * serials of one serialized item are checked as a batch (format, duplicates in the batch,
 * already registered per the uniqueness setting) and saved only when every row is valid, in one
 * transaction, as a posted goods receipt with the reason `manual_registration` (receipts exist,
 * so the serials keep their document link and the warehouse feed sees the receipt).
 * The normalized form is always shown back to the user (never normalized silently).
 */
class RegisterSerials
{
    public function __construct(
        protected SerialChecker $checker,
        protected ManageGoodsReceipt $receipts,
        protected AddReceiptSerials $addSerials,
        protected PostGoodsReceipt $post,
    ) {}

    /**
     * @param  list<string>  $inputs
     * @return list<array{raw: string, serial: string|null, result: string, message: string}>
     */
    public function check(Item $item, array $inputs): array
    {
        if (! $item->is_serialized) {
            throw new StockActionException('item_not_serialized');
        }

        $rows = $this->checker->prepare($inputs);
        $valid = array_values(array_unique(array_filter(array_column($rows, 'serial'), fn (?string $serial): bool => $serial !== null)));
        $probe = new GoodsReceipt(['source_type' => ReceiptSource::ManualRegistration]);
        $checks = $this->checker->forReceipt($probe, $item, $valid);

        foreach ($rows as $index => $row) {
            if ($row['result'] === 'ok' && isset($checks[$row['serial']])) {
                $rows[$index] = [...$row, ...$checks[$row['serial']]];
            }
        }

        return $rows;
    }

    /**
     * @param  list<string>  $inputs
     */
    public function register(Item $item, Warehouse $warehouse, array $inputs, User $user, string $origin = 'manual'): GoodsReceipt
    {
        // Spec 06 SoD-S4: only stock roles create the receipt behind the dialog (never admin).
        if (! $user->can('register', ItemSerial::class)) {
            throw new AuthorizationException;
        }

        $rows = $this->check($item, $inputs);

        if ($rows === []) {
            throw new StockActionException('batch_empty');
        }

        if (collect($rows)->contains(fn (array $row): bool => $row['result'] !== 'ok')) {
            throw new StockActionException('batch_invalid');
        }

        return DB::transaction(function () use ($item, $warehouse, $rows, $user, $origin): GoodsReceipt {
            $receipt = $this->receipts->create([
                'source_type' => ReceiptSource::ManualRegistration->value,
                'warehouse_id' => $warehouse->id,
                'note' => __('stock.register.reason_note', ['origin' => __('stock.register.origins.'.$origin)]),
            ], $user);
            $line = $this->receipts->addLine($receipt, $item, null, (string) count($rows));
            $added = $this->addSerials->handle($line, array_column($rows, 'raw'), $user, null, true);

            if (collect($added)->contains(fn (array $row): bool => $row['result'] !== 'ok')) {
                throw new StockActionException('batch_invalid');
            }

            $this->post->handle($receipt, $user);
            $item->logActivity('serials_registered', [
                'count' => count($rows),
                'receipt' => $receipt->number,
                'warehouse' => $warehouse->name,
                'origin' => $origin,
            ]);

            return $receipt->fresh();
        });
    }
}
