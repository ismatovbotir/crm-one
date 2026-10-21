<?php

namespace App\Http\Controllers;

use App\Documents\DocumentLanguage;
use App\Documents\DocumentRenderer;
use App\Models\Offer;
use App\Support\BusinessSettings;
use App\Support\CompanyProfile;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Printable offer (Spec 05 section 11) through DocumentRenderer, in the language chosen with the
 * picker (?lang=uz|ru|en), else the offer's document language, else the customer's preference.
 * ?selection=blank|current|selected: blank form for ticking by hand, current choices, or only
 * fixed + selected lines (acceptance confirmation). Black-and-white safe: every state is text.
 */
class OfferPrintController extends Controller
{
    public const MODES = ['blank', 'current', 'selected'];

    public function __invoke(Request $request, Offer $offer, DocumentRenderer $renderer): View
    {
        Gate::authorize('print', $offer);

        return $this->render($request, $offer, $renderer, route('offers.show', $offer));
    }

    /** The printed offer without authorization (callers authorize: staff policy or portal scope). */
    public function render(Request $request, Offer $offer, DocumentRenderer $renderer, string $backUrl): View
    {
        $offer->load(['lines.itemImage', 'groups', 'customer', 'contact', 'owner', 'selections']);
        $language = DocumentLanguage::resolve($request->query('lang'), $offer);
        $selected = $offer->selections->pluck('offer_line_id')->map(fn (mixed $id): int => (int) $id)->all();
        $mode = in_array($request->query('selection'), self::MODES, true) ? (string) $request->query('selection') : ($selected === [] ? 'blank' : 'current');

        if ($mode === 'selected' && ! $offer->selection_complete) {
            $mode = 'current';
        }

        return $renderer->print('documents.offer', [
            'offer' => $offer,
            'blocks' => $offer->blocks(),
            'selected' => $mode === 'blank' ? [] : $selected,
            'mode' => $mode,
            'seller' => CompanyProfile::seller($language),
            'showAccountantCode' => (bool) config('documents.show_accountant_code'),
            // Spec 05 section 16: picture column by setting, overridable per print (?images=0|1).
            'showImages' => $request->has('images') ? $request->boolean('images') : BusinessSettings::bool('documents.show_item_images'),
        ], $language, __('documents.offer.title', [], $language->value).' '.$offer->displayNumber(), $backUrl);
    }
}
