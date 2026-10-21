<?php

namespace App\Http\Controllers;

use App\Documents\DocumentLanguage;
use App\Documents\DocumentRenderer;
use App\Models\Order;
use App\Support\BusinessSettings;
use App\Support\CompanyProfile;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Printable order confirmation (Spec 06 section 12) through DocumentRenderer, in the language
 * chosen with the picker (?lang=), else the order's document language, else the customer's.
 */
class OrderPrintController extends Controller
{
    public function __invoke(Request $request, Order $order, DocumentRenderer $renderer): View
    {
        Gate::authorize('print', $order);

        return $this->render($request, $order, $renderer, route('orders.show', $order));
    }

    /** The printed order without authorization (callers authorize: staff policy or portal scope). */
    public function render(Request $request, Order $order, DocumentRenderer $renderer, string $backUrl): View
    {
        $order->load(['lines', 'customer', 'contact', 'owner', 'offer']);
        $language = DocumentLanguage::resolve($request->query('lang'), $order);

        return $renderer->print('documents.order', [
            'order' => $order,
            'seller' => CompanyProfile::seller($language),
            'showAccountantCode' => (bool) config('documents.show_accountant_code'),
            // Spec 05 section 16: order and delivery prints show pictures only by setting or ?images=1.
            'showImages' => $request->has('images') ? $request->boolean('images') : BusinessSettings::bool('documents.show_item_images_orders'),
        ], $language, __('documents.order.confirmation_title', [], $language->value).' '.$order->number, $backUrl);
    }
}
