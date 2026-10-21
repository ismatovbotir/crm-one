<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Product name shown in the admin panel
    |--------------------------------------------------------------------------
    */

    'name' => env('CRM_NAME', 'Sales CRM'),

    /*
    |--------------------------------------------------------------------------
    | Money (Spec 01 section 6.10, D-1)
    |--------------------------------------------------------------------------
    |
    | Amounts are stored as integer minor units next to an ISO 4217 currency.
    | `currencies` lists the allowed currencies with their minor-unit exponent.
    |
    */

    'base_currency' => env('CRM_BASE_CURRENCY', 'UZS'),

    'currencies' => [
        'UZS' => 2,
        'USD' => 2,
        'EUR' => 2,
        'RUB' => 2,
    ],

    /* VAT in basis points: 1200 = 12% (D-2). */
    'default_tax_rate_bp' => 1200,

    /* Default country of new customers (ISO 3166-1 alpha-2). */
    'default_country' => 'UZ',

    /*
    | Legal-form tokens removed when normalising customer names for duplicate warnings
    | (Uzbek, Russian and English forms; Latin and Cyrillic).
    */
    'legal_form_tokens' => [
        'llc', 'ltd', 'inc', 'corp', 'co', 'plc', 'gmbh', 'jsc', 'ojsc', 'cjsc', 'ip',
        'mchj', 'aj', 'qk', 'xk', 'yatt', 'ok', 'xususiy', 'korxonasi',
        'ооо', 'оао', 'зао', 'пао', 'ао', 'ип', 'чп', 'мчж', 'аж', 'хк', 'ятт',
    ],

    'per_page' => 25,

];
