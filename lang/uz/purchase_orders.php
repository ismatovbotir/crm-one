<?php

/*
| Yetkazib beruvchilarga buyurtmalar (purchase-tender ko‘nikmasi, 6-bo‘lim; Spec 08 6.1).
*/

return [

    'title' => 'Yetkazib beruvchilarga buyurtmalar',
    'intro' => 'Qabul qilingan xarid tenderi qarorlari asosida yaratilgan buyurtmalar. 1C hujjat raqami qo‘lda kiritiladi.',
    'back' => 'Yetkazib beruvchilarga buyurtmalar',
    'search_placeholder' => 'Raqam, 1C raqami yoki yetkazib beruvchi',
    'empty' => 'Hozircha buyurtmalar yo‘q.',
    'history_title' => 'Holatlar tarixi',

    'status' => [
        'draft' => 'Qoralama',
        'confirmed' => 'Tasdiqlangan',
        'in_transit' => 'Yo‘lda',
        'partially_received' => 'Qisman qabul qilingan',
        'received' => 'Qabul qilingan',
        'closed' => 'Yopilgan',
        'cancelled' => 'Bekor qilingan',
    ],

    'fields' => [
        'currency' => 'Valyuta',
        'incoterm_place' => 'Punkt',
        'pickup' => 'Yuklash',
        'pickup_country' => 'Yuklash davlati (ISO)',
        'pickup_city' => 'Yuklash shahri',
        'pickup_address' => 'Yuklash manzili',
        'readiness_date' => 'Yuk tayyorligi',
        'documents_available' => 'Yetkazib beruvchi beradigan hujjatlar',
        'special_handling' => 'Alohida muomala',
        'supplier_order_ref' => 'Yetkazib beruvchi buyurtma raqami',
        'cargo' => 'Yuk',
        'country_of_origin' => 'Kelib chiqishi (ISO)',
        'gross_weight_kg' => 'Brutto, kg',
        'net_weight_kg' => 'Netto, kg',
        'volume_m3' => 'Hajm, m³',
        'packages_count' => 'Joylar',
        'package_type' => 'Qadoq turi',
        'weight_source' => 'Og‘irlik manbai',
        'number' => 'Raqam',
        'supplier' => 'Yetkazib beruvchi',
        'subtotal' => 'Summa',
        'incoterm' => 'Inkoterms',
        'required_delivery_date' => 'Talab qilingan yetkazish',
        'warehouse' => 'Manzil ombori',
        'payment_terms' => 'To‘lov shartlari',
        'tender' => 'Tender',
        'onec_number' => '1C hujjat raqami',
        'cancel_reason' => 'Bekor qilish sababi',
        'item' => 'Pozitsiya',
        'quantity' => 'Miqdor',
        'unit_price' => 'Birlik narxi',
        'total' => 'Jami',
    ],

    'actions' => ['create_transport_tender' => 'Transport tenderi yaratish', 'confirm' => 'Tasdiqlash', 'cancel' => 'Bekor qilish', 'print' => 'Chop etish'],

    'history' => ['from_award' => ':value tenderi qarori asosida yaratilgan'],

    'flash' => [
        'tender_created' => 'Buyurtma asosida :number transport tenderi yaratildi.',
        'confirmed' => 'Buyurtma tasdiqlandi.',
        'cancelled' => 'Buyurtma bekor qilindi.',
        'saved' => 'Saqlandi.',
    ],

    'new' => 'Yangi buyurtma',
    'create' => 'Yaratish',
    'no_lines' => 'Hozircha pozitsiyalar yo‘q.',
    'add_line' => 'Pozitsiya qo‘shish',
    'exw_warning' => 'EXW: xaridor eksportni chet elda rasmiylashtirishi kerak. FCA afzal. Baribir transport tenderi yaratilsinmi?',
    'cargo_line' => 'TIF TN :hs · :gross kg · :volume m³ · :packages joy',
    'weight_hint' => 'Og‘irlik va hajmni ma’lumotnoma × miqdordan olish uchun bo‘sh qoldiring; kiritilgan qiymatlar qadoqlash varaqasi ma’lumoti hisoblanadi.',
    'sources' => [
        'manual' => 'Qo‘lda',
        'tender_award' => 'Tender qarori bo‘yicha',
    ],
    'weight_sources' => [
        'master' => 'Ma’lumotnoma',
        'packing_list' => 'Qadoqlash varaqasi',
        'manual' => 'Qo‘lda',
    ],

];
