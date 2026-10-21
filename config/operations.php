<?php

/*
|--------------------------------------------------------------------------
| Operations (roadmap step 10)
|--------------------------------------------------------------------------
|
| Backups, health check and retention. See docs/operations.md.
|
*/

return [

    'backups' => [
        /* Folder for backup archives; must not be web-served. Use a separate disk or a mounted volume. */
        'path' => env('BACKUP_PATH', storage_path('app/backups')),

        /* Archives kept; older ones are deleted after each successful backup. */
        'keep' => (int) env('BACKUP_KEEP', 14),

        /* Daily scheduled backup (server time zone of the scheduler is Asia/Tashkent). */
        'schedule' => (bool) env('BACKUP_SCHEDULE', true),
        'at' => env('BACKUP_AT', '02:30'),

        /* Folders of storage/app included next to the database (private documents, pictures, logo). */
        'storage_roots' => ['private', 'public'],

        /* Paths inside those roots that are never backed up (temporary uploads). */
        'exclude' => ['private/livewire-tmp', 'public/livewire-tmp'],

        /* Client tools for MySQL / PostgreSQL dumps; must be on PATH or absolute. */
        'mysqldump' => env('BACKUP_MYSQLDUMP', 'mysqldump'),
        'mysql' => env('BACKUP_MYSQL', 'mysql'),
        'pg_dump' => env('BACKUP_PG_DUMP', 'pg_dump'),
        'psql' => env('BACKUP_PSQL', 'psql'),
    ],

    'health' => [
        /* Token for the detailed health report (header X-Health-Token). Empty = details only from localhost. */
        'token' => env('HEALTH_TOKEN'),

        /* Thresholds that turn the status into "fail". */
        'scheduler_max_age_minutes' => 5,
        'backup_max_age_hours' => 26,
        'queue_max_pending_age_minutes' => 15,
        'failed_jobs_24h_max' => 0,
    ],

    'retention' => [
        /* Read notifications older than this are deleted (days). Unread ones are kept. */
        'notifications_days' => (int) env('RETENTION_NOTIFICATIONS_DAYS', 90),

        /* Telegram logs (received updates, the message id log, company-group posts) kept this long
           (days); messages linked to a lead are kept. Overridable in Settings > Telegram. */
        'telegram_days' => (int) env('RETENTION_TELEGRAM_DAYS', 365),

        /* Closed (lost or disqualified) leads lose their personal data after this many months
           (leads:anonymise, Spec 04 section 19). Converted leads are not touched. */
        'closed_leads_months' => (int) env('RETENTION_CLOSED_LEADS_MONTHS', 24),

        /* Daily log files kept (LOG_STACK=daily); mirrors LOG_DAILY_DAYS. */
        'log_days' => (int) env('LOG_DAILY_DAYS', 30),
    ],

];
