<?php

/*
| Taklif havolalari sahifalari va xatlar (Spec 08 8.2).
*/

return [

    'for' => ':name uchun taklifnoma',
    'deadline' => 'Takliflar muddati: :at',
    'time_left' => 'Qoldi: :time',
    'notice' => 'Narx so‘rovi',
    'my_bids' => 'Sizning takliflaringiz va kvitansiyalar',
    'no_bids' => 'Siz hali taklif bermadingiz.',
    'receipt' => 'Kvitansiya sha256 :hash',
    'withdraw' => 'Taklifni qaytarib olish',
    'withdraw_reason' => 'Qaytarib olish sababi',
    'confirm_withdraw' => 'Taklifingiz qaytarib olinsinmi?',
    'withdrawn' => 'Taklif qaytarib olindi.',
    'verify_title' => 'Bu siz ekanligingizni tasdiqlang',
    'verify_hint' => ':email manziliga 6 xonali kod yuboramiz. Kod 10 daqiqa amal qiladi.',
    'send_code' => 'Kod yuborish',
    'code' => 'Kod',
    'verify' => 'Tasdiqlash',
    'code_sent' => 'Kod yuborildi.',
    'code_invalid' => 'Kod noto‘g‘ri yoki muddati o‘tgan.',
    'verified' => 'Tasdiqlandi. Taklif berishingiz mumkin.',
    'verify_first' => 'Avval kod bilan tasdiqlang.',
    'submit_title' => 'Sizning taklifingiz',
    'revise' => 'Taklifni o‘zgartirish (yangi versiya oldingisini almashtiradi)',
    'valid_until' => 'Taklif amal qiladi',
    'files' => 'Ilovalar (taklif, sertifikatlar; PDF, JPG, PNG)',
    'conditions' => 'Tender shartlarini qabul qilaman (shu sahifa orqali muloqot, maxfiylik)',
    'submit' => 'Taklif berish',
    'submitted' => 'Taklif qabul qilindi. Kvitansiya xeshini saqlang.',
    'ask_title' => 'Savol berish',
    'ask_hint' => 'Javoblar barcha ishtirokchilarga ismingizsiz e’lon qilinadi.',
    'question' => 'Savol',
    'ask' => 'Savolni yuborish',
    'question_sent' => 'Savol yuborildi.',
    'decline_title' => 'Qatnashmaysizmi?',
    'decline_reason' => 'Sabab',
    'decline' => 'Voz kechish',
    'confirm_decline' => 'Ishtirokdan voz kechilsinmi?',
    'declined' => 'Siz ishtirokdan voz kechdingiz.',
    'you_declined' => 'Siz ushbu tenderdan voz kechgansiz.',
    'closed' => 'Takliflar muddati o‘tdi. Takliflaringiz va kvitansiyalar ko‘rinib turadi.',
    'mail' => [
        'invitation_subject' => ':number narx so‘rovi',
        'greeting' => 'Hurmatli :name!',
        'invitation_body' => ':company sizni :number «:title» bo‘yicha taklif berishga taklif qiladi. Muddat — :deadline.',
        'open' => 'So‘rovni ochish va taklif berish',
        'do_not_forward' => 'Havola faqat sizning kompaniyangiz uchun. Uni boshqalarga yubormang.',
        'code_subject' => ':number uchun tasdiqlash kodi',
        'code_body' => ':company kompaniyasining :number so‘rovi uchun tasdiqlash kodingiz:',
        'code_warning' => '10 daqiqa amal qiladi. Kodni hech kimga bermang.',
    ],

];
