<?php

/*
| Заказы поставщикам (навык purchase-tender, раздел 6; Spec 08 6.1).
*/

return [

    'title' => 'Заказы поставщикам',
    'intro' => 'Заказы поставщикам, созданные по принятым решениям закупочных тендеров. Номер документа 1С вводится вручную.',
    'back' => 'Заказы поставщикам',
    'search_placeholder' => 'Номер, номер 1С или поставщик',
    'empty' => 'Заказов поставщикам пока нет.',
    'history_title' => 'История статусов',

    'status' => [
        'draft' => 'Черновик',
        'confirmed' => 'Подтверждён',
        'in_transit' => 'В пути',
        'partially_received' => 'Принят частично',
        'received' => 'Принят',
        'closed' => 'Закрыт',
        'cancelled' => 'Отменён',
    ],

    'fields' => [
        'currency' => 'Валюта',
        'incoterm_place' => 'Пункт',
        'pickup' => 'Загрузка',
        'pickup_country' => 'Страна загрузки (ISO)',
        'pickup_city' => 'Город загрузки',
        'pickup_address' => 'Адрес загрузки',
        'readiness_date' => 'Готовность груза',
        'documents_available' => 'Документы от поставщика',
        'special_handling' => 'Особое обращение',
        'supplier_order_ref' => 'Номер заказа у поставщика',
        'cargo' => 'Груз',
        'country_of_origin' => 'Происхождение (ISO)',
        'gross_weight_kg' => 'Брутто, кг',
        'net_weight_kg' => 'Нетто, кг',
        'volume_m3' => 'Объём, м³',
        'packages_count' => 'Мест',
        'package_type' => 'Тип упаковки',
        'weight_source' => 'Вес взят из',
        'number' => 'Номер',
        'supplier' => 'Поставщик',
        'subtotal' => 'Сумма',
        'incoterm' => 'Инкотермс',
        'required_delivery_date' => 'Требуемая поставка',
        'warehouse' => 'Склад назначения',
        'payment_terms' => 'Условия оплаты',
        'tender' => 'Тендер',
        'onec_number' => 'Номер документа 1С',
        'cancel_reason' => 'Причина отмены',
        'item' => 'Позиция',
        'quantity' => 'Количество',
        'unit_price' => 'Цена за ед.',
        'total' => 'Сумма',
    ],

    'actions' => ['create_transport_tender' => 'Создать транспортный тендер', 'confirm' => 'Подтвердить', 'cancel' => 'Отменить', 'print' => 'Печать'],

    'history' => ['from_award' => 'Создан по решению тендера :value'],

    'flash' => [
        'tender_created' => 'Транспортный тендер :number создан по заказу поставщику.',
        'confirmed' => 'Заказ поставщику подтверждён.',
        'cancelled' => 'Заказ поставщику отменён.',
        'saved' => 'Сохранено.',
    ],

    'new' => 'Новый заказ поставщику',
    'create' => 'Создать',
    'no_lines' => 'Позиций пока нет.',
    'add_line' => 'Добавить позицию',
    'exw_warning' => 'EXW: покупатель должен оформить экспорт за границей. Лучше FCA. Всё равно создать транспортный тендер?',
    'cargo_line' => 'ТН ВЭД :hs · :gross кг · :volume м³ · :packages мест',
    'weight_hint' => 'Оставьте вес и объём пустыми, чтобы взять их из справочника × количество; введённые значения считаются данными упаковочного листа.',
    'sources' => [
        'manual' => 'Вручную',
        'tender_award' => 'По решению тендера',
    ],
    'weight_sources' => [
        'master' => 'Справочник',
        'packing_list' => 'Упаковочный лист',
        'manual' => 'Вручную',
    ],

];
