<?php

namespace App\Http\Controllers;

use App\Documents\DocumentLanguage;
use App\Documents\DocumentRenderer;
use App\Models\PurchaseOrder;
use App\Models\Tender;
use App\Models\TenderComparison;
use App\Models\TenderInvitation;
use App\Services\Tenders\ComparisonBuilder;
use App\Support\CompanyProfile;
use App\Support\Money;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Tender notice (Spec 03 section 9, AC-27/AC-28) and purchase order prints through
 * DocumentRenderer. The notice is bidder-facing: it is built only from a whitelist of fields
 * (no budget, target prices, incumbent data, internal notes or site contacts before award);
 * deadlines in Asia/Tashkent with the UTC+5 label. Language: picker (?lang=), else the
 * invitation's notice language / the invitee's preference, else the tender's notice language.
 */
class TenderPrintController extends Controller
{
    public function notice(Request $request, Tender $tender, DocumentRenderer $renderer): View
    {
        Gate::authorize('print', $tender);

        $invitation = $request->filled('invitation')
            ? TenderInvitation::query()->where('tender_id', $tender->id)->with('counterparty')->find((int) $request->query('invitation'))
            : null;

        $tender->load(['lots.items', 'lots.transportSpec', 'criteria', 'requirements', 'addenda', 'questions' => fn ($q) => $q->whereNotNull('published_at')]);
        $language = DocumentLanguage::resolve($request->query('lang') ?? $invitation?->notice_locale ?? $tender->notice_locale, $invitation?->counterparty);

        return $renderer->print('documents.tender_notice', [
            'tender' => $tender,
            'invitee' => $invitation?->counterparty?->name,
            'seller' => CompanyProfile::seller($language),
            'timezone' => (string) config('tenders.timezone'),
            'timezoneLabel' => (string) config('tenders.timezone_label'),
        ], $language, __('documents.tender_notice.title', [], $language->value).' '.$tender->number, route('tenders.show', $tender));
    }

    /**
     * Comparison report print (Spec 08 9.2): the requested or latest snapshot, else a live preview;
     * only after opening (AC-21); flags and matrix as text codes (black-and-white safe).
     */
    public function comparison(Request $request, Tender $tender, DocumentRenderer $renderer, ComparisonBuilder $builder): View
    {
        Gate::authorize('viewBids', $tender);
        [$core, $snapshot] = $this->comparisonData($request, $tender, $builder);
        $language = DocumentLanguage::resolve($request->query('lang'));
        $tender->logActivity('comparison_printed', ['snapshot' => $snapshot?->version]);

        return $renderer->print('documents.transport_comparison', [
            'report' => $core,
            'snapshot' => $snapshot,
            'seller' => CompanyProfile::seller($language),
            'timezone' => (string) config('tenders.timezone'),
            'timezoneLabel' => (string) config('tenders.timezone_label'),
        ], $language, __('documents.transport_comparison.title', [], $language->value).' '.$tender->number, route('tenders.comparison', $tender));
    }

    /**
     * CSV of the comparison table (Spec 08 9.2): UTF-8 with BOM, configurable delimiter, every cell
     * that could start a formula (= + - @ tab CR) prefixed with an apostrophe (AC-20).
     */
    public function comparisonCsv(Request $request, Tender $tender, ComparisonBuilder $builder): StreamedResponse
    {
        Gate::authorize('viewBids', $tender);
        [$core, $snapshot] = $this->comparisonData($request, $tender, $builder);
        $tender->logActivity('comparison_exported', ['snapshot' => $snapshot?->version, 'format' => 'csv']);
        $delimiter = (string) config('tenders.csv_delimiter', ';');

        return response()->streamDownload(function () use ($core, $delimiter): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['lot', 'rank', 'counterparty', 'channel', 'currency', 'bid_total', 'landed_cost', 'evaluation_currency', 'transit_days', 'lead_time_days', 'per_kg', 'weighted', 'valid_until', 'flags', 'knockouts'], $delimiter);

            foreach ($core['lots'] as $lot) {
                foreach ($lot['rows'] as $row) {
                    fputcsv($out, array_map([self::class, 'csvSafe'], [
                        $lot['number'], $row['rank'], $row['counterparty'], $row['channel'], $row['currency'],
                        Money::of((int) $row['total_minor'], $row['currency'])->toMajor(),
                        $row['landed_minor'] !== null ? Money::of((int) $row['landed_minor'], $core['tender']['evaluation_currency'])->toMajor() : '',
                        $core['tender']['evaluation_currency'], $row['transit_days'], $row['lead_time_days'],
                        $row['per_kg_minor'] !== null ? Money::of((int) $row['per_kg_minor'], $core['tender']['evaluation_currency'])->toMajor() : '',
                        $row['weighted'], $row['valid_until'], implode(' ', $row['flags']), implode(' ', $row['knockouts']),
                    ]), $delimiter);
                }
            }

            fclose($out);
        }, $tender->number.'-comparison.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }

    public static function csvSafe(mixed $value): string
    {
        $value = (string) ($value ?? '');

        return $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$value : $value;
    }

    /**
     * @return array{0: array<string, mixed>, 1: TenderComparison|null}
     */
    protected function comparisonData(Request $request, Tender $tender, ComparisonBuilder $builder): array
    {
        $query = TenderComparison::query()->where('tender_id', $tender->id);
        $snapshot = $request->filled('snapshot')
            ? (clone $query)->where('uuid', (string) $request->query('snapshot'))->first()
            : (clone $query)->whereIn('status', [TenderComparison::STATUS_SUBMITTED, TenderComparison::STATUS_DECIDED])->latest('id')->first();

        return [$snapshot?->payload['core'] ?? $builder->build($tender)['core'], $snapshot];
    }

    public function purchaseOrder(Request $request, PurchaseOrder $purchaseOrder, DocumentRenderer $renderer): View
    {
        Gate::authorize('print', $purchaseOrder);

        $purchaseOrder->load(['supplier', 'lines', 'destinationWarehouse', 'award.tender']);
        $language = DocumentLanguage::resolve($request->query('lang'), $purchaseOrder->supplier);

        return $renderer->print('documents.purchase_order', [
            'po' => $purchaseOrder,
            'seller' => CompanyProfile::seller($language),
        ], $language, __('documents.purchase_order.title', [], $language->value).' '.$purchaseOrder->number, route('purchase-orders.show', $purchaseOrder));
    }
}
