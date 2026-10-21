<?php

namespace App\Support;

/**
 * Normalises company names for duplicate warnings (Spec 01 section 6.3, BR-6):
 * lowercase, quotes and punctuation removed, legal-form tokens (LLC, OOO, MChJ, ...) dropped,
 * single spaces. "«ALPHA TRADE» MChJ" and "Alpha Trade LLC" both become "alpha trade".
 */
final class CustomerNameNormalizer
{
    public static function normalize(?string $name): string
    {
        $name = mb_strtolower((string) $name);
        $name = (string) preg_replace('/[«»"\'`‘’“”„.,()\/\\\\-]+/u', ' ', $name);

        $tokens = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $legalForms = array_map(fn (string $token): string => mb_strtolower($token), (array) config('crm.legal_form_tokens', []));

        $kept = array_filter($tokens, fn (string $token): bool => ! in_array($token, $legalForms, true));

        return mb_substr(implode(' ', $kept), 0, 255);
    }
}
