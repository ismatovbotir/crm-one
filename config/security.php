<?php

/*
|--------------------------------------------------------------------------
| Security (roadmap step 6)
|--------------------------------------------------------------------------
|
| Staff two-factor authentication (TOTP). `enforce_two_factor` can also be switched in the
| settings table (BusinessSettings, key "security.enforce_two_factor"); turn it on at go-live.
|
*/

return [

    'enforce_two_factor' => (bool) env('SECURITY_ENFORCE_TWO_FACTOR', false),

    /* Global roles that must use 2FA when enforcement is on. */
    'two_factor_roles' => ['admin', 'accountant', 'finance'],

    /* Minutes a password-verified login may wait for its second factor. */
    'two_factor_challenge_minutes' => 5,

];
