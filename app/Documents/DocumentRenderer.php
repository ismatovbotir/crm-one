<?php

namespace App\Documents;

use App\Enums\Locale;
use Illuminate\Contracts\View\View;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Traits\Localizable;

/**
 * The single entry point for printable documents (offers, orders, agreements, invoices).
 *
 * The document body is rendered inside withLocale($language) (try/finally restores the
 * UI locale), so __() labels, enum labels and Carbon dates come out in the document
 * language while the surrounding print screen (toolbar, picker) stays in the user's interface language. Views receive:
 *  - $doc: a DocumentFormatter for dates, numbers, money and amounts in words;
 *  - $documentLanguage: the Locale being printed.
 */
class DocumentRenderer
{
    use Localizable;

    /**
     * @param  array<string, mixed>  $data
     */
    public function render(string $view, array $data, Locale $language): HtmlString
    {
        return $this->withLocale($language->value, fn (): HtmlString => new HtmlString(
            view($view, [
                ...$data,
                'doc' => new DocumentFormatter($language),
                'documentLanguage' => $language,
            ])->render()
        ));
    }

    /**
     * Full print screen: the rendered document inside layouts/print with the language picker
     * and a "Print / Save PDF" button. $title should already be in the document language.
     *
     * @param  array<string, mixed>  $data
     */
    public function print(string $view, array $data, Locale $language, string $title, ?string $backUrl = null): View
    {
        return view('layouts.print', [
            'document' => $this->render($view, $data, $language),
            'documentLanguage' => $language,
            'title' => $title,
            'backUrl' => $backUrl,
        ]);
    }
}
