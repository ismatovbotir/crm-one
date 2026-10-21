<?php

/*
| Kontragentlar: yetkazib beruvchilar va tashuvchilar (Spec 03 T-1).
*/

return [

    'title' => 'Kontragentlar',
    'intro' => 'Tenderlarga taklif qilinadigan yetkazib beruvchilar va tashuvchilar: malaka hujjatlari, kontaktlar va reytinglar.',
    'new' => 'Yangi kontragent',
    'create' => 'Yaratish',
    'back' => 'Kontragentlar',
    'search_placeholder' => 'Nom, yuridik nom, STIR yoki e-mail',
    'all_roles' => 'Yetkazib beruvchilar va tashuvchilar',
    'empty' => 'Hozircha kontragentlar yo‘q.',
    'no_tenders' => 'Hali tenderlarga taklif qilinmagan.',
    'change_status' => 'Holatni o‘zgartirish',
    'reason' => 'Sabab',
    'tabs_label' => 'Kontragent bo‘limlari',
    'tabs' => ['details' => 'Rekvizitlar', 'contacts' => 'Kontaktlar', 'documents' => 'Hujjatlar', 'ratings' => 'Reytinglar', 'tenders' => 'Tenderlar', 'activity' => 'O‘zgarishlar jurnali'],

    'status' => ['prospect' => 'Potensial', 'pending_qualification' => 'Malaka tekshiruvida', 'qualified' => 'Malakali', 'suspended' => 'To‘xtatilgan', 'blacklisted' => 'Qora ro‘yxatda'],
    'status_reasons' => ['document_expired' => 'Malaka hujjatining muddati tugadi.'],
    'roles' => ['supplier' => 'Yetkazib beruvchi', 'carrier' => 'Tashuvchi'],

    'fields' => [
        'name' => 'Nom',
        'legal_name' => 'Yuridik nom',
        'tax_id' => 'STIR',
        'country' => 'Davlat (ISO)',
        'city' => 'Shahar',
        'address' => 'Manzil',
        'phone' => 'Telefon',
        'email' => 'E-mail',
        'website' => 'Sayt',
        'roles' => 'Rol',
        'location' => 'Joylashuv',
        'rating' => 'Reyting',
        'fleet_size' => 'Avtopark hajmi',
        'has_tir' => 'TIR daftarchasi egasi',
        'has_adr' => 'ADR (xavfli yuklar)',
        'preferred_locale' => 'Hujjatlar tili',
        'notes' => 'Izohlar',
    ],

    'contacts' => [
        'name' => 'F.I.Sh.', 'position' => 'Lavozim', 'telegram' => 'Telegram', 'primary' => 'Asosiy', 'add' => 'Kontakt qo‘shish',
        'remove' => 'O‘chirish', 'confirm_remove' => 'Ushbu kontakt o‘chirilsinmi?', 'empty' => 'Hozircha kontaktlar yo‘q.',
    ],

    'document_types' => [
        'registration' => 'Davlat ro‘yxatidan o‘tish', 'license' => 'Litsenziya', 'transport_license' => 'Transport litsenziyasi', 'cmr_insurance' => 'CMR javobgarlik sug‘urtasi',
        'cargo_insurance' => 'Yuk sug‘urtasi', 'adr' => 'ADR guvohnomasi', 'tir' => 'TIR daftarchasi', 'iso' => 'ISO sertifikati',
        'dealer_authorization' => 'Diler / OEM ruxsatnomasi', 'bank_reference' => 'Bank ma’lumotnomasi', 'other' => 'Boshqa',
    ],

    'documents' => [
        'type' => 'Turi', 'number' => 'Raqam', 'valid_until' => 'Amal qiladi', 'verified' => 'Tekshirgan', 'file' => 'Fayl (PDF, JPG, PNG)',
        'add' => 'Hujjat qo‘shish', 'verify' => 'Tekshirish', 'open' => 'Ochish', 'expired' => 'Muddati o‘tgan', 'expiring' => 'Muddati tugamoqda', 'empty' => 'Hozircha hujjatlar yo‘q.',
    ],

    'ratings' => [
        'quality' => 'Sifat', 'communication' => 'Muloqot', 'documents' => 'Hujjatlar', 'on_time' => 'O‘z vaqtida', 'late' => 'Kechikib',
        'comment' => 'Izoh', 'add' => 'Baho qo‘shish', 'empty' => 'Hozircha baholar yo‘q.',
        'line' => 'Sifat :quality · muloqot :communication · hujjatlar :documents',
    ],

    'flash' => [
        'saved' => 'Kontragent saqlandi.',
        'contact_saved' => 'Kontakt saqlandi.',
        'document_added' => 'Hujjat qo‘shildi.',
        'status_changed' => 'Holat o‘zgartirildi.',
        'rated' => 'Baho saqlandi.',
    ],

];
