<?php

namespace App\Http\Controllers;

use App\Documents\DocumentLanguage;
use App\Documents\DocumentRenderer;
use App\Enums\Locale;
use App\Models\Agreement;
use App\Services\Agreements\AgreementRenderer;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Printable agreement (Spec 06 section 12): the stored text of the sent version (or ?version=n of
 * an earlier one), or a draft preview marked as such. The picked language prints first; with a
 * second agreement language both texts print side by side.
 */
class AgreementPrintController extends Controller
{
    public function __invoke(Request $request, Agreement $agreement, DocumentRenderer $renderer, AgreementRenderer $agreements): View
    {
        Gate::authorize('print', $agreement);

        return $this->render($request, $agreement, $renderer, $agreements, route('agreements.show', $agreement));
    }

    /** The printed agreement without authorization (callers authorize: staff policy or portal scope). */
    public function render(Request $request, Agreement $agreement, DocumentRenderer $renderer, AgreementRenderer $agreements, string $backUrl): View
    {
        $agreement->load(['customer', 'order', 'parent', 'renewalOf', 'ourSignatory']);
        $language = DocumentLanguage::resolve($request->query('lang'), $agreement);
        $version = ctype_digit((string) $request->query('version')) ? (int) $request->query('version') : null;
        $locales = in_array($language->value, $agreement->printLocales(), true)
            ? array_values(array_unique([$language->value, ...$agreement->printLocales()]))
            : [$language->value];

        $texts = array_map(fn (string $locale): array => [...$agreements->html($agreement, Locale::from($locale), $version), 'locale' => $locale], $locales);

        return $renderer->print('documents.agreement', [
            'agreement' => $agreement,
            'texts' => $texts,
        ], $language, __('documents.agreement.title', [], $language->value).' '.$agreement->number, $backUrl);
    }
}
