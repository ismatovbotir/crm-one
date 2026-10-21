<?php

namespace App\Documents;

use App\Enums\Locale;

/**
 * Implemented by anything whose printed documents default to a particular language,
 * typically a customer (offers, orders, agreements and invoices addressed to them).
 */
interface HasDocumentLanguage
{
    /**
     * The language documents for this subject should be printed in by default, or null for "no preference".
     */
    public function preferredDocumentLocale(): ?Locale;
}
