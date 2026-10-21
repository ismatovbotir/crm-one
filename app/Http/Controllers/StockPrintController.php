<?php

namespace App\Http\Controllers;

use App\Documents\DocumentLanguage;
use App\Documents\DocumentRenderer;
use App\Models\Delivery;
use App\Models\GoodsReceipt;
use App\Models\ItemSerial;
use App\Models\StockOffAct;
use App\Support\BusinessSettings;
use App\Support\CompanyProfile;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Stock prints (Spec 06 21.10) through DocumentRenderer in uz/ru/en: delivery note with serials
 * (inline up to `print_inline_max`, ranges and an annex above), warranty card per serialized line,
 * goods receipt; plus the XLSX serial import template (serial column formatted as text).
 */
class StockPrintController extends Controller
{
    public function delivery(Request $request, Delivery $delivery, DocumentRenderer $renderer): View
    {
        Gate::authorize('print', $delivery);

        return $this->renderDelivery($request, $delivery, $renderer, route('delivery-notes.show', $delivery));
    }

    /** Delivery note print without authorization (callers authorize: staff policy or portal scope). */
    public function renderDelivery(Request $request, Delivery $delivery, DocumentRenderer $renderer, string $backUrl): View
    {
        $delivery->load(['order.customer', 'warehouse', 'lines.orderLine', 'lines.serialRows']);
        $language = DocumentLanguage::resolve($request->query('lang'), $delivery);

        return $renderer->print('documents.delivery_note', [
            // Spec 05 section 16: order and delivery prints show pictures only by setting or ?images=1.
            'showImages' => $request->has('images') ? $request->boolean('images') : BusinessSettings::bool('documents.show_item_images_orders'),
            'delivery' => $delivery,
            'seller' => CompanyProfile::seller($language),
            'inlineMax' => BusinessSettings::int('serials.print_inline_max'),
        ], $language, __('documents.delivery_note.title', [], $language->value).' '.$delivery->number, $backUrl);
    }

    public function warranty(Request $request, Delivery $delivery, DocumentRenderer $renderer): View
    {
        Gate::authorize('print', $delivery);

        return $this->renderWarranty($request, $delivery, $renderer, route('delivery-notes.show', $delivery));
    }

    /** Warranty card print without authorization (callers authorize: staff policy or portal scope). */
    public function renderWarranty(Request $request, Delivery $delivery, DocumentRenderer $renderer, string $backUrl): View
    {
        $delivery->load(['order.customer', 'lines.orderLine', 'lines.serialRows']);
        $language = DocumentLanguage::resolve($request->query('lang'), $delivery);
        $serials = ItemSerial::query()->with('item')->whereIn('sold_delivery_line_id', $delivery->lines->pluck('id'))->orderBy('serial_normalized')->get();

        return $renderer->print('documents.warranty_card', [
            'delivery' => $delivery,
            'serials' => $serials,
            'seller' => CompanyProfile::seller($language),
        ], $language, __('documents.warranty_card.title', [], $language->value).' '.$delivery->number, $backUrl);
    }

    public function receipt(Request $request, GoodsReceipt $receipt, DocumentRenderer $renderer): View
    {
        Gate::authorize('print', $receipt);
        $receipt->load(['warehouse', 'lines.item', 'lines.serialRows']);
        $language = DocumentLanguage::resolve($request->query('lang'));

        return $renderer->print('documents.goods_receipt', [
            'receipt' => $receipt,
            'seller' => CompanyProfile::seller($language),
            'inlineMax' => BusinessSettings::int('serials.print_inline_max'),
        ], $language, __('documents.goods_receipt.title', [], $language->value).' '.$receipt->number, route('goods-receipts.show', $receipt));
    }

    /** Stock-off act (Spec 06 27.6): reason, lines with serials and value, approvals and signatures. */
    public function stockOff(Request $request, StockOffAct $act, DocumentRenderer $renderer): View
    {
        Gate::authorize('print', $act);
        $act->load(['warehouse', 'lines.item', 'lines.serials', 'goodsReceipt', 'supplierPartner', 'creator', 'decider', 'transitions.user']);
        $language = DocumentLanguage::resolve($request->query('lang'));

        return $renderer->print('documents.stock_off_act', [
            'act' => $act,
            'seller' => CompanyProfile::seller($language),
            'inlineMax' => BusinessSettings::int('serials.print_inline_max'),
        ], $language, __('documents.stock_off.title', [], $language->value).' '.$act->number, route('stock-off.show', $act));
    }

    /** XLSX template: `serial_number` as Text (leading zeros survive), `warranty_until`, `note`. */
    public function template(): StreamedResponse
    {
        abort_unless(auth()->user()?->can('create', GoodsReceipt::class) || auth()->user()?->can('viewAny', Delivery::class) || auth()->user()?->can('register', ItemSerial::class), 403);

        return response()->streamDownload(function (): void {
            $writer = new Writer;
            $writer->openToFile('php://output');
            $text = (new Style)->withFormat('@');
            $writer->addRow(Row::fromValues([
                'serial_number', 'warranty_until', 'note', 'damaged',
            ]));
            $writer->addRow(Row::fromValuesWithStyles(['SN0001', '', '', ''], [0 => $text]));
            $writer->close();
        }, 'serial-numbers-template.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }
}
