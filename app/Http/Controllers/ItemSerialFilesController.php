<?php

namespace App\Http\Controllers;

use App\Authorization\PermissionMap;
use App\Enums\DataScope;
use App\Enums\SerialStatus;
use App\Models\Item;
use App\Models\ItemSerial;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Item page serial files (Spec 06 section 21.10): the import template prefilled for the item
 * (stock roles) and the export of the item's serials (serials.view with scope "all"). Cells that
 * start with = + - @ are prefixed with ' so spreadsheets never run them as formulas.
 */
class ItemSerialFilesController extends Controller
{
    public function template(Item $item): StreamedResponse
    {
        Gate::authorize('view', $item);
        Gate::authorize('register', ItemSerial::class);

        return response()->streamDownload(function () use ($item): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['serial_number', 'warranty_until', 'note'], ';');
            fputcsv($out, ['SN-EXAMPLE-0001', '', $this->safe(__('stock.serials.template_note', ['sku' => $item->sku, 'name' => $item->name]))], ';');
            fclose($out);
        }, 'serials-'.$item->sku.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function export(Item $item): StreamedResponse
    {
        Gate::authorize('view', $item);
        abort_unless(PermissionMap::scopeFor(auth()->user(), 'serials.view') === DataScope::All, 403);

        return response()->streamDownload(function () use ($item): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['serial_number', 'status', 'warehouse', 'received_at', 'warranty_until', 'sold_at'], ';');
            ItemSerial::query()->with('warehouse:id,name')->where('item_id', $item->id)->orderBy('serial_normalized')
                ->chunkById(1000, function ($serials) use ($out): void {
                    foreach ($serials as $serial) {
                        fputcsv($out, [
                            $this->safe($serial->serial_normalized), $serial->status instanceof SerialStatus ? $serial->status->value : (string) $serial->status,
                            $this->safe((string) $serial->warehouse?->name), $serial->received_at?->toDateString(), $serial->warranty_until?->toDateString(), $serial->sold_at?->toDateString(),
                        ], ';');
                    }
                });
            fclose($out);
        }, 'serials-'.$item->sku.'-'.now()->format('Ymd').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    protected function safe(string $value): string
    {
        return preg_match('/^[=+\-@]/', $value) === 1 ? "'".$value : $value;
    }
}
