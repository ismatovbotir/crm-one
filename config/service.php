<?php

/*
|--------------------------------------------------------------------------
| Customer equipment and service tickets (Spec 09)
|--------------------------------------------------------------------------
|
| SLA targets can be overridden in the settings table (BusinessSettings) with the same keys,
| e.g. "service.sla_response_hours".
|
*/

return [

    /* Hours from report to first response (accepted or rejected). */
    'sla_response_hours' => (int) env('SERVICE_SLA_RESPONSE_HOURS', 4),

    /* Hours from acceptance to "ready". */
    'sla_repair_hours' => (int) env('SERVICE_SLA_REPAIR_HOURS', 72),

    /* Ticket numbers: SV-2026-00001. */
    'number_prefix' => 'SV',

    /* Portal limits per hour (Spec 09 §11). */
    'portal_reports_per_user_hour' => 10,
    'portal_reports_per_organization_hour' => 30,
    'portal_registrations_per_user_hour' => 20,

    /* Photos per report and size limits (re-encoded like item pictures). */
    'max_photos' => 3,

    /*
     * Spec 09 section 14: post service ticket updates (number and status only) to the customer's
     * linked Telegram groups. Global switch (Settings > Telegram, settings key of the same name);
     * each customer can also switch it off on its Telegram tab.
     */
    'telegram_group_updates' => true,

    /* Days before warranty expiry when the reminder goes out. */
    'warranty_reminder_days' => 30,

];
