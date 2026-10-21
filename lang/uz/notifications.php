<?php

/*
| Notification center (roadmap step 4).
*/

return [

    'title' => 'Bildirishnomalar',
    'back' => 'Barcha bildirishnomalar',
    'open' => 'Ochish',
    'mark_read' => 'O‘qilgan deb belgilash',
    'mark_all_read' => 'Hammasini o‘qilgan deb belgilash',
    'only_unread' => 'Faqat o‘qilmaganlar',
    'unread' => 'o‘qilmagan',
    'unread_count' => '{0} o‘qilmagan yo‘q|[1,*] o‘qilmagan: :count',
    'empty' => 'Bildirishnomalar yo‘q.',
    'channels' => [
        'database' => 'CRMda',
        'mail' => 'E-mail',
        'telegram' => 'Telegram',
    ],
    'kinds' => [
        'offer' => 'taklifdagi chegirma',
        'tender_publication' => 'tenderni e’lon qilish',
        'tender_award' => 'tender natijasi',
    ],
    'events' => [
        'payment_reminder' => 'To‘lov muddati yaqin',
        'payment_overdue' => 'To‘lov kechiktirilgan',
        'shipped_unpaid' => 'To‘lanmagan buyurtma jo‘natildi',
        'account_security' => 'Akkaunt xavfsizligi',
        'lead_converted' => 'Lid mijozga aylantirildi',
        'requisition' => 'Mijoz so‘rovlari',
        'service_ticket' => 'Ta’mirlash so‘rovlari',
        'warranty_expiring' => 'Kafolat tez orada tugaydi',
        'telegram_bot_removed' => 'Bot Telegram guruhidan chiqarildi',
        'approval_requested' => 'Kelishuv so‘rovi',
        'lead_created' => 'Men uchun yangi lid',
        'follow_up_due' => 'Harakat muddati keldi',
        'payment_received' => 'Buyurtmamga to‘lov',
        'tender_deadline' => 'Tender 24 soat ichida yopiladi',
        'offer_expiring' => 'Taklif 2 kun ichida tugaydi',
    ],
    'messages' => [
        'payment_reminder' => [
            'title' => ':number buyurtmasi uchun to‘lov muddati :date',
            'body' => ':customer: to‘lanishi kerak :amount.',
        ],
        'payment_overdue' => [
            'title' => ':number buyurtmasi: to‘lov kechiktirilgan',
            'body' => ':customer: :amount, muddat :date (:days kun kechiktirilgan).',
        ],
        'payment_overdue_digest' => [
            'title' => 'Yangi kechiktirilgan to‘lovlar: :count',
            'body' => 'Roʻyxatni koʻrish uchun «Debitorlik qarzi» ni oching.',
        ],
        'shipped_unpaid' => [
            'title' => ':number buyurtma to‘liq to‘lovsiz jo‘natildi',
            'body' => ':customer, :delivery yuk xati: :total dan :paid to‘langan.',
        ],
        'telegram_link_linked_pending' => [
            'title' => 'Akkauntingizga Telegram bog‘landi',
            'body' => ':by sizga :username Telegram akkauntini bog‘ladi. «Mening kabinetim»da tasdiqlang; ungacha xabarnomalar kelmaydi.',
        ],
        'telegram_link_confirmed' => [
            'title' => 'Telegram tasdiqlandi',
            'body' => ':username Telegram akkaunti hisobingiz uchun tasdiqlandi.',
        ],
        'telegram_link_verified_in_person' => [
            'title' => 'Telegram shaxsan tasdiqlandi',
            'body' => ':by :username Telegram akkauntini siz uchun shaxsan tasdiqladi.',
        ],
        'telegram_link_unlinked' => [
            'title' => 'Telegram uzildi',
            'body' => ':username Telegram akkaunti hisobingizdan uzildi (:by).',
        ],
        'service_stock_arrived' => [
            'title' => 'Navbatdagi so‘rovlar uchun tovar keldi',
            'body' => ':receipt kirimi quyidagi so‘rovlar kutayotgan tovarlarni olib keldi: :tickets.',
        ],
        'lead_converted' => [
            'title' => ':number lid aylantirildi',
            'body' => ':user lidni :name mijoziga aylantirdi.',
        ],
        'requisition_submitted' => [
            'title' => ':number so‘rovi yuborildi',
            'body' => ':lines pozitsiya tekshiruvingizni kutmoqda.',
        ],
        'requisition_returned' => [
            'title' => ':number so‘rovi qaytarildi',
            'body' => 'Xarid menejeri so‘rovni izoh bilan qaytardi.',
        ],
        'requisition_approved' => [
            'title' => ':number so‘rovi tasdiqlandi',
            'body' => 'Xarid menejeri so‘rovni tasdiqladi.',
        ],
        'requisition_rejected' => [
            'title' => ':number so‘rovi rad etildi',
            'body' => 'Xarid menejeri so‘rovni rad etdi.',
        ],
        'requisition_received' => [
            'title' => 'Yangi mijoz so‘rovi :number',
            'body' => ':customer :lines pozitsiya yubordi.',
        ],
        'requisition_offered' => [
            'title' => ':number so‘rovi bo‘yicha taklif tayyor',
            'body' => 'Taklifni ko‘rish uchun so‘rovni oching.',
        ],
        'requisition_clarification' => [
            'title' => ':number so‘rovi bo‘yicha savol',
            'body' => 'Yetkazib beruvchi so‘rovingiz bo‘yicha savol berdi.',
        ],
        'requisition_customer_comment' => [
            'title' => ':number bo‘yicha mijoz izohi',
            'body' => ':customer izoh qoldirdi.',
        ],
        'requisition_declined' => [
            'title' => ':number so‘roviga rad javobi',
            'body' => 'Yetkazib beruvchi so‘rovga rad javobi berdi.',
        ],
        'service_ticket' => [
            'title' => ':number ta’mirlash so‘rovi',
            'body' => ':customer · :asset · :status',
        ],
        'service_ticket_customer' => [
            'title' => ':number ta’mirlash so‘rovi: :status',
            'body' => ':asset. :note',
        ],
        'service_claim_refused' => [
            'title' => 'Uskunani ro‘yxatdan o‘tkazish rad etildi',
            'body' => ':claimant :serial seriya raqamini ro‘yxatdan o‘tkazmoqchi bo‘ldi (egasi: :owner).',
        ],
        'warranty_expiring' => [
            'title' => 'Kafolat :date da tugaydi',
            'body' => ':asset (:customer)',
        ],
        'telegram_bot_removed' => [
            'title' => 'Bot :group dan chiqarildi',
            'body' => 'Bu guruh xabarlari endi CRMga kelmaydi. Guruhdan botni qayta qo‘shishni so‘rang.',
        ],
        'approval_requested' => [
            'title' => 'Kelishuv kerak: :what :number',
            'body' => ':requester qaroringizni so‘ramoqda.',
        ],
        'lead_created' => [
            'title' => 'Yangi lid :number',
            'body' => ':name (:source) sizga biriktirildi.',
        ],
        'follow_up_due' => [
            'title' => ':number lid bo‘yicha harakat muddati',
            'body' => ':note',
        ],
        'payment_received' => [
            'title' => ':number buyurtma bo‘yicha to‘lov',
            'body' => ':amount taqsimlandi; to‘lov holati: :status.',
        ],
        'tender_deadline' => [
            'title' => ':number tender tez orada yopiladi',
            'body' => ':title: takliflar :at gacha.',
        ],
        'offer_expiring' => [
            'title' => ':number taklif tez orada tugaydi',
            'body' => ':customer: :date gacha amal qiladi.',
        ],
    ],
    'preferences' => [
        'title' => 'Bildirishnoma sozlamalari',
        'intro' => 'Har bir hodisa haqida qanday xabar berilishini tanlang. CRMdagi bildirishnomalar doim yoqilgan.',
        'event' => 'Hodisa',
        'always' => 'doim yoqilgan',
        'telegram_not_linked' => 'Telegramda bildirishnoma olish uchun hisobingizni bog‘lang:',
        'saved' => 'Bildirishnoma sozlamalari saqlandi.',
    ],

];
