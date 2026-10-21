<?php

namespace App\Documents;

use App\Enums\Locale;

/**
 * Decides which language a document is printed in. The document language is independent
 * of the interface language: a Russian-speaking manager can print an English offer.
 */
final class DocumentLanguage
{
    /**
     * Explicit choice (e.g. ?lang=ru from the print language picker), else the subject's
     * preference (e.g. the customer), else the current interface language.
     * Unsupported values are ignored rather than rejected, so a stale link still prints.
     */
    public static function resolve(mixed $requested = null, ?HasDocumentLanguage $subject = null): Locale
    {
        return Locale::fromAvailable($requested)
            ?? $subject?->preferredDocumentLocale()
            ?? Locale::current();
    }
}
