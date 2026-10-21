<?php

namespace App\Support;

use App\Services\Agreements\TemplateSanitizer;
use Illuminate\Support\HtmlString;

/**
 * Trusted HTML for views without raw `{!! !!}` (UI consistency step 1): agreement texts are run
 * through the allowlist sanitizer again (defence in depth) and returned as HtmlString, which Blade
 * prints as markup.
 */
final class SafeHtml
{
    public static function agreement(?string $html): HtmlString
    {
        return new HtmlString(app(TemplateSanitizer::class)->sanitize((string) $html));
    }
}
