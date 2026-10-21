<?php

/*
| Go-live data import (roadmap step 7).
*/

return [

    'title' => 'Ma’lumotlarni import qilish',
    'intro' => 'Mijozlar yoki tovarlarni CSV yoki Excel faylidan dastlabki yuklash. Avval fayl tekshiriladi; faqat to‘g‘ri qatorlar saqlanadi.',
    'kind' => 'Nimani import qilish',
    'kinds' => [
        'customers' => 'Mijozlar',
        'items' => 'Tovarlar (1C GUID bilan)',
    ],
    'file' => 'Fayl (CSV yoki XLSX)',
    'rules' => '5 MB va :rows qatorgacha. CSV UTF-8 yoki Windows-1251 da, vergul, nuqtali vergul yoki tab bilan ajratilgan; birinchi qator — ustun nomlari.',
    'columns' => 'Ustunlar:',
    'items_note' => 'Tovarlar 1C ga tegishli: keyingi 1C yangilanishlari aynan shu tovarlarni o‘zgartirishi uchun 1C GUID majburiy.',
    'template' => 'CSV shablonini yuklab olish',
    'check' => 'Faylni tekshirish',
    'preview' => 'Oldindan ko‘rish: :total qator',
    'row' => 'Qator',
    'name' => 'Nomi',
    'messages_column' => 'Izohlar',
    'status' => [
        'ok' => 'To‘g‘ri',
        'duplicate' => 'Takror',
        'error' => 'Xato',
    ],
    'discard' => 'Bekor qilish',
    'commit' => ':count to‘g‘ri qatorni import qilish',
    'confirm' => ':count qatorni hozir import qilasizmi?',
    'done_customers' => ':count mijoz import qilindi.',
    'done_items' => 'Tovarlar: yangi :created, yangilangan :updated, xato :failed.',
    'messages' => [
        'formula' => 'Formula bor; o‘rniga qiymatni kiriting.',
        'name_required' => 'Nomi majburiy (255 belgigacha).',
        'email_invalid' => 'E-mail noto‘g‘ri.',
        'currency_invalid' => ':currency valyutasi CRMda ishlatilmaydi.',
        'terms_invalid' => 'To‘lov kunlari 0–365 bo‘lishi kerak.',
        'sale_type_invalid' => 'Sotuv turi b2b yoki retail bo‘lishi kerak.',
        'owner_unknown' => ':email e-mailli faol savdo xodimi yo‘q.',
        'duplicate_tax_id' => ':tax_id STIRli mijoz allaqachon bor yoki faylda takrorlanadi.',
        'unit_unknown' => ':unit birligi noma’lum; avval u 1C dan kelishi kerak.',
        'field_error' => ':field: :code',
    ],
    'errors' => [
        'too_large' => 'Fayl hajmi :mb MB dan oshmasligi kerak.',
        'format' => 'Faqat CSV yoki XLSX fayllar qabul qilinadi.',
        'no_header' => 'Birinchi qatorda ustun nomlari bo‘lishi kerak.',
        'too_many_rows' => 'Faylda ko‘pi bilan :max qator bo‘lishi mumkin.',
        'expired' => 'Oldindan ko‘rish muddati tugadi. Faylni qayta tekshiring.',
    ],

];
