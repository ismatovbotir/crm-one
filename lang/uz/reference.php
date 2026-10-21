<?php

/*
| Reference lists (Spec 03 section 24.7).
*/

return [
    'title' => 'Ma’lumotnomalar',
    'intro' => 'Formalardagi ro‘yxatlar: sohalar, valyutalar, kontakt lavozimlari va yetkazib beruvchi toifalari. Yozuvlarni qayta nomlang, tartibini o‘zgartiring yoki o‘chiring; ishlatilayotgan yozuvlarni o‘chirib bo‘lmaydi.',
    'lists' => [
        'industry' => 'Sohalar',
        'currency' => 'Valyutalar',
        'contact_position' => 'Kontakt lavozimlari',
        'supplier_category' => 'Yetkazib beruvchi toifalari',
    ],
    'fields' => [
        'code' => 'Kod',
        'name_uz' => 'Nomi (uz)',
        'name_ru' => 'Nomi (ru)',
        'name_en' => 'Nomi (en)',
        'sort' => 'Tartib',
    ],
    'sources' => [
        'user' => 'foydalanuvchi qo‘shgan',
        'legacy' => 'eski qiymat',
    ],
    'add' => 'Yozuv qo‘shish',
    'deactivate' => 'O‘chirish',
    'activate' => 'Yoqish',
    'confirm_delete' => 'Yozuv o‘chirilsinmi? Faqat ishlatilmayotganlarini o‘chirish mumkin.',
    'used' => 'Bu yozuv ishlatilmoqda; uni o‘chiring (faolsizlantiring).',
    'saved' => 'Saqlandi.',
    'deleted' => 'Yozuv o‘chirildi.',
    'hints' => [
        'code' => 'Kichik lotin harflari, raqamlar va _.',
        'currency_code' => 'ISO kodi, 3 ta katta harf (masalan, KZT).',
    ],
];
