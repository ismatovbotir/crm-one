<?php

/*
|--------------------------------------------------------------------------
| Accounting (Spec 06 7.16)
|--------------------------------------------------------------------------
|
| Defaults; each key can be overridden by a `settings` row with the same full key
| ("accounting.<key>"), edited in Settings > Accounting (App\Support\BusinessSettings).
| Money thresholds are minor units per currency.
|
*/

return [

    // SoD-4: CRM-recorded payments at or above this start `pending_approval` (Q-8 defaults).
    'payment_approval_threshold_minor' => [
        'UZS' => 10_000_000_000,
        'USD' => 1_000_000,
        'EUR' => 1_000_000,
        'RUB' => 100_000_000,
    ],

    // Bank transfers at or above this need a proof attachment (BR-AC.5); defaults to the threshold.
    'proof_required_above_minor' => [
        'UZS' => 10_000_000_000,
        'USD' => 1_000_000,
        'EUR' => 1_000_000,
        'RUB' => 100_000_000,
    ],

    // DEC-13: recorded (not yet 1C-confirmed) payments count toward order payment status.
    'count_unconfirmed_payments' => true,

    'prepayment_due_days' => 5,

    'overdue_grace_days' => 0,

    // 6.2: overdue schedule items older than this put new orders of the customer on hold.
    'hold_overdue_days' => 30,

    'tax_doc_due_days' => 3,

    'customer_reminders_enabled' => false,

    'auto_close_orders' => true,

    // BR-AC.12: off | warn | block per code.
    'invoice_code_validation' => [
        'accountant_code' => 'warn',
        'class_code' => 'warn',
        'package_code' => 'warn',
    ],

    // Columns printed on invoices (proforma and tax-document copies).
    'invoice_print_columns' => [
        'accountant_code' => false,
        'class_code' => true,
        'package_code' => true,
    ],

    'outbox' => [
        'max_per_poll' => 500,
        'max_acks' => 500,
        'stale_minutes' => 60,
        'poll_stale_minutes' => 30,
    ],

    'payments' => [
        'max_per_push' => 1000,
    ],

];
