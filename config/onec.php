<?php

/*
|--------------------------------------------------------------------------
| 1C integration (Spec 02)
|--------------------------------------------------------------------------
|
| `enabled` is the default of the customer exchange switch (Spec 02 rev. 3; setting
| `onec.customers_enabled` overrides it): while off, customers created in the CRM are
| `not_synced` and the customer endpoints answer 503. 1C is on a private network and is never
| called by the CRM: it pushes its customers and polls the outbox of CRM customers.
|
| `inbound.enabled` switches the inbound catalog API (/api/v1/integration/1c). It is
| separate so the catalog feed can go live before the customer sync; the admin can
| override it on Settings > Integrations (setting `onec.inbound_enabled`).
|
*/

return [

    'enabled' => (bool) env('ONEC_ENABLED', false),

    'timezone' => env('ONEC_TIMEZONE', 'Asia/Tashkent'),

    /* Customer exchange (Spec 02 section 10). */
    'customers' => [
        /* Warn when 1C has not polled the outbox for this long while the exchange is on (BR-I.23). */
        'poll_stale_minutes' => (int) env('ONEC_CUSTOMERS_POLL_STALE_MINUTES', 30),
        'max_per_poll' => (int) env('ONEC_CUSTOMERS_MAX_PER_POLL', 500),
        'max_per_push' => (int) env('ONEC_CUSTOMERS_MAX_PER_PUSH', 200),
        'max_acks' => (int) env('ONEC_CUSTOMERS_MAX_ACKS', 500),
        /* Owner of customers pushed by 1C whose manager e-mail matches nobody (BR-I.16). */
        'default_owner_email' => env('ONEC_DEFAULT_OWNER_EMAIL'),
    ],

    /* Spec 06 26: goods receipts created in 1C (POST /goods-receipts). */
    'goods_receipts' => [
        'max_per_push' => (int) env('ONEC_GOODS_RECEIPTS_MAX_PER_PUSH', 50),
    ],

    'inbound' => [
        'enabled' => (bool) env('ONEC_INBOUND_ENABLED', false),

        /* Records per request (sum of all arrays) and raw body size. */
        'max_records' => (int) env('ONEC_API_MAX_RECORDS', 1000),
        'max_body_kb' => (int) env('ONEC_API_MAX_BODY_KB', 10240),

        /* Requests per minute per token (BR-I.20). */
        'rate_per_minute' => (int) env('ONEC_API_RATE_PER_MINUTE', 60),

        /* Full snapshot: skip deactivation when fewer than this % of active rows were seen (7.4). */
        'full_snapshot_guard_percent' => (int) env('ONEC_FULL_SNAPSHOT_GUARD_PERCENT', 80),
        'snapshot_ttl_minutes' => (int) env('ONEC_SNAPSHOT_TTL_MINUTES', 120),

        /* Token lifetime (BR-I.18a): every token expires. */
        'token_default_days' => (int) env('ONEC_TOKEN_DEFAULT_DAYS', 180),
        'token_max_days' => (int) env('ONEC_TOKEN_MAX_DAYS', 365),

        /* HMAC is optional only for callers in these networks (plus RFC 1918 / loopback). */
        'private_networks' => array_values(array_filter(array_map('trim', explode(',', (string) env('ONEC_PRIVATE_NETWORKS', ''))))),

        /* Allowed clock skew for X-Timestamp of signed requests, seconds. */
        'signature_tolerance_seconds' => 300,

        /* Queue for batch processing (FIFO, one at a time). */
        'queue' => env('ONEC_INBOUND_QUEUE', 'onec-inbound'),

        /* Retention: raw batches (rejected ones shorter) and request logs. */
        'batch_retention_days' => 30,
        'batch_metadata_retention_days' => 365,
        'rejected_retention_days' => 7,
        'log_retention_days' => 90,
    ],

    /* BR-I.21 staleness thresholds in minutes (Settings > Integrations overrides them). */
    'stale_minutes' => [
        'items' => (int) env('ONEC_STALE_MINUTES_ITEMS', 1440),
        'stock' => (int) env('ONEC_STALE_MINUTES_STOCK', 30),
    ],

    /*
    | BR-I.12: default quantity precision of new units by OKEI code (CRM-local, admin-editable).
    | Unknown codes get 0.
    */
    'okei_precision' => [
        '796' => 0,
        '166' => 3,
        '112' => 3,
        '006' => 2,
        '055' => 2,
        '356' => 2,
    ],

];
