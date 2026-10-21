<?php

/*
|--------------------------------------------------------------------------
| Agreements (Spec 06 Part B)
|--------------------------------------------------------------------------
|
| default_templates       template code used per kind when generating (Settings > Agreement
|                         templates; seeded by AgreementTemplateSeeder).
| review_above_minor      agreements whose value is at or above this amount (minor units, per
|                         currency) need review by an approver who is not the preparer. UZS follows
|                         the user decision that amounts above 60 000 000 UZS go to the CEO.
| default_term_months     order agreements run from signature for this many months.
| expiry_reminder_days    reminder stages before `ends_on` (one reminder per stage).
| unsigned_reminder_days  remind the sender when an agreement stays unsigned this many days.
| scan_max_kb             maximum size of an uploaded signed scan (pdf, jpg, png only).
|
*/

return [

    'default_templates' => [
        'order' => 'order-standard',
        'framework' => 'framework-standard',
        'addendum' => 'addendum-standard',
    ],

    'review_above_minor' => [
        'UZS' => 6_000_000_000,
        'USD' => 500_000,
        'EUR' => 500_000,
        'RUB' => 50_000_000,
    ],

    'default_term_months' => 12,

    'expiry_reminder_days' => [90, 60, 30],

    'unsigned_reminder_days' => 5,

    'scan_max_kb' => 10240,

];
