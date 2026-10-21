<?php

/*
|--------------------------------------------------------------------------
| Offers (Spec 05 section 7.11)
|--------------------------------------------------------------------------
|
| Defaults from Spec 05 Q-7/Q-8/Q-15. Discount limits are in basis points (500 = 5%) per grant
| (same grant syntax as config/permissions.php); a user's limit is the widest matching grant.
| An offer whose worst-case discount (any line of any group, DEC-6) exceeds the owner's limit
| needs approval by someone with `offers.approve` and a limit at least that high.
|
*/

return [

    'number_prefix' => 'OF',

    /* VAT basis of new offers; item sale prices with the other basis are converted once per line. */
    'prices_include_vat' => (bool) env('OFFERS_PRICES_INCLUDE_VAT', false),

    /* Validity is evaluated in this timezone (valid_until is inclusive, end of day). */
    'timezone' => env('OFFERS_TIMEZONE', 'Asia/Tashkent'),

    'default_validity_days' => (int) env('OFFERS_DEFAULT_VALIDITY_DAYS', 14),
    'max_validity_days' => (int) env('OFFERS_MAX_VALIDITY_DAYS', 90),

    /* Options per alternative group: letters A..F. */
    'max_options_per_group' => 6,

    'discount_limits_bp' => [
        'role:sales' => 500,
        'dept:sales:specialist' => 500,
        'dept:sales:head' => 1000,
        'role:manager' => 2500,
        'dept:management:head' => 10000,
    ],

    /* Recording a customer decision on behalf (BR-12). */
    'note_required_channels' => ['phone', 'messenger', 'meeting', 'email'],
    'evidence_required_channels' => ['paper', 'e_sign'],

];
