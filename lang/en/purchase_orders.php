<?php

/*
| Purchase orders to suppliers (purchase-tender skill section 6, Spec 08 6.1).
*/

return [

    'title' => 'Purchase orders',
    'intro' => 'Orders to suppliers created from accepted purchase awards. The 1C document number is entered manually.',
    'back' => 'Purchase orders',
    'search_placeholder' => 'Number, 1C number or supplier',
    'empty' => 'No purchase orders yet.',
    'history_title' => 'Status history',

    'status' => [
        'draft' => 'Draft',
        'confirmed' => 'Confirmed',
        'in_transit' => 'In transit',
        'partially_received' => 'Partially received',
        'received' => 'Received',
        'closed' => 'Closed',
        'cancelled' => 'Cancelled',
    ],

    'fields' => [
        'currency' => 'Currency',
        'incoterm_place' => 'Named place',
        'pickup' => 'Pickup',
        'pickup_country' => 'Pickup country (ISO)',
        'pickup_city' => 'Pickup city',
        'pickup_address' => 'Pickup address',
        'readiness_date' => 'Cargo ready',
        'documents_available' => 'Documents the supplier provides',
        'special_handling' => 'Special handling',
        'supplier_order_ref' => 'Supplier order reference',
        'cargo' => 'Cargo',
        'country_of_origin' => 'Origin (ISO)',
        'gross_weight_kg' => 'Gross, kg',
        'net_weight_kg' => 'Net, kg',
        'volume_m3' => 'Volume, m³',
        'packages_count' => 'Packages',
        'package_type' => 'Package type',
        'weight_source' => 'Weights from',
        'number' => 'Number',
        'supplier' => 'Supplier',
        'subtotal' => 'Amount',
        'incoterm' => 'Incoterm',
        'required_delivery_date' => 'Required delivery',
        'warehouse' => 'Destination warehouse',
        'payment_terms' => 'Payment terms',
        'tender' => 'Tender',
        'onec_number' => '1C document number',
        'cancel_reason' => 'Cancellation reason',
        'item' => 'Item',
        'quantity' => 'Quantity',
        'unit_price' => 'Unit price',
        'total' => 'Total',
    ],

    'actions' => ['create_transport_tender' => 'Create transport tender', 'confirm' => 'Confirm', 'cancel' => 'Cancel', 'print' => 'Print'],

    'history' => ['from_award' => 'Created from the award of tender :value'],

    'flash' => [
        'tender_created' => 'Transport tender :number created from the purchase order.',
        'confirmed' => 'Purchase order confirmed.',
        'cancelled' => 'Purchase order cancelled.',
        'saved' => 'Saved.',
    ],

    'new' => 'New purchase order',
    'create' => 'Create',
    'no_lines' => 'No lines yet.',
    'add_line' => 'Add line',
    'exw_warning' => 'EXW: the buyer must export-clear abroad. Prefer FCA. Create the transport tender anyway?',
    'cargo_line' => 'HS :hs · :gross kg · :volume m³ · :packages pkg',
    'weight_hint' => 'Leave weight and volume empty to take them from the item master × quantity; entered values count as packing-list data.',
    'sources' => [
        'manual' => 'Manual',
        'tender_award' => 'From tender award',
    ],
    'weight_sources' => [
        'master' => 'Item master',
        'packing_list' => 'Packing list',
        'manual' => 'Manual',
    ],

];
