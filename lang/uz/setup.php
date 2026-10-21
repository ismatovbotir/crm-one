<?php

/*
| Initial setup wizard (roadmap step 7).
*/

return [

    'title' => 'Sozlash',
    'intro' => 'Ishga tushirish ro‘yxati: har bir qadamning joriy holati va uni qayerda bajarish.',
    'ready' => 'Ishga tushirishga tayyor: barcha majburiy qadamlar bajarildi.',
    'not_ready' => 'Hali tayyor emas: majburiy qadamlarni bajaring.',
    'done' => 'bajarildi',
    'required' => 'majburiy',
    'optional' => 'ixtiyoriy',
    'open' => 'Ochish',
    'ceo' => 'Direktor',
    'two_factor_enable' => '2FA ni majburiy qilish',
    'two_factor_disable' => 'Majburiylikni bekor qilish',
    'confirm_two_factor_on' => 'Administrator, buxgalter va moliyachilardan ikki bosqichli tekshiruv talab qilinsinmi? 2FA siz foydalanuvchilar keyingi sahifada uni sozlashi kerak bo‘ladi.',
    'confirm_two_factor_off' => 'Ikki bosqichli tekshiruv endi talab qilinmasinmi?',
    'two_factor_on' => 'Ikki bosqichli tekshiruv endi administrator, buxgalter va moliyachilar uchun majburiy.',
    'two_factor_off' => 'Ikki bosqichli tekshiruv endi majburiy emas.',
    'steps' => [
        'company' => [
            'title' => 'Kompaniya profili',
            'hint' => 'Hujjatlarda chop etiladigan yuridik nom, STIR, manzil va kontaktlar.',
        ],
        'signatory' => [
            'title' => 'Direktor standart imzolovchi',
            'hint' => 'Shartnomalarni direktor imzolaydi.',
        ],
        'bank' => [
            'title' => 'Asosiy bank hisobi',
            'hint' => 'Asosiy valyutadagi to‘lov hisoblarida chop etiladi.',
        ],
        'templates' => [
            'title' => 'Shartnoma shablonlari',
            'hint' => 'Shablonlarni e’lon qiling (produktivda AgreementTemplateSeeder ni bir marta ishga tushiring).',
        ],
        'onec_token' => [
            'title' => '1C integratsiya tokeni',
            'hint' => '1C ma’lumot yuborishi va navbatni olishi uchun token chiqaring.',
        ],
        'warehouses' => [
            'title' => '1C dan omborlar',
            'hint' => 'Omborlar 1C dan birinchi qoldiq yuklamasi bilan keladi.',
        ],
        'roles' => [
            'title' => 'Asosiy rollar biriktirilgan',
            'hint' => 'Kamida bitta savdo xodimi, buxgalter, moliya nazoratchisi, omborchi va direktor (boshqaruv bo‘limi rahbari).',
        ],
        'scheduler' => [
            'title' => 'Rejalashtiruvchi ishlayapti',
            'hint' => 'Cron / Vazifalar rejalashtiruvchisi har daqiqada «php artisan schedule:run» ni ishga tushiradi.',
        ],
        'two_factor' => [
            'title' => 'Ikki bosqichli tekshiruv majburiy',
            'hint' => 'Administrator, buxgalter va moliyachilardan 2FA talab qilish.',
        ],
        'telegram' => [
            'title' => 'Telegram bot',
            'hint' => 'Ixtiyoriy: Telegram orqali kirish kodlari, bildirishnomalar va lidlar.',
        ],
        'demo_data' => [
            'title' => 'Demo ma’lumotlar o‘chirilgan',
            'hint' => 'Ishga tushirishdan oldin quyidagi buyruqni bajaring.',
        ],
    ],

];
