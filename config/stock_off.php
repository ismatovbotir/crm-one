<?php

/*
|--------------------------------------------------------------------------
| Stock-off acts (Spec 06 section 27)
|--------------------------------------------------------------------------
|
| Overridable by `settings` rows with the full key, App\Support\BusinessSettings.
|
*/

return [

    // From this estimated value (minor units per currency) the CEO / finance approves instead of the warehouse head.
    'high_approval_from_minor' => [
        'UZS' => 1_000_000_000,
        'USD' => 100_000,
        'EUR' => 100_000,
        'RUB' => 10_000_000,
    ],

    // Evidence files (photos, PDFs) per act, kilobytes each.
    'evidence_max_kb' => 10240,

];
