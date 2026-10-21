<?php

/*
| Partners: all organizations with roles (Spec 03 section 24).
*/

return [
    'title' => 'Hamkorlar',
    'intro' => 'Barcha tashkilotlar: mijozlar, yetkazib beruvchilar va tashuvchilar. Bitta tashkilot bir nechta rolga ega bo‘lishi mumkin.',
    'new' => 'Yangi hamkor',
    'create' => 'Hamkorni yaratish',
    'back' => 'Hamkorlarga qaytish',
    'search_placeholder' => 'Nomi, yuridik nomi yoki STIR',
    'any_role' => 'Istalgan rol',
    'empty' => 'Hamkorlar topilmadi',
    'roles' => [
        'customer' => 'Mijoz',
        'supplier' => 'Yetkazib beruvchi',
        'carrier' => 'Tashuvchi',
    ],
    'fields' => [
        'short_name' => 'Nomi',
        'region' => 'Viloyat',
        'supplier_category' => 'Yetkazib beruvchi toifasi',
        'permits' => 'Ruxsatnomalar',
        'role' => 'Rol',
        'roles' => 'Rollar',
        'fleet_size' => 'Avtopark (mashinalar)',
    ],
    'roles_hint' => 'Kamida bitta rolni belgilang. Yetkazib beruvchi va tashuvchini birlashtirish mumkin; mijoz ham yetkazib beruvchi yoki tashuvchi bo‘lishi mumkin. Faqat siz yarata oladigan rollar ko‘rinadi.',
    'sections' => [
        'terms' => 'Til, valyuta va mas’ul',
        'identity' => 'Tashkilot',
        'customer' => 'Mijoz',
        'supplier' => 'Yetkazib beruvchi va tashuvchi',
    ],
    'wizard' => [
        'label' => 'Yangi hamkor bosqichlari',
        'steps' => ['roles' => 'Turi', 'identity' => 'Tashkilot', 'address' => 'Manzil va aloqa', 'terms' => 'Shartlar', 'contact' => 'Birinchi kontakt'],
        'next' => 'Keyingisi',
        'back' => 'Orqaga',
        'step_of' => ':total dan :current-bosqich',
        'optional' => 'Majburiy emas: o‘tkazib yuboring, kontaktlarni kartada qo‘shasiz.',
    ],
    'customer_hint' => 'Bank rekvizitlari, kredit va moliyaviy ma’lumotlar saqlangandan keyin mijoz kartasida.',
    'supplier_hint' => 'Malaka «Potensial» holatidan boshlanadi. Hujjatlar, rekvizitlar va baholar yetkazib beruvchi kartasida qo‘shiladi.',
    'tax_id_exists' => 'Bu STIR bilan tashkilot allaqachon bor: :name (rollar: :roles).',
    'add_roles_existing' => 'Unga tanlangan rollarni qo‘shish',
    'created' => ':name hamkor yaratildi.',
    'edit_roles' => 'Rollarni o‘zgartirish',
    'roles_edit_hint' => 'Foydalanilayotgan rolni (takliflar, buyurtmalar, tenderlar, yetkazib beruvchiga buyurtmalar) olib bo‘lmaydi. Hech narsa o‘chirilmaydi.',
    'roles_saved' => ':name rollari saqlandi.',
    'errors' => [
        'owner_not_allowed' => 'Boshqa mas’ulni faqat tayinlash huquqi bor foydalanuvchilar tanlashi mumkin.',
        'tax_id_uz_individual' => '9 xonali STIR yoki 14 xonali JSHSHIR kiriting.',
        'tax_id_foreign' => 'Soliq raqami 5 dan 20 gacha harf yoki raqamdan iborat bo‘lishi kerak.',
        'role_required' => 'Kamida bitta rolni tanlang.',
        'role_not_allowed' => '«:role» rolini o‘zgartira olmaysiz.',
        'role_in_use' => '«:role» roli ishlatilayotgan paytda olib bo‘lmaydi: :details.',
        'tax_id_taken' => 'Bu STIR bilan tashkilot allaqachon bor (:name).',
        'tax_id_archived' => 'Bu STIR arxivdagi tashkilotda (:name).',
    ],
    'dependents' => [
        'offers' => 'takliflar: :count',
        'orders' => 'buyurtmalar: :count',
        'leads' => 'ochiq lidlar: :count',
        'tender_invitations' => 'tender takliflari: :count',
        'tender_bids' => 'stavkalar: :count',
        'tender_awards' => 'tender qarorlari: :count',
        'purchase_orders' => 'yetkazib beruvchiga buyurtmalar: :count',
    ],

    'hints' => [
        'short_name' => 'CRMda hamma joyda ko‘rinadigan qisqa nom.',
        'legal_name' => 'Shakli bilan to‘liq yuridik nom (masalan, "Alfa Trade" MChJ); hujjatlarda ishlatiladi.',
        'tax_uz' => '9 xonali STIR.',
        'tax_uz_individual' => 'Ixtiyoriy: 9 xonali STIR yoki 14 xonali JSHSHIR.',
        'tax_foreign' => 'Mamlakat soliq raqami (5–20 harf yoki raqam).',
        'search_country' => 'Mamlakatni qidirish',
        'search_owner' => 'Mas’ulni qidirish',
        'sale_type' => 'Odatda B2B; jismoniy shaxs uchun chakana. Buyurtmada o‘zgartirish mumkin.',
        'payment_terms' => 'Hisob-fakturadan keyin to‘lov kunlari; 0 = oldindan to‘lov.',
        'position' => 'Ro‘yxatdan tanlang yoki yangi lavozimni yozing.',
    ],
    'any_industry' => 'Istalgan soha',

];
