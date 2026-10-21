<?php

/*
|--------------------------------------------------------------------------
| Internal Telegram notifications (Spec 03 section 23)
|--------------------------------------------------------------------------
|
| Defaults; Settings > Telegram stores overrides in the settings table under the same keys
| (e.g. "telegram.internal.daily_time"). Times are Asia/Tashkent.
|
*/

return [

    'internal' => [
        /* Daily report time (HH:MM). */
        'daily_time' => '18:00',

        /* Weekly report: day (mon..sun) and time. */
        'weekly_day' => 'mon',
        'weekly_time' => '09:00',

        /* Overdue payment scan runs once a day after this time. */
        'overdue_scan_time' => '09:00',
    ],

];
