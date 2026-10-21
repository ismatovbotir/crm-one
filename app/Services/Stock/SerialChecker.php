<?php

namespace App\Services\Stock;

use App\Authorization\PermissionMap;
use App\Enums\ReceiptSource;
use App\Enums\SerialStatus;
use App\Models\Delivery;
use App\Models\DeliveryLine;
use App\Models\DocumentLineSerial;
use App\Models\GoodsReceipt;
use App\Models\Item;
use App\Models\ItemSerial;
use App\Models\User;
use App\Support\BusinessSettings;

/**
 * Per-serial checks with translated messages (Spec 06 BR-SN.4/5/6/10):
 *  - receipts accept only new serials of the item (customer returns only serials sold on the
 *    referenced delivery note);
 *  - delivery notes accept only registered serials of the line's item, `available`, in the note's
 *    warehouse. Who bought a sold serial is shown only to users allowed to see it.
 * Input: normalized serial => raw input. Output: normalized serial => [result, message].
 */
class SerialChecker
{
    public function __construct(protected SerialNormalizer $normalizer) {}

    /**
     * Normalizes a list of raw inputs and flags format errors and duplicates within the list.
     *
     * @param  list<string>  $inputs
     * @return list<array{raw: string, serial: string|null, result: string, message: string}>
     */
    public function prepare(array $inputs): array
    {
        $rows = [];
        $seen = [];

        foreach ($inputs as $index => $input) {
            $normalized = $this->normalizer->normalize($input);

            if ($normalized['error'] !== null) {
                $rows[] = ['raw' => $normalized['raw'], 'serial' => null, 'result' => $normalized['error'], 'message' => __('stock.results.'.$normalized['error'])];

                continue;
            }

            $serial = (string) $normalized['value'];

            if (isset($seen[$serial])) {
                $rows[] = ['raw' => $normalized['raw'], 'serial' => $serial, 'result' => 'duplicate_in_file', 'message' => __('stock.results.duplicate_in_file', ['row' => $seen[$serial]])];

                continue;
            }

            $seen[$serial] = $index + 1;
            $rows[] = ['raw' => $normalized['raw'], 'serial' => $serial, 'result' => 'ok', 'message' => ''];
        }

        return $rows;
    }

    /**
     * @param  list<string>  $serials  normalized
     * @return array<string, array{result: string, message: string}>
     */
    public function forReceipt(GoodsReceipt $receipt, Item $item, array $serials): array
    {
        $results = [];
        $inDocument = DocumentLineSerial::query()->where('document_type', GoodsReceipt::DOCUMENT_TYPE)->where('document_id', $receipt->id)
            ->where('item_id', $item->id)->whereIn('serial_number', $serials)->whereIn('status', [DocumentLineSerial::DRAFT, DocumentLineSerial::POSTED])
            ->pluck('serial_number')->flip();
        $existing = ItemSerial::query()->with('receiptLine.receipt')->whereIn('serial_normalized', $serials)->get();
        $global = BusinessSettings::bool('serials.global_uniqueness');

        foreach ($serials as $serial) {
            if ($inDocument->has($serial)) {
                $results[$serial] = ['result' => 'already_in_document', 'message' => __('stock.results.already_in_document')];

                continue;
            }

            $same = $existing->first(fn (ItemSerial $row): bool => $row->serial_normalized === $serial && $row->item_id === $item->id);
            $other = $existing->first(fn (ItemSerial $row): bool => $row->serial_normalized === $serial && $row->item_id !== $item->id);

            if ($receipt->source_type === ReceiptSource::CustomerReturn) {
                $results[$serial] = $this->returnCheck($receipt, $same, $serial);

                continue;
            }

            if ($same !== null) {
                $results[$serial] = ['result' => 'already_registered', 'message' => __('stock.results.already_registered', [
                    'document' => $same->receiptLine?->receipt?->number ?? '—',
                    'date' => $same->received_at?->format('d.m.Y') ?? '—',
                    'status' => $same->status->label(),
                ])];

                continue;
            }

            if ($global && $other !== null) {
                $results[$serial] = ['result' => 'belongs_to_other_item', 'message' => __('stock.results.belongs_to_other_item', ['sku' => $other->item?->sku, 'item' => $other->item?->name])];

                continue;
            }

            $results[$serial] = ['result' => 'ok', 'message' => ''];
        }

        return $results;
    }

    /**
     * @return array{result: string, message: string}
     */
    protected function returnCheck(GoodsReceipt $receipt, ?ItemSerial $serial, string $value): array
    {
        if ($serial === null || $serial->status !== SerialStatus::Sold || $receipt->return_of_delivery_id === null
            || ! DeliveryLine::query()->whereKey($serial->sold_delivery_line_id)->where('delivery_id', $receipt->return_of_delivery_id)->exists()) {
            return ['result' => 'not_sold_on_delivery', 'message' => __('stock.results.not_sold_on_delivery', ['document' => $receipt->returnOfDelivery?->number ?? '—'])];
        }

        return ['result' => 'ok', 'message' => ''];
    }

    /**
     * @param  list<string>  $serials  normalized
     * @return array<string, array{result: string, message: string}>
     */
    public function forDelivery(Delivery $delivery, DeliveryLine $line, array $serials, ?User $viewer = null): array
    {
        $results = [];
        $rows = ItemSerial::query()->with(['item', 'warehouse', 'customer'])->whereIn('serial_normalized', $serials)->get();
        $reservedBy = DeliveryLine::query()->with('delivery')->whereIn('id', $rows->where('reserved_by_line_type', DeliveryLine::LINE_TYPE)->pluck('reserved_by_line_id'))->get()->keyBy('id');
        $soldOn = DeliveryLine::query()->with(['delivery.order'])->whereIn('id', $rows->pluck('sold_delivery_line_id')->filter())->get()->keyBy('id');

        foreach ($serials as $serial) {
            $own = $rows->first(fn (ItemSerial $row): bool => $row->serial_normalized === $serial && $row->item_id === $line->item_id);

            if ($own === null) {
                $other = $rows->first(fn (ItemSerial $row): bool => $row->serial_normalized === $serial);
                $results[$serial] = $other !== null
                    ? ['result' => 'belongs_to_other_item', 'message' => __('stock.results.belongs_to_other_item', ['sku' => $other->item?->sku, 'item' => $other->item?->name])]
                    : ['result' => 'not_found', 'message' => __('stock.results.not_found', ['item' => $line->item?->name])];

                continue;
            }

            $results[$serial] = match ($own->status) {
                SerialStatus::Available => $own->warehouse_id === $delivery->warehouse_id
                    ? ['result' => 'ok', 'message' => '']
                    : ['result' => 'wrong_warehouse', 'message' => __('stock.results.wrong_warehouse', ['warehouse' => $own->warehouse?->name ?? '—'])],
                SerialStatus::Reserved => $own->reserved_by_line_type === DeliveryLine::LINE_TYPE && $own->reserved_by_line_id === $line->id
                    ? ['result' => 'already_in_document', 'message' => __('stock.results.already_in_document')]
                    : ['result' => 'reserved', 'message' => __('stock.results.reserved', ['document' => $reservedBy->get($own->reserved_by_line_id)?->delivery?->number ?? '—'])],
                SerialStatus::Sold => $this->soldMessage($own, $soldOn->get($own->sold_delivery_line_id), $viewer),
                SerialStatus::Returned, SerialStatus::Quarantined => ['result' => 'not_sellable', 'message' => __('stock.results.not_sellable')],
                SerialStatus::WrittenOff => ['result' => 'written_off', 'message' => __('stock.results.written_off')],
            };
        }

        return $results;
    }

    /**
     * @return array{result: string, message: string}
     */
    protected function soldMessage(ItemSerial $serial, ?DeliveryLine $line, ?User $viewer): array
    {
        $order = $line?->delivery?->order;
        $mayKnow = $viewer !== null && $order !== null && PermissionMap::allowsFor($viewer, 'serials.view_customer', $order->owner_id);

        return ['result' => 'sold', 'message' => $mayKnow
            ? __('stock.results.sold_to', ['document' => $line->delivery->number, 'customer' => $serial->customer?->name ?? '—'])
            : __('stock.results.sold')];
    }
}
