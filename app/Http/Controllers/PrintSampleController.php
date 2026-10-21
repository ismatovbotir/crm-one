<?php

namespace App\Http\Controllers;

use App\Documents\DocumentFormatter;
use App\Documents\DocumentLanguage;
use App\Documents\DocumentRenderer;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * Demonstrates the print pipeline (language picker + DocumentRenderer + print layout)
 * with a hard-coded sample offer until real offers exist. Nothing here is persisted.
 */
class PrintSampleController extends Controller
{
    public const VAT_RATE = '12';

    public function __invoke(Request $request, DocumentRenderer $renderer): View
    {
        $language = DocumentLanguage::resolve($request->query('lang'));
        $offer = $this->sampleOffer($request->user());

        return $renderer->print(
            'documents.sample',
            ['offer' => $offer],
            $language,
            title: __('documents.offer.title', [], $language->value).' '.$offer['number'],
            backUrl: route('dashboard'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function sampleOffer(User $user): array
    {
        $lines = array_map(function (array $line): array {
            $gross = bcmul($line['quantity'], $line['unit_price'], 4);
            $discount = bcdiv(bcmul($gross, $line['discount'], 4), '100', 4);
            $line['total'] = bcadd(bcsub($gross, $discount, 4), '0', 4);

            return $line;
        }, [
            ['name' => 'Industrial router RX-200', 'unit' => 'pcs', 'quantity' => '4', 'unit_price' => '3250000.00', 'discount' => '5'],
            ['name' => 'Installation and configuration', 'unit' => 'hour', 'quantity' => '12.5', 'unit_price' => '180000.00', 'discount' => '0'],
            ['name' => 'Fibre optic cable, single-mode', 'unit' => 'm', 'quantity' => '350', 'unit_price' => '4200.00', 'discount' => '10'],
        ]);

        $subtotal = array_reduce($lines, fn (string $sum, array $line): string => bcadd($sum, $line['total'], 4), '0');
        $subtotal = DocumentFormatter::round($subtotal, 2);
        $vat = DocumentFormatter::round(bcdiv(bcmul($subtotal, self::VAT_RATE, 4), '100', 4), 2);

        return [
            'number' => 'OF-2026-0001',
            'date' => now()->startOfDay(),
            'valid_until' => now()->startOfDay()->addDays(30),
            'currency' => 'UZS',
            'seller' => ['name' => config('crm.name'), 'address' => 'Toshkent, Amir Temur ko‘chasi 1', 'tax_id' => '301 234 567'],
            'customer' => ['name' => 'Namuna Savdo MChJ', 'address' => 'Samarqand, Registon ko‘chasi 5', 'tax_id' => '302 765 432'],
            'prepared_by' => $user,
            'lines' => $lines,
            'subtotal' => $subtotal,
            'vat_rate' => self::VAT_RATE,
            'vat' => $vat,
            'total' => bcadd($subtotal, $vat, 2),
        ];
    }
}
