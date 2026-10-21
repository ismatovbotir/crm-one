<?php

/*
| HTTP error pages (resources/views/errors).
*/

return [

    'code' => 'Xato :code',
    'home' => 'Bosh sahifaga',
    'back' => 'Orqaga',
    'retry_after' => ':seconds soniyadan keyin qayta urinib ko‘ring.',
    '403' => [
        'title' => 'Kirish taqiqlangan',
        'message' => 'Bu sahifani ochishga ruxsatingiz yo‘q. Kirish kerak bo‘lsa, rahbaringiz yoki administratorga murojaat qiling.',
    ],
    '404' => [
        'title' => 'Sahifa topilmadi',
        'message' => 'Sahifa mavjud emas, ko‘chirilgan yoki havola to‘liq emas.',
    ],
    '419' => [
        'title' => 'Sahifa eskirgan',
        'message' => 'Xavfsizlik uchun sessiya muddati tugadi. Orqaga qayting, sahifani yangilang va qaytadan urinib ko‘ring.',
    ],
    '429' => [
        'title' => 'So‘rovlar juda ko‘p',
        'message' => 'Qisqa vaqt ichida juda ko‘p so‘rov yuborildi. Biroz kuting.',
    ],
    '500' => [
        'title' => 'Nimadir noto‘g‘ri ketdi',
        'message' => 'Bizning tomonda kutilmagan xato yuz berdi. U jurnalga yozildi; keyinroq qayta urinib ko‘ring.',
    ],
    '503' => [
        'title' => 'Texnik ishlar olib borilmoqda',
        'message' => 'Tizim yangilanmoqda va tez orada yana ishlaydi.',
    ],

];
