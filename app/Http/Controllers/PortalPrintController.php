<?php

namespace App\Http\Controllers;

use App\Documents\DocumentRenderer;
use App\Enums\StockDocumentStatus;
use App\Models\Agreement;
use App\Models\Delivery;
use App\Models\Invoice;
use App\Models\Offer;
use App\Models\Order;
use App\Services\Agreements\AgreementRenderer;
use App\Support\PortalContext;
use App\Support\PortalScope;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * Portal prints (Spec 03 BR-P.5): the same documents as staff prints, through DocumentRenderer
 * with the language picker, after the company scope check (another company's record → 404).
 * Invoices and agreements only for finance roles (Spec 03 4.8).
 */
class PortalPrintController extends Controller
{
    public function offer(Request $request, Offer $offer, PortalScope $scope, DocumentRenderer $renderer): View
    {
        abort_unless($scope->offers()->whereKey($offer->id)->exists(), 404);

        return app(OfferPrintController::class)->render($request, $offer, $renderer, route('portal.offers.show', $offer));
    }

    public function order(Request $request, Order $order, PortalScope $scope, DocumentRenderer $renderer): View
    {
        abort_unless($scope->orders()->whereKey($order->id)->exists(), 404);

        return app(OrderPrintController::class)->render($request, $order, $renderer, route('portal.orders.show', $order));
    }

    public function invoice(Request $request, Invoice $invoice, PortalScope $scope, PortalContext $context, DocumentRenderer $renderer): View
    {
        abort_unless($context->canSeeFinance() && $scope->invoices()->whereKey($invoice->id)->exists(), 404);

        return app(InvoicePrintController::class)->render($request, $invoice, $renderer, route('portal.invoices.index'));
    }

    public function agreement(Request $request, Agreement $agreement, PortalScope $scope, PortalContext $context, DocumentRenderer $renderer, AgreementRenderer $agreements): View
    {
        abort_unless($context->canSeeFinance() && $scope->agreements()->whereKey($agreement->id)->exists(), 404);

        return app(AgreementPrintController::class)->render($request, $agreement, $renderer, $agreements, route('portal.agreements.index'));
    }

    public function delivery(Request $request, Delivery $delivery, PortalScope $scope, DocumentRenderer $renderer): View
    {
        $this->assertDelivery($delivery, $scope);

        return app(StockPrintController::class)->renderDelivery($request, $delivery, $renderer, route('portal.orders.show', $delivery->order));
    }

    public function warranty(Request $request, Delivery $delivery, PortalScope $scope, DocumentRenderer $renderer): View
    {
        $this->assertDelivery($delivery, $scope);

        return app(StockPrintController::class)->renderWarranty($request, $delivery, $renderer, route('portal.orders.show', $delivery->order));
    }

    protected function assertDelivery(Delivery $delivery, PortalScope $scope): void
    {
        abort_unless($delivery->status === StockDocumentStatus::Posted && $scope->orders()->whereKey($delivery->order_id)->exists(), 404);
    }
}
