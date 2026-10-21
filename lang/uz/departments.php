<?php

return [

    'title' => 'Bo‘limlar',
    'intro' => 'Bo‘limlar va ularning xodimlari. Modullardagi huquqlar tashkiliy tuzilmaga bog‘liq: bo‘limdagi rol (rahbar, mutaxassis, kuzatuvchi) global rolga qo‘shimcha huquq beradi.',
    'new' => 'Yangi bo‘lim',
    'create' => 'Bo‘lim yaratish',
    'back' => 'Bo‘limlar ro‘yxatiga qaytish',
    'details' => 'Bo‘lim ma’lumotlari',
    'search_placeholder' => 'Nomi yoki kodi bo‘yicha qidirish',
    'empty' => 'Filtrlarga mos bo‘lim topilmadi.',

    'code' => 'Kod',
    'code_hint' => 'Kichik lotin harflari, raqamlar, «-» va «_». Huquqlar xaritasida ishlatiladi.',
    'name_in' => 'Nomi (:language)',
    'names_hint' => 'Standart bo‘limlarning barcha tillarda o‘rnatilgan nomlari bor; bu maydonlarni faqat ularni almashtirish uchun to‘ldiring.',
    'parent' => 'Yuqori bo‘lim',
    'no_parent' => 'Yo‘q (yuqori daraja)',
    'sort_order' => 'Tartib raqami',
    'is_active' => 'Faol (nofaol bo‘lim huquq bermaydi)',
    'head' => 'Rahbar',
    'members_count' => 'Xodimlar',

    'members' => 'Xodimlar',
    'members_hint' => 'Har bir bo‘limda faqat bitta rahbar; har bir foydalanuvchining bitta asosiy bo‘limi bor. O‘zgarishlar foydalanuvchining keyingi so‘rovidan kuchga kiradi.',
    'no_members' => 'Bu bo‘limda hali xodim yo‘q.',
    'member' => 'Foydalanuvchi',
    'member_role' => 'Bo‘limdagi rol',
    'primary' => 'Asosiy',
    'make_primary' => 'Asosiy qilish',
    'choose_user' => 'Foydalanuvchini tanlang...',
    'add_member' => 'Xodim qo‘shish',
    'remove' => 'Chiqarish',
    'remove_confirm' => ':name bo‘limdan chiqarilsinmi?',

    'created' => ':name bo‘limi yaratildi.',
    'updated' => ':name bo‘limi yangilandi.',
    'member_added' => ':name bo‘limga qo‘shildi.',
    'member_updated' => ':name a’zoligi yangilandi.',
    'member_removed' => ':name bo‘limdan chiqarildi.',

    'errors' => [
        'head_taken' => 'Bu bo‘limning rahbari allaqachon bor (:name). Avval uning rolini o‘zgartiring.',
        'portal_user' => 'Portal foydalanuvchilari ichki bo‘lim xodimi bo‘la olmaydi.',
    ],

    'roles' => [
        'head' => 'Rahbar',
        'specialist' => 'Mutaxassis',
        'viewer' => 'Kuzatuvchi',
    ],

    'scopes' => [
        'own' => 'O‘z yozuvlari',
        'linked' => 'Bog‘liq yozuvlar',
        'department' => 'Bo‘lim yozuvlari',
        'all' => 'Barcha yozuvlar',
    ],

    'names' => [
        'sales' => 'Sotuv',
        'purchase' => 'Xaridlar',
        'logistics' => 'Import va transport',
        'finance' => 'Buxgalteriya va moliya',
        'management' => 'Rahbariyat',
        'it' => 'Ma’muriyat va IT',
        'support' => 'Qo‘llab-quvvatlash',
        'warehouse' => 'Ombor',
        'service' => 'Servis',
    ],

];
