<?php

/*
| Reference lists (Spec 03 section 24.7).
*/

return [
    'title' => 'Reference lists',
    'intro' => 'Lists used in forms: industries, currencies, contact positions and supplier categories. Rename, reorder or deactivate entries; entries in use cannot be deleted.',
    'lists' => [
        'industry' => 'Industries',
        'currency' => 'Currencies',
        'contact_position' => 'Contact positions',
        'supplier_category' => 'Supplier categories',
    ],
    'fields' => [
        'code' => 'Code',
        'name_uz' => 'Name (uz)',
        'name_ru' => 'Name (ru)',
        'name_en' => 'Name (en)',
        'sort' => 'Order',
    ],
    'sources' => [
        'user' => 'added by a user',
        'legacy' => 'old value',
    ],
    'add' => 'Add an entry',
    'deactivate' => 'Deactivate',
    'activate' => 'Activate',
    'confirm_delete' => 'Delete this entry? Only unused entries can be deleted.',
    'used' => 'This entry is used; deactivate it instead.',
    'saved' => 'Saved.',
    'deleted' => 'Entry deleted.',
    'hints' => [
        'code' => 'Lowercase letters, digits and _.',
        'currency_code' => 'ISO code, 3 capital letters (e.g. KZT).',
    ],
];
