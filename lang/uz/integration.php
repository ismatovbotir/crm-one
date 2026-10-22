<?php

/*
| 1C integration labels (Spec 02 section 12). The integration itself is a later increment.
*/

return [

    'badge' => [
        'synced_from_1c' => '1C dan sinxronlangan',
    ],

    'last_received' => '1C dan oxirgi qabul',
    'read_only' => 'Faqat o‘qish',
    'deleted_in_1c' => '1C da o‘chirilgan',
    'deleted_in_1c_banner' => 'Bu mijoz 1C da o‘chirilgan va faqat o‘qish uchun.',
    'duplicate_tax_id' => '1C da STIR dublikati',
    'onec_code' => '1C kodi',
    'sync_status_label' => '1C sinxronlash',
    'any_sync_status' => 'Har qanday sinxronlash holati',

    'sync_status' => [
        'not_synced' => 'Sinxronlanmagan',
        'pending' => 'Navbatda',
        'synced' => 'Sinxronlangan',
        'failed' => 'Xatolik',
        'conflict' => 'Ziddiyat',
    ],

    'stale' => 'Ma’lumotlar eskirgan bo‘lishi mumkin',
    'stale_feed' => ':feed: oxirgi marta :ago olingan (chegara :minutes daq.).',
    'stale_feed_never' => ':feed: 1C dan hali hech narsa olinmagan.',

    'feeds' => [
        'items' => 'Nomenklatura',
        'stock' => 'Qoldiqlar',
    ],

    'batch_status' => [
        'received' => 'Qabul qilindi',
        'queued' => 'Navbatda',
        'processing' => 'Ishlanmoqda',
        'processed' => 'Ishlandi',
        'partial' => 'Qisman ishlandi',
        'failed' => 'Xato',
        'rejected' => 'Rad etildi',
    ],

    'sync_mode' => [
        'delta' => 'O‘zgarishlar',
        'full' => 'To‘liq yuklama',
    ],

    'abilities' => [
        'items_write' => 'Nomenklatura yuborish',
        'stock_write' => 'Qoldiqlarni yuborish',
        'batches_read' => 'Paket holatini o‘qish',
        'customers_read' => 'CRM mijozlarini olish (so‘rov)',
        'customers_write' => 'Mijozlar va tasdiqlarni yuborish',
        'orders_read' => 'Buyurtmalar, shartnomalar va hisob-varaqlarni o‘qish (outbox)',
        'payments_read' => 'To‘lovlarni o‘qish (outbox)',
        'payments_write' => '1C dan to‘lovlarni yuborish',
        'warehouse_read' => 'Kirimlarni o‘qish (outbox)',
        'warehouse_write' => '1C dan tovar kirimlarini yuborish',
    ],

    'settings' => [
        'title' => 'Integratsiyalar (1C)',
        'intro' => '1C nomenklatura, narxlar, qoldiqlar va mijozlarni CRM API ga yuboradi va undan CRM mijozlarini oladi; CRM hech qachon 1C ga murojaat qilmaydi. Token faqat bir marta ko‘rsatiladi; uni 1C jamoasiga xavfsiz kanal orqali bering.',
        'saved' => 'Integratsiya sozlamalari saqlandi.',
        'inbound_title' => 'Kiruvchi API (1C dan CRM ga)',
        'api_base_url' => '1C uchun API manzili',
        'api_base_url_help' => 'Metodlar: /ping, /items, /item-stock, /batch, /batches/{batch_id}, /customers/pending, /customers/ack, /customers, /outbox/pending, /outbox/ack, /payments, /goods-receipts.',
        'inbound_enabled' => '1C dan ma’lumot qabul qilish',
        'inbound_enabled_help' => 'O‘chirilganda 1C 503 oladi va keyinroq qayta urinadi; hech narsa saqlanmaydi.',
        'stale_title' => 'Ma’lumot shundan eski bo‘lsa ogohlantirish (daqiqa)',
        'stale_help' => 'Shu vaqt ichida yangi ma’lumot kelmasa, Nomenklatura, pozitsiya kartasi va bosh sahifada qizil ogohlantirish chiqadi.',
        'feeds_title' => 'Oqimlar holati',
    ],

    'simple' => [
        'title' => '1C API tokeni',
        'help' => '1C :url manziliga ma’lumot yuborishi uchun bitta bearer token. Uni 1C ga nusxalang. Imzo (HMAC) kerak emas.',
        'refresh' => 'Tokenni yangilash',
        'refresh_confirm' => 'Yangi token yaratilsinmi? Joriy token darhol ishlamay qoladi, shuning uchun uni 1C da zudlik bilan yangilang.',
        'refreshed' => 'Yangi 1C API tokeni yaratildi. Oldingisi endi ishlamaydi.',
        'header' => 'Sarlavhada yuboring',
        'expires' => ':date gacha amal qiladi',
        'none' => '1C API tokeni hali yo‘q (yoki muddati tugagan).',
        'generate' => 'Token yaratish',
    ],

    'tokens' => [
        'title' => 'API tokenlari',
        'help' => 'Tokenga faqat kerakli huquqlarni bering. Almashtirish: yangi token yarating, 1C ni unga o‘tkazing, so‘ng eskisini bekor qiling.',
        'name' => 'Nomi',
        'days' => 'Amal qilish muddati (kun)',
        'days_help' => 'Har bir tokenning muddati bor; ko‘pi bilan :max kun.',
        'abilities' => 'Huquqlar',
        'allowed_ips' => 'Ruxsat etilgan IP manzillar',
        'allowed_ips_help' => 'IP yoki CIDR diapazonlari, har birini yangi qatorda. Bo‘sh = istalgan manzil (unda so‘rovlarni imzolash majburiy).',
        'with_secret' => 'So‘rovlarni imzolashni talab qilish (HMAC)',
        'with_secret_help' => 'Token faqat ichki tarmoq manzillari bilan cheklanmagan bo‘lsa, majburiy.',
        'create' => 'Token yaratish',
        'ips_invalid' => 'To‘g‘ri IP manzillar yoki CIDR diapazonlarini kiriting.',
        'secret_required' => 'So‘rovlarni imzolashni yoqing yoki tokenni ichki tarmoq manzillari bilan cheklang.',
        'revoked' => 'Token bekor qilindi. U bilan 1C so‘rovlari endi rad etiladi.',
        'issued_title' => 'Yangi token yaratildi',
        'issued_warning' => 'Uni hozir nusxalang. U faqat bir marta ko‘rsatiladi va tiklab bo‘lmaydi.',
        'token' => 'Token',
        'copy' => 'Nusxalash',
        'copied' => 'Nusxalandi',
        'signing_secret' => 'Imzo siri',
        'issued_done' => 'Saqlab oldim',
        'last_used' => 'Oxirgi foydalanish',
        'expires' => 'Amal qiladi',
        'any_ip' => 'Istalgan manzil',
        'hmac_on' => 'Imzo talab qilinadi',
        'status_revoked' => 'Bekor qilingan',
        'status_expired' => 'Muddati o‘tgan',
        'rotate' => 'Almashtirish',
        'rotate_confirm' => 'Xuddi shu sozlamalar bilan almashtiruvchi token yaratilsinmi? Joriy token siz bekor qilmaguningizcha faol qoladi.',
        'revoke' => 'Bekor qilish',
        'revoke_confirm' => 'Bu token bekor qilinsinmi? 1C so‘rovlari darhol rad etiladi.',
        'empty' => 'Hozircha tokenlar yo‘q.',
    ],

    'batches' => [
        'title' => '1C dan oxirgi paketlar',
        'received_at' => 'Qabul qilindi',
        'endpoint' => 'Metod',
        'mode' => 'Rejim',
        'counts' => 'Yozuvlar',
        'counts_line' => ':accepted qabul qilindi, :rejected rad etildi, :failed xato',
        'empty' => 'Hozircha hech narsa olinmagan.',
    ],

    'empty' => [
        'catalog' => 'Nomenklatura 1C dan keladi. Administratordan API token yaratib, uni 1C jamoasiga berishni so‘rang.',
    ],

    'customer_sync' => [
        'title' => '1C bilan almashinuv',
        'pending_since' => ':time dan beri 1C ni kutmoqda',
        'poll_stale' => '1C :time dan beri o‘zgarishlarni olmadi (kamida har :minutes daqiqada kutiladi).',
        'poll_never' => '1C hali birorta ham o‘zgarishni olmagan.',
        'last_synced' => 'Oxirgi sinxronlash',
        'last_contact' => '1C bilan oxirgi aloqa',
        'resync' => 'Qayta yuborish',
        'resync_queued' => '1C uchun navbatga qo‘yildi. Keyingi almashinuvda olinadi.',
        'not_pushable' => [
            'sync_off' => 'Bu hamkor uchun 1C bilan sinxronlash o‘chirilgan.',
            'integration_disabled' => '1C bilan mijozlar almashinuvi o‘chirilgan.',
            'archived' => 'Arxivlangan yoki o‘chirilgan mijozlar 1C ga yuborilmaydi.',
            'tax_id_missing' => 'Mijozni 1C ga yuborish uchun STIRni kiriting.',
            'tax_id_invalid' => 'Mijozni 1C ga yuborish uchun STIR 9 ta raqamdan iborat bo‘lishi kerak.',
            'legal_type_not_supported' => 'Bu huquqiy shakl 1C ga yuborilmaydi.',
            'not_pushable' => 'Bu mijozni 1C ga yuborib bo‘lmaydi.',
        ],
    ],

    'customer_exchange' => [
        'allow_create' => '1C yangi hamkorlar yarata oladi',
        'allow_create_help' => '1C hech bir hamkorda yo‘q STIR yuborsa, yangi mijoz yaratiladi. O‘chirilgan: bunday yozuvlar o‘tkazib yuboriladi (create_disabled).',
        'title' => 'Mijozlar almashinuvi (1C CRM ga murojaat qiladi)',
        'help' => '1C yopiq tarmoqda joylashgan va CRM unga hech qachon murojaat qilmaydi. 1C ning rejali vazifasi o‘z mijozlarini yuboradi, CRM dagi yangi va o‘zgargan mijozlarni oladi va har birini tasdiqlaydi.',
        'enabled' => '1C bilan mijozlarni almashish',
        'enabled_help' => 'Yoqilganda, to‘g‘ri STIRli mijozlar «Kutilmoqda» holatida 1C ni kutadi. O‘chirilganda 1C 503 javobini oladi.',
        'poll_stale' => '1C so‘rov yubormasa ogohlantirish (daqiqa)',
        'poll_stale_help' => '1C vazifasi jadvalidan biroz ko‘proq qiymat kiriting (masalan, vazifa har 5 daqiqada, ogohlantirish 30 daqiqadan keyin).',
        'last_contact' => '1C bilan oxirgi aloqa',
        'channels' => [
            'poll' => 'Mijozlarni oldi',
            'ack' => 'Mijozlarni tasdiqladi',
            'push' => 'O‘z mijozlarini yubordi',
        ],
        'poll_stale_badge' => '1C mijozlarni olishni to‘xtatdi',
        'outbox_line' => '1C ni kutmoqda: :pending · xatolik bilan: :failed',
        'oldest' => 'eng eskisi :time',
        'saved' => 'Mijozlar almashinuvi sozlamalari saqlandi. Navbatga qo‘yildi: :count.',
    ],

    'document_outbox' => [
        'title' => '1C uchun hujjatlar (outbox)',
        'intro' => '1C tasdiqlangan buyurtmalar, shartnomalar, hisob-varaqlar va to‘lovlarni GET /outbox/pending orqali oladi va tasdiqlaydi; 1C da o‘tkazilgan to‘lovlar POST /payments orqali keladi.',
        'open' => '1C kutilmoqda',
        'blocked' => 'Mijoz kutilmoqda',
        'rejected' => 'Rad etilgan (30 kun)',
        'oldest' => 'Eng eski kutilayotgan',
        'last_poll' => '1C oxirgi so‘rovi',
        'last_payments' => '1C dan oxirgi to‘lovlar',
        'last_receipts' => '1C dan oxirgi kirimlar',
        'never' => 'hech qachon',
    ],

    'docs' => [
        'title' => '1C integratsiya API',
        'back' => 'Sozlamalar > Integratsiyalar',
        'read_only' => 'Faqat o‘qish uchun hujjat, namunaviy qiymatlar bilan. Bu sahifadan so‘rov yuborilmaydi.',
        'link' => '1C dasturchisi uchun API hujjati (Swagger)',
        'hint' => 'Barcha metodlarning OpenAPI 3.1 tavsifi, misollar bilan. Qo‘llanma: docs/integration-1c.md (ruscha: docs/integration-1c.ru.md).',
        'token_hint' => '1C dasturchisiga quyidagi formadan token bering: faqat 1C ga kerakli huquqlarni belgilang, 1C serverining IP manzillarini kiriting va 1C internet orqali ulansa «imzo siri»ni yoqing. Token va sir bir marta ko‘rsatiladi; ularni xavfsiz kanal orqali yetkazing.',
    ],

];
