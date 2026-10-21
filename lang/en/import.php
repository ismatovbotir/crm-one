<?php

/*
| Go-live data import (roadmap step 7).
*/

return [

    'title' => 'Data import',
    'intro' => 'Initial load of customers or items from a CSV or Excel file. The file is checked first; only valid rows are saved.',
    'kind' => 'What to import',
    'kinds' => [
        'customers' => 'Customers',
        'items' => 'Items (with 1C GUID)',
    ],
    'file' => 'File (CSV or XLSX)',
    'rules' => 'Up to 5 MB and :rows rows. CSV in UTF-8 or Windows-1251, separated by comma, semicolon or tab; first row = column names.',
    'columns' => 'Columns:',
    'items_note' => 'Items belong to 1C: the 1C GUID is required so later 1C updates change the same items.',
    'template' => 'Download a CSV template',
    'check' => 'Check file',
    'preview' => 'Preview: :total rows',
    'row' => 'Row',
    'name' => 'Name',
    'messages_column' => 'Notes',
    'status' => [
        'ok' => 'Valid',
        'duplicate' => 'Duplicate',
        'error' => 'Error',
    ],
    'discard' => 'Discard',
    'commit' => 'Import :count valid rows',
    'confirm' => 'Import :count rows now?',
    'done_customers' => ':count customers imported.',
    'done_items' => 'Items imported: :created new, :updated updated, :failed failed.',
    'messages' => [
        'formula' => 'Contains a formula; enter the value instead.',
        'name_required' => 'Name is required (up to 255 characters).',
        'email_invalid' => 'E-mail is not valid.',
        'currency_invalid' => 'Currency :currency is not used in the CRM.',
        'terms_invalid' => 'Payment days must be 0–365.',
        'sale_type_invalid' => 'Sale type must be b2b or retail.',
        'owner_unknown' => 'No active sales user with e-mail :email.',
        'duplicate_tax_id' => 'A customer with tax ID :tax_id already exists or repeats in the file.',
        'unit_unknown' => 'Unit :unit is not known; it must come from 1C first.',
        'field_error' => ':field: :code',
    ],
    'errors' => [
        'too_large' => 'The file may be at most :mb MB.',
        'format' => 'Only CSV or XLSX files are accepted.',
        'no_header' => 'The first row must contain the column names.',
        'too_many_rows' => 'The file may have at most :max rows.',
        'expired' => 'The preview has expired. Check the file again.',
    ],

];
