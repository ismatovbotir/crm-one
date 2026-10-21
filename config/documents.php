<?php

/*
|--------------------------------------------------------------------------
| Printed documents
|--------------------------------------------------------------------------
|
| show_accountant_code: print the accountant's item code (Spec 02 BR-I.13a, snapshotted on offer
| lines) as an extra column on printed offers. Off by default; the IKPU class code and package
| code are never printed on offers (they belong on invoices, Spec 06).
|
*/

return [

    'show_accountant_code' => (bool) env('DOCUMENTS_SHOW_ACCOUNTANT_CODE', false),

    /* Item pictures (Spec 05 section 16): offer prints show them by default; order and delivery prints do not. */
    'show_item_images' => (bool) env('DOCUMENTS_SHOW_ITEM_IMAGES', true),
    'show_item_images_orders' => (bool) env('DOCUMENTS_SHOW_ITEM_IMAGES_ORDERS', false),

    'seller' => [
        'name' => env('COMPANY_NAME', env('CRM_NAME', 'Sales CRM')),
        'address' => env('COMPANY_ADDRESS', ''),
        'tax_id' => env('COMPANY_TAX_ID', ''),
        'phone' => env('COMPANY_PHONE', ''),
    ],

];
