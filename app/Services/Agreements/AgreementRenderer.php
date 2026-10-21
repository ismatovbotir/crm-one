<?php

namespace App\Services\Agreements;

use App\Enums\Locale;
use App\Models\Agreement;

/**
 * Agreement text in one language: the stored snapshot once sent (immutable, DEC-10), otherwise a
 * live preview rendered from the template version and the current variables snapshot.
 */
class AgreementRenderer
{
    public function __construct(protected TemplateRenderer $renderer, protected AgreementValidator $validator) {}

    /**
     * @return array{html: string, sha256: string|null, stored: bool, version: int}
     */
    public function html(Agreement $agreement, Locale $locale, ?int $version = null): array
    {
        $version ??= $agreement->version;
        $stored = $agreement->renderedVersions()->where('version', $version)->where('locale', $locale->value)->first();

        if ($stored !== null) {
            return ['html' => $stored->html, 'sha256' => $stored->sha256, 'stored' => true, 'version' => $version];
        }

        return ['html' => $this->preview($agreement, $locale), 'sha256' => null, 'stored' => false, 'version' => $agreement->version];
    }

    public function preview(Agreement $agreement, Locale $locale): string
    {
        $template = $this->validator->templateVersion($agreement, $locale->value);

        return $template === null ? '' : $this->renderer->render($template->body, $agreement->variables ?? [], $agreement->clause_options ?? [], $locale);
    }
}
