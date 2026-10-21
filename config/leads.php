<?php

/*
|--------------------------------------------------------------------------
| Leads (Spec 04)
|--------------------------------------------------------------------------
|
| Defaults recorded in Spec 04 section 12.
|
*/

return [

    'number_prefix' => 'LD',

    /* Open leads without activity for this many days are "stale" (BR-8). */
    'stale_days' => (int) env('LEADS_STALE_DAYS', 14),

    /* Minimum length of the note for reason "other" and of a reopen comment (BR-5). */
    'min_note_length' => 10,

    /* Maximum length of a logged activity text and of interested items per lead. */
    'activity_max_length' => 2000,
    'max_items' => 50,

    /* Telegram leads (section 15.2): owner by round robin among sales users (else unassigned). */
    'round_robin' => (bool) env('LEADS_ROUND_ROBIN', true),

    /* Messages per chat and hour turned into lead work; more are dropped silently (spam guard). */
    'telegram_rate_per_hour' => (int) env('LEADS_TELEGRAM_RATE_PER_HOUR', 20),

    /* Spec 04 section 17: messages per hour from one Telegram group (each sender also has the per-person limit above). */
    'telegram_group_rate_per_hour' => (int) env('LEADS_TELEGRAM_GROUP_RATE_PER_HOUR', 120),

];
