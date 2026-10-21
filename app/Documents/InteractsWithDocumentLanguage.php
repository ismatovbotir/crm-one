<?php

namespace App\Documents;

use App\Enums\Locale;

/**
 * Default HasDocumentLanguage implementation for Eloquent models that store the
 * preference in a nullable `preferred_locale` column (override documentLocaleColumn() to change it).
 */
trait InteractsWithDocumentLanguage
{
    public function preferredDocumentLocale(): ?Locale
    {
        $value = $this->getAttribute($this->documentLocaleColumn());

        return Locale::fromAvailable($value instanceof Locale ? $value->value : $value);
    }

    protected function documentLocaleColumn(): string
    {
        return 'preferred_locale';
    }
}
