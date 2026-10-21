<?php

/*
|--------------------------------------------------------------------------
| Orders (Spec 06 Part A)
|--------------------------------------------------------------------------
|
| Defaults for the order rules. Each key may be overridden at runtime by a row in the
| `settings` table with the same key prefixed by "orders." (App\Support\BusinessSettings);
| the Settings > Accounting screen that edits them ships with job 4.
|
| agreement_required                 new orders need a signed agreement (or an active framework
|                                    agreement); a manager/finance user may waive it with a reason.
| block_fulfilment_without_agreement start of fulfilment is held (`agreement_unsigned`) until the
|                                    agreement is signed or active.
| block_confirm_on_shortage          refuse confirmation when stock is short (default: warn and
|                                    require "I acknowledge shortages").
| stock_warehouse_ids                warehouses counted for availability; empty = all warehouses.
|
*/

return [

    'agreement_required' => (bool) env('ORDERS_AGREEMENT_REQUIRED', true),

    'block_fulfilment_without_agreement' => (bool) env('ORDERS_BLOCK_FULFILMENT_WITHOUT_AGREEMENT', true),

    'block_confirm_on_shortage' => (bool) env('ORDERS_BLOCK_CONFIRM_ON_SHORTAGE', false),

    'stock_warehouse_ids' => [],

    'timezone' => env('CRM_BUSINESS_TIMEZONE', 'Asia/Tashkent'),

];
