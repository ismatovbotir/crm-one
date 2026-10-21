<?php

/*
| In-app user guide per role (roadmap step 10).
*/

return [

    'title' => 'Foydalanuvchi qo‘llanmasi',
    'intro' => 'CRMdagi kundalik ish bo‘yicha qisqa qo‘llanma. U sizning rolingizda ochiladi; boshqa varaqlar hamkasblar ishni qanday davom ettirishini ko‘rsatadi.',
    'sections_label' => 'Qo‘llanma bo‘limlari',
    'yours' => 'sizning rolingiz',
    'open' => 'Sahifani ochish',
    'sections' => [
        'service_engineer' => [
            'title' => 'Servis muhandisi',
            'intro' => 'Mijozlar uskunalari va ta’mirlash so‘rovlari (Spec 09).',
            'steps' => [
                'queue' => [
                    'title' => 'Navbatni kuzating',
                    'text' => 'Servis > Servis so‘rovlari: SLA taymerlari bilan yangi va ochiq so‘rovlar. «Muddati o‘tgan» — javob yoki ta’mir muddati o‘tgan.',
                ],
                'accept' => [
                    'title' => 'Qabul qiling yoki rad eting',
                    'text' => 'Ta’mirni boshlash uchun so‘rovni qabul qiling (mijoz «Ta’mirda» ni ko‘radi) yoki sabab bilan rad eting. Muhandisni tayinlang.',
                ],
                'repair' => [
                    'title' => 'Diagnostika va ta’mir',
                    'text' => '«Diagnostika»ga, qism, sarf materiali yoki almashtiriladigan qurilma yo‘q bo‘lsa «Navbatda kutmoqda (omborda yo‘q)»ga (nima yetishmasligi va taxminiy sanani yozing) yoki «Ta’mirlash»ga o‘tkazing. Navbat ustuvorlik doirasida kelish tartibida; tartibni faqat sabab bilan o‘zgartiring. Kerakli tovar kirimi xabarnoma yuboradi. Qismlarni va kafolatdan tashqari ish narxini yozing.',
                ],
                'close' => [
                    'title' => 'Tayyor va yopish',
                    'text' => 'Qurilmani olib ketish mumkin bo‘lganda «Tayyor» deb belgilang, so‘ng bajarilgan ishlar bilan yoping. Qabul dalolatnomasini chop eting. Kafolatdan tashqari pullik ishlar 1C olishi uchun mavjud bo‘ladi.',
                ],
                'equipment' => [
                    'title' => 'Uskuna qidiruvi',
                    'text' => 'Istalgan qurilmani seriya raqami bo‘yicha toping, mijozlar ro‘yxatdan o‘tkazgan qurilmalarni tasdiqlang va bahsli seriya raqamlarini ko‘rib chiqing.',
                ],
            ],
        ],
        'general' => [
            'title' => 'Hamma uchun',
            'intro' => 'CRM qanday tuzilgani va har bir foydalanuvchidagi vositalar.',
            'steps' => [
                'flow' => [
                    'title' => 'Savdo jarayoni',
                    'text' => 'Lid → mijoz → taklif → buyurtma va shartnoma → to‘lov → seriya raqamlari bilan qadoqlash → yetkazib berish. Har bir yozuv oldingisiga bog‘langan, shuning uchun hech narsa ikki marta kiritilmaydi.',
                ],
                'sign_in' => [
                    'title' => 'Kirish va 2FA',
                    'text' => 'E-mail va parol bilan kiring. Administrator, buxgalter va moliyachilar Shaxsiy kabinetda ikki bosqichli tekshiruvni sozlaydi va tiklash kodlarini saqlaydi.',
                ],
                'search' => [
                    'title' => 'Qidiruv',
                    'text' => 'Yuqori paneldagi qidiruv sizga ruxsat etilgan lidlar, mijozlar, takliflar, buyurtmalar, tovarlar va seriya raqamlarini topadi.',
                ],
                'notifications' => [
                    'title' => 'Bildirishnomalar',
                    'text' => 'Qo‘ng‘iroqcha tasdiqlar, to‘lovlar, vazifalar va muddatlarni ko‘rsatadi. Har bir hodisa uchun CRM yoki Telegramni tanlang; avval Telegramni Shaxsiy kabinetda bog‘lang.',
                ],
                'language' => [
                    'title' => 'Til',
                    'text' => 'Interfeys tili yuqori panelda almashtiriladi. Chop etiladigan hujjatlar, chop etish sahifasida boshqasi tanlanmasa, mijoz tilida chiqadi.',
                ],
            ],
        ],
        'sales' => [
            'title' => 'Savdo',
            'intro' => 'Birinchi murojaatdan to‘langan va yetkazilgan buyurtmagacha.',
            'steps' => [
                'power_of_attorney' => [
                    'title' => 'Ishonchnoma',
                    'text' => 'Tashkilotlarning B2B buyurtmalari sahifasida «Ishonchnoma olindi» belgisi bor. Mijozning ishonchnomasi kelganda uni belgilang (raqami va vakilni izohda yozing). Shundan keyingina hisob-fakturani ro‘yxatdan o‘tkazish mumkin; menejer yoki moliya sababini ko‘rsatib, usiz ruxsat berishi mumkin.',
                ],
                'requests' => [
                    'title' => 'Mijoz so‘rovlariga javob bering',
                    'text' => 'Savdo > Mijoz so‘rovlari: mijozlar portaldan yuborganlari (ularda narxsiz). So‘rovda savol bering, «kategoriya bo‘yicha» pozitsiyalarni tovarlar bilan moslashtiring, so‘ng «So‘rov asosida taklif yaratish».',
                ],
                'leads' => [
                    'title' => 'Har bir murojaat — lid',
                    'text' => 'Lidlar > Yangi lid to‘rt qadamdan o‘tkazadi: kompaniya va kontakt, ehtiyoj va tovarlar, saralash, so‘ng tekshirish va saqlash (yoki «Hammasini bitta sahifada ko‘rsatish» ni belgilang — tanlov eslab qolinadi). Avval mijozni qidiring; dublikatlar har bir qadamda ko‘rinadi. Alt+S istalgan qadamdan saqlaydi.',
                ],
                'qualify' => [
                    'title' => 'Saralash',
                    'text' => 'Byudjet, vakolat, ehtiyoj va muddatni tasdiqlang, so‘ng lidni mijozga aylantiring; birinchi taklif shu oynada yaratiladi. Yo‘qotilgan lidlarni sabab bilan yoping.',
                ],
                'convert' => [
                    'title' => 'Lidni mijozga aylantiring',
                    'text' => 'Lid sahifasida «Mijozga aylantirish» tugmasini bosing. Topilgan tashkilotni bog‘lang yoki yangisini yarating, har bir maydon bo‘yicha nimani saqlashni tanlang va kerak bo‘lsa birinchi taklifni belgilang. Lid faqat o‘qish uchun qoladi; uning tarixi mijoz xronologiyasida ko‘rinadi.',
                ],
                'offers' => [
                    'title' => 'Taklif tuzing va yuboring',
                    'text' => 'Tovarlar, variantlar va chegirmalarni qo‘shing. Limitingizdan yuqori chegirmalar rahbar tasdig‘iga ketadi. Taklifni yuboring; mijoz portalda variantlarni tanlashi mumkin.',
                ],
                'orders' => [
                    'title' => 'Qabul qilingan taklif — buyurtmaga',
                    'text' => 'Buyurtma taklif qabul qilinganda yaratiladi yoki Buyurtmalar > Yangi buyurtma orqali taklifsiz kiritiladi (mijoz, yetkazib berish yoki o‘zi olib ketish, ombor, tovarlar; narx va chegirma limitlari takliflardagidek). Buyurtma darhol ishga tushadi (alohida tasdiqlash yo‘q); faqat to‘xtatish bo‘lsa «yangi» qoladi. B2B uchun shartnoma kerak (ramka shartnomasi avtomatik qo‘llanadi), chakana uchun savdo cheki. Buyurtma shaklini buyurtma sahifasidan chop eting; keyingi qadam o‘sha yerda.',
                ],
                'follow' => [
                    'title' => 'To‘lov va yetkazishni kuzating',
                    'text' => 'Buyurtma shkalasi shartnoma, to‘lov, qadoqlash va yetkazishni ko‘rsatadi. Debitorlik muddati o‘tgan mijozlarni ko‘rsatadi.',
                ],
                'partners' => [
                    'title' => 'Hamkorlar va rollar',
                    'text' => 'Savdo > Hamkorlar: sizga ochiq barcha tashkilotlar. «Yangi hamkor» bitta tashkilotni bir yoki bir nechta rol bilan yaratadi (mijoz, yetkazib beruvchi, tashuvchi); mavjud STIR takrorlanmaydi — rolni qo‘shing. Rollar kartadagi «Rollarni o‘zgartirish» tugmasi bilan o‘zgartiriladi.',
                ],
                'customers' => [
                    'title' => 'Mijoz ma’lumotlari to‘liq bo‘lsin',
                    'text' => 'STIR, kontaktlar, rekvizitlar va hujjat tili. Mijoz 1C da kerak bo‘lsa, «1C bilan sinxronlash»ni yoqing (STIR kerak); odatda o‘chirilgan va 1C faqat STIR bo‘yicha moslashtiradi.',
                ],
                'service' => [
                    'title' => 'Mijozlaringizning servis arizalari',
                    'text' => 'Servis > Arizalar va Uskunalar mijozlaringizning ta’mirlash arizalari va uskunalarini ko‘rsatadi (savdo rahbari — o‘z bo‘limi): holat, navbat, tarix. Mijozingiz uchun ariza ro‘yxatdan o‘tkazishingiz mumkin; qabul qilish, tayinlash va ta’mirlashni servis xizmati bajaradi.',
                ],
                'create_from_card' => [
                    'title' => 'Mijoz kartasidan hujjatlar',
                    'text' => 'Mijoz kartasidagi «Yaratish» tugmasi shu mijoz tanlangan holda yangi lid, taklif, buyurtma yoki to‘lovni ochadi yoki ramka shartnomasi va uskunalar bo‘limiga o‘tkazadi. Faqat sizga ruxsat etilgan amallar ko‘rinadi.',
                ],
            ],
        ],
        'manager' => [
            'title' => 'Savdo rahbari',
            'intro' => 'Tasdiqlar, jamoa voronkasi va natijalar.',
            'steps' => [
                'reference_lists' => [
                    'title' => 'Ma’lumotnomalarni tartibda saqlang',
                    'text' => 'Katalog > Ma’lumotnomalar: sohalar, valyutalar, kontakt lavozimlari va yetkazib beruvchi toifalari (uz, ru, en nomlari). Foydalanuvchilar qo‘shgan lavozimlar va eski soha qiymatlarini ko‘rib chiqing; yozuvlarni o‘chirish o‘rniga faolsizlantiring.',
                ],
                'portal_catalog' => [
                    'title' => 'Portal katalogini sozlang',
                    'text' => 'Katalog > Kategoriyalar bo‘limida portal nomlari, tartib va ko‘rinishni belgilang; alohida tovarlar tovar sahifasida yashiriladi; mijoz uchun qoidalar mijozning «Portal» varag‘ida.',
                ],
                'approvals' => [
                    'title' => 'Takliflarni tasdiqlash',
                    'text' => 'Savdo limitidan yuqori chegirmali takliflar Tasdiqlarda kutadi. Tasdiqlang yoki izoh bilan qaytaring.',
                ],
                'assign' => [
                    'title' => 'Lidlarni taqsimlash',
                    'text' => 'Mas’ulsiz lidlar (masalan, Telegramdan) tayinlashni kutadi. Xodim yo‘qligida lidlarni qayta tayinlang.',
                ],
                'dashboard' => [
                    'title' => 'Voronkani kuzatish',
                    'text' => 'Panel ochiq lidlar, tasdiq kutayotgan takliflar, ishdagi buyurtmalar va muddati o‘tgan vazifalarni ko‘rsatadi.',
                ],
                'reports' => [
                    'title' => 'Hisobotlar',
                    'text' => 'Savdo voronkasi, takliflar konversiyasi va buyurtmalar portfeli, CSV va Excelga yuklab olinadi.',
                ],
            ],
        ],
        'accountant' => [
            'title' => 'Buxgalter',
            'intro' => 'Shartnomalar, hisoblar va to‘lovlar (ijrochi; moliyachi tasdiqlaydi).',
            'steps' => [
                'order_payments' => [
                    'title' => 'Buyurtmalar uchun to‘lov',
                    'text' => 'To‘lovlar > Yangi to‘lov: hamkorni tanlang, to‘langan buyurtmalarni belgilang va summalarni (ochiq qoldiq qo‘yiladi), sana va usulni tekshiring. Bu omborga nimani jo‘natish mumkinligi haqida xabar; hisob 1C da yuritiladi. Noto‘g‘ri yozuv o‘chirilmaydi, sabab bilan bekor qilinadi.',
                ],
                'agreements' => [
                    'title' => 'Shartnomalarni tayyorlash va yuborish',
                    'text' => 'B2B buyurtmalar uchun: shablondan shartnoma tuzing, yuboring va imzolangan nusxani yuklang.',
                ],
                'invoices' => [
                    'title' => 'Hisob chiqarish',
                    'text' => 'Buyurtmadan to‘lov hisobini yoki chakana savdo uchun savdo chekini chiqaring.',
                ],
                'payments' => [
                    'title' => 'To‘lovlarni kiritish va taqsimlash',
                    'text' => 'Har bir kelgan to‘lovni tasdiqlovchi hujjat bilan kiriting va hisoblarga taqsimlang. Moliyachi tasdiqlaydi; o‘z yozuvingizni tasdiqlay olmaysiz.',
                ],
                'receivables' => [
                    'title' => 'Debitorlikni kuzatish',
                    'text' => 'Debitorlik va muddatlar hisoboti kechikishlarni ko‘rsatadi. Mijoz kredit limitidan oshgan buyurtmalar to‘xtatiladi.',
                ],
                'onec' => [
                    'title' => '1C bilan almashinuv',
                    'text' => 'Hisoblar, yetkazishlar va kirimlar 1C olishi uchun mavjud bo‘ladi. 1Cga qo‘lda kiritish o‘rniga hujjatdagi almashinuv holatini tekshiring.',
                ],
            ],
        ],
        'finance' => [
            'title' => 'Moliyachi',
            'intro' => 'Nazorat va tasdiqlash (tekshiruvchi).',
            'steps' => [
                'payments' => [
                    'title' => 'To‘lovlarni tasdiqlash',
                    'text' => 'Buxgalterlar kiritgan to‘lovlarni tasdiqlang yoki rad eting. Ijrochi va tekshiruvchi doim boshqa-boshqa odamlar.',
                ],
                'voids' => [
                    'title' => 'Bekor qilish va qaytarish',
                    'text' => 'To‘lov yoki hisobni bekor qilish yoki qaytarish so‘rovlari sabab bilan qaroringizni kutadi.',
                ],
                'periods' => [
                    'title' => 'Davrlarni yopish',
                    'text' => 'Oy oxirida hisob davrini yoping, shunda o‘tkazilgan summalarni hech kim o‘zgartirmaydi.',
                ],
                'credit' => [
                    'title' => 'Kredit limitlari va to‘xtatishlar',
                    'text' => 'Mijozlarga kredit limiti va qo‘lda to‘xtatish belgilang; to‘xtatilgan mijozlarning buyurtmalari oldinga siljimaydi.',
                ],
                'reports' => [
                    'title' => 'Hisobotlar',
                    'text' => 'Debitorlik va to‘lovlar hisobotlari, CSV va Excelga yuklab olinadi.',
                ],
            ],
        ],
        'stock_manager' => [
            'title' => 'Omborchi',
            'intro' => 'Kirimlar, seriya raqamlari, qadoqlash va yetkazish.',
            'steps' => [
                'receipts' => [
                    'title' => '1C dan kelgan kirimlarni qabul qilish',
                    'text' => 'Yetkazib beruvchilardan kirimlar 1C dan keladi. Qabul qilishni boshlang. Seriya hisobidagi tovarlar uchun raqamlarni skanerlang: qabul qilingan miqdor ulardan hisoblanadi; har bir shikastlangan birlikda «Shikastlangan» belgisini qo‘ying va shikastni yozing. Boshqa tovarlar uchun qabul qilingan va shikastlangan miqdorni kiriting (shikastni izohda yozing). Kirimni tekshirilgan deb belgilang va o‘tkazing; karantinga faqat shikastlangan raqamlar tushadi, natija esa 1C olishi uchun mavjud bo‘ladi. Biror narsani aniqlash kerak bo‘lsa, kirimni to‘xtatib turing; uni ombor mudiri davom ettiradi. O‘zingiz faqat mijoz qaytarishlari va boshlang‘ich qoldiqlarni yaratasiz.',
                ],
                'stock_off' => [
                    'title' => 'Shikastlangan yoki yo‘qolgan tovarni hisobdan chiqarish',
                    'text' => 'Kirimda aniqlangan shikast hisobdan chiqarish dalolatnomasi qoralamasini ochadi; boshqa holatlar uchun (saqlash yoki tashishda shikast, yo‘qotish, o‘g‘irlik, muddat o‘tishi, brak, ichki foydalanish, namunalar, rad etilgan qaytarishlar) Ombor > Hisobdan chiqarish bo‘limida dalolatnoma yarating. Seriya raqamlarini tanlang, rasm qo‘shing, yuboring; ombor mudiri tasdiqlaydi (chegaradan yuqori bo‘lsa — direktor yoki moliya), so‘ng uni o‘tkazasiz. Kirimdagi shikast bo‘yicha yetkazib beruvchiga da’voni kuzatish mumkin.',
                ],
                'serials' => [
                    'title' => 'Seriya raqamlarini ro‘yxatga olish',
                    'text' => 'Seriya raqamlarini birma-bir qo‘shing yoki fayldan yuklang; takrorlar rad etiladi.',
                ],
                'packaging' => [
                    'title' => 'Buyurtmalarni qadoqlash',
                    'text' => 'Qadoqlash navbati to‘langan yoki ruxsat berilgan buyurtmalarni ko‘rsatadi. Seriya raqamlarini skanerlang va buyurtmani qadoqlangan deb belgilang.',
                ],
                'delivery' => [
                    'title' => 'Yetkazishlarni o‘tkazish',
                    'text' => 'Tovar chiqqanda yuk xatini o‘tkazing; seriya raqamlari mijozga o‘tadi.',
                ],
                'reconciliation' => [
                    'title' => 'Seriya raqamlari va 1C',
                    'text' => 'Ombordagi seriya raqamlarini 1C miqdori bilan solishtiring. Kamomadni boshlang‘ich qoldiq kirimi bilan yoping.',
                ],
            ],
        ],
        'staff' => [
            'title' => 'Xaridlar va logistika',
            'intro' => 'Tenderlar, yetkazib beruvchilar, tashuvchilar va xarid buyurtmalari.',
            'steps' => [
                'purchase' => [
                    'title' => 'Xarid tenderlari',
                    'text' => 'Tovar yoki xizmatni tavsiflang, yetkazib beruvchilarni havola orqali taklif qiling, takliflarni solishtiring va g‘olibni tasdiqqa taklif qiling.',
                ],
                'transport' => [
                    'title' => 'Transport tenderlari',
                    'text' => 'Yo‘nalish, yuk va muddatlarni e’lon qiling, tashuvchilarni taklif qiling, takliflarni solishtiring va g‘olibni tanlang.',
                ],
                'counterparties' => [
                    'title' => 'Yetkazib beruvchilar va tashuvchilar',
                    'text' => 'Kontragentlar malakasini yuriting: hujjatlar, kontaktlar va reytinglar.',
                ],
                'purchase_orders' => [
                    'title' => 'Xarid buyurtmalari',
                    'text' => 'Tender natijasida xarid buyurtmasini yarating va tovar kelguncha kuzating.',
                ],
            ],
        ],
        'admin' => [
            'title' => 'Administrator',
            'intro' => 'Faqat tizim sozlamalari; administratorda biznes huquqlari yo‘q (ijrochi ≠ tekshiruvchi).',
            'steps' => [
                'setup' => [
                    'title' => 'Ishga tushirish ro‘yxati',
                    'text' => 'Sozlamalar > Sozlash nima yetishmayotganini ko‘rsatadi: kompaniya, direktor-imzolovchi, hisob, shablonlar, 1C tokeni, omborlar, rollar, rejalashtiruvchi.',
                ],
                'users' => [
                    'title' => 'Foydalanuvchilar va rollar',
                    'text' => 'Foydalanuvchilarni to‘g‘ri rol, bo‘lim va asosiy til bilan yarating. Ketganlarni o‘chiring. Telefon yo‘qolsa ikki bosqichli tekshiruvni tiklang. «Foydalanuvchilar > tahrirlash > Telegram» da bot ko‘rgan Telegram foydalanuvchisini bog‘lash mumkin; bog‘lash bot kodi bilan tasdiqlanguncha (yoki sabab bilan «Shaxsan tekshirildi») kutiladi.',
                ],
                'import' => [
                    'title' => 'Dastlabki import',
                    'text' => 'Mijozlar va tovarlarni CSV yoki Exceldan import qiling; fayl avval tekshiriladi, faqat to‘g‘ri qatorlar saqlanadi.',
                ],
                'integrations' => [
                    'title' => '1C integratsiyasi',
                    'text' => '1C tokenini chiqaring. 1C katalog va qoldiqlarni yuboradi va navbatdan hujjatlarni oladi.',
                ],
                'telegram_groups' => [
                    'title' => 'Kompaniyaning Telegram guruhlari',
                    'text' => 'Sozlamalar > Telegram > Kompaniya guruhlari: jamoa guruhini (va mavzuni) qo‘shing, nima va qaysi darajada yozilishini tanlang, hamma ko‘ra olmasa summalarni yoqmang va sinov xabarini yuboring. Xodimlar u yerda /status, /orders today, /requests open va /report today buyruqlaridan foydalanishi mumkin.',
                ],
                'operations' => [
                    'title' => 'Zaxira nusxalar va monitoring',
                    'text' => 'Kundalik nusxalar, /api/health va produktiv ro‘yxati ekspluatatsiya qo‘llanmasida (docs/operations.md) yozilgan.',
                ],
            ],
        ],
    ],

];
