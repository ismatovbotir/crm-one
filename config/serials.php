<?php

/*
|--------------------------------------------------------------------------
| Serial numbers and stock documents (Spec 06 section 21)
|--------------------------------------------------------------------------
|
| Overridable by `settings` rows with the full key ("serials.<key>"), App\Support\BusinessSettings.
|
*/

return [

    // BR-SN.2: unique across all items (off = unique per item only).
    'global_uniqueness' => false,

    // BR-SN.1 normalization.
    'remove_internal_whitespace' => true,
    'allowed_pattern' => '/^[A-Z0-9][A-Z0-9\-\/._]*$/',
    'min_length' => 3,
    'max_length' => 64,

    // BR-SN.13: warehouse-head override for unregistered serials on delivery notes (not built yet).
    'allow_unregistered_override' => false,

    // 21.8 import limits.
    'import_max_mb' => 5,
    'import_max_rows' => 10000,
    'import_file_retention_days' => 14,

    // 21.9: include serials in `goods_receipt.posted` outbox events (returns always include them).
    'outbox_receipt_serials' => false,

    // 21.10 prints: up to this many serials inline, longer lists as ranges plus an annex.
    'print_inline_max' => 20,

    // 21.4: over-receipt above expected quantity (basis points) needs the warehouse head.
    'over_receipt_tolerance_bp' => 0,

    // 21.10 queue: drafts holding serials longer than this are listed for the stock manager.
    'draft_hold_alert_hours' => 72,

];
