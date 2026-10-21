<?php

/*
| Контрагенты: поставщики и перевозчики (Spec 03 T-1).
*/

return [

    'title' => 'Контрагенты',
    'intro' => 'Поставщики и перевозчики для тендеров: квалификационные документы, контакты и рейтинги.',
    'new' => 'Новый контрагент',
    'create' => 'Создать',
    'back' => 'Контрагенты',
    'search_placeholder' => 'Название, юр. название, ИНН или e-mail',
    'all_roles' => 'Поставщики и перевозчики',
    'empty' => 'Контрагентов пока нет.',
    'no_tenders' => 'Пока не приглашался в тендеры.',
    'change_status' => 'Изменить статус',
    'reason' => 'Причина',
    'tabs_label' => 'Разделы контрагента',
    'tabs' => ['details' => 'Реквизиты', 'contacts' => 'Контакты', 'documents' => 'Документы', 'ratings' => 'Рейтинги', 'tenders' => 'Тендеры', 'activity' => 'Журнал изменений'],

    'status' => ['prospect' => 'Потенциальный', 'pending_qualification' => 'На квалификации', 'qualified' => 'Квалифицирован', 'suspended' => 'Приостановлен', 'blacklisted' => 'Чёрный список'],
    'status_reasons' => ['document_expired' => 'Истёк срок квалификационного документа.'],
    'roles' => ['supplier' => 'Поставщик', 'carrier' => 'Перевозчик'],

    'fields' => [
        'name' => 'Название',
        'legal_name' => 'Юридическое название',
        'tax_id' => 'ИНН',
        'country' => 'Страна (ISO)',
        'city' => 'Город',
        'address' => 'Адрес',
        'phone' => 'Телефон',
        'email' => 'E-mail',
        'website' => 'Сайт',
        'roles' => 'Роль',
        'location' => 'Местоположение',
        'rating' => 'Рейтинг',
        'fleet_size' => 'Размер парка',
        'has_tir' => 'Держатель книжки МДП',
        'has_adr' => 'ДОПОГ (опасные грузы)',
        'preferred_locale' => 'Язык документов',
        'notes' => 'Примечания',
    ],

    'contacts' => [
        'name' => 'ФИО', 'position' => 'Должность', 'telegram' => 'Telegram', 'primary' => 'Основной', 'add' => 'Добавить контакт',
        'remove' => 'Удалить', 'confirm_remove' => 'Удалить этот контакт?', 'empty' => 'Контактов пока нет.',
    ],

    'document_types' => [
        'registration' => 'Госрегистрация', 'license' => 'Лицензия', 'transport_license' => 'Транспортная лицензия', 'cmr_insurance' => 'Страхование ответственности CMR',
        'cargo_insurance' => 'Страхование груза', 'adr' => 'Свидетельство ДОПОГ', 'tir' => 'Книжка МДП', 'iso' => 'Сертификат ISO',
        'dealer_authorization' => 'Дилерское / OEM-письмо', 'bank_reference' => 'Банковская справка', 'other' => 'Другое',
    ],

    'documents' => [
        'type' => 'Тип', 'number' => 'Номер', 'valid_until' => 'Действует до', 'verified' => 'Проверил', 'file' => 'Файл (PDF, JPG, PNG)',
        'add' => 'Добавить документ', 'verify' => 'Проверить', 'open' => 'Открыть', 'expired' => 'Истёк', 'expiring' => 'Скоро истекает', 'empty' => 'Документов пока нет.',
    ],

    'ratings' => [
        'quality' => 'Качество', 'communication' => 'Коммуникация', 'documents' => 'Документы', 'on_time' => 'Вовремя', 'late' => 'С опозданием',
        'comment' => 'Комментарий', 'add' => 'Добавить оценку', 'empty' => 'Оценок пока нет.',
        'line' => 'Качество :quality · коммуникация :communication · документы :documents',
    ],

    'flash' => [
        'saved' => 'Контрагент сохранён.',
        'contact_saved' => 'Контакт сохранён.',
        'document_added' => 'Документ добавлен.',
        'status_changed' => 'Статус изменён.',
        'rated' => 'Оценка сохранена.',
    ],

];
