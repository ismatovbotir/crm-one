<?php

/*
| Notification center (roadmap step 4).
*/

return [

    'title' => 'Уведомления',
    'back' => 'Все уведомления',
    'open' => 'Открыть',
    'mark_read' => 'Отметить прочитанным',
    'mark_all_read' => 'Отметить все прочитанными',
    'only_unread' => 'Только непрочитанные',
    'unread' => 'не прочитано',
    'unread_count' => '{0} нет непрочитанных|[1,*] непрочитанных: :count',
    'empty' => 'Уведомлений нет.',
    'channels' => [
        'database' => 'В CRM',
        'mail' => 'E-mail',
        'telegram' => 'Telegram',
    ],
    'kinds' => [
        'offer' => 'скидка в предложении',
        'tender_publication' => 'публикация тендера',
        'tender_award' => 'итоги тендера',
    ],
    'events' => [
        'payment_reminder' => 'Скоро срок оплаты',
        'payment_overdue' => 'Просрочена оплата',
        'shipped_unpaid' => 'Отгружен неоплаченный заказ',
        'account_security' => 'Безопасность аккаунта',
        'lead_converted' => 'Лид переведён в клиенты',
        'requisition' => 'Заявки клиентов',
        'service_ticket' => 'Заявки на ремонт',
        'warranty_expiring' => 'Скоро заканчивается гарантия',
        'telegram_bot_removed' => 'Бот удалён из группы Telegram',
        'approval_requested' => 'Запрос на согласование',
        'lead_created' => 'Новый лид для меня',
        'follow_up_due' => 'Наступил срок действия',
        'payment_received' => 'Оплата по моему заказу',
        'tender_deadline' => 'Тендер закрывается в течение 24 часов',
        'offer_expiring' => 'Предложение истекает через 2 дня',
    ],
    'messages' => [
        'payment_reminder' => [
            'title' => 'Оплата по заказу :number — срок :date',
            'body' => ':customer: к оплате :amount.',
        ],
        'payment_overdue' => [
            'title' => 'Заказ :number: оплата просрочена',
            'body' => ':customer: :amount, срок :date (просрочено :days дн.).',
        ],
        'payment_overdue_digest' => [
            'title' => 'Новых просроченных платежей: :count',
            'body' => 'Откройте «Дебиторскую задолженность», чтобы увидеть список.',
        ],
        'shipped_unpaid' => [
            'title' => 'Заказ :number отгружен без полной оплаты',
            'body' => ':customer, накладная :delivery: оплачено :paid из :total.',
        ],
        'telegram_link_linked_pending' => [
            'title' => 'К аккаунту привязан Telegram',
            'body' => ':by привязал(а) к вам аккаунт Telegram :username. Подтвердите в «Моём кабинете»; до этого уведомления не приходят.',
        ],
        'telegram_link_confirmed' => [
            'title' => 'Telegram подтверждён',
            'body' => 'Аккаунт Telegram :username подтверждён для вашей учётной записи.',
        ],
        'telegram_link_verified_in_person' => [
            'title' => 'Telegram подтверждён лично',
            'body' => ':by лично подтвердил(а) аккаунт Telegram :username для вас.',
        ],
        'telegram_link_unlinked' => [
            'title' => 'Telegram отвязан',
            'body' => 'Аккаунт Telegram :username отвязан от вашей учётной записи (:by).',
        ],
        'service_stock_arrived' => [
            'title' => 'Поступил товар для заявок в очереди',
            'body' => 'Приход :receipt привёз товары, которых ждут заявки: :tickets.',
        ],
        'lead_converted' => [
            'title' => 'Лид :number переведён',
            'body' => ':user перевёл лид в клиента :name.',
        ],
        'requisition_submitted' => [
            'title' => 'Заявка :number отправлена',
            'body' => 'Позиции (:lines) ждут вашей проверки.',
        ],
        'requisition_returned' => [
            'title' => 'Заявка :number возвращена',
            'body' => 'Менеджер по закупкам вернул заявку с комментарием.',
        ],
        'requisition_approved' => [
            'title' => 'Заявка :number согласована',
            'body' => 'Менеджер по закупкам согласовал заявку.',
        ],
        'requisition_rejected' => [
            'title' => 'Заявка :number отклонена',
            'body' => 'Менеджер по закупкам отклонил заявку.',
        ],
        'requisition_received' => [
            'title' => 'Новая заявка клиента :number',
            'body' => ':customer отправил позиций: :lines.',
        ],
        'requisition_offered' => [
            'title' => 'КП по заявке :number готово',
            'body' => 'Откройте заявку, чтобы увидеть КП.',
        ],
        'requisition_clarification' => [
            'title' => 'Вопрос по заявке :number',
            'body' => 'Поставщик задал вопрос по вашей заявке.',
        ],
        'requisition_customer_comment' => [
            'title' => 'Комментарий клиента по :number',
            'body' => ':customer оставил комментарий.',
        ],
        'requisition_declined' => [
            'title' => 'По заявке :number отказано',
            'body' => 'Поставщик отказал по заявке.',
        ],
        'service_ticket' => [
            'title' => 'Заявка на ремонт :number',
            'body' => ':customer · :asset · :status',
        ],
        'service_ticket_customer' => [
            'title' => 'Заявка на ремонт :number: :status',
            'body' => ':asset. :note',
        ],
        'service_claim_refused' => [
            'title' => 'Отклонена регистрация оборудования',
            'body' => ':claimant пытался зарегистрировать серийный номер :serial (владелец: :owner).',
        ],
        'warranty_expiring' => [
            'title' => 'Гарантия заканчивается :date',
            'body' => ':asset (:customer)',
        ],
        'telegram_bot_removed' => [
            'title' => 'Бот удалён из :group',
            'body' => 'Сообщения этой группы больше не поступают в CRM. Попросите группу снова добавить бота.',
        ],
        'approval_requested' => [
            'title' => 'Нужно согласование: :what :number',
            'body' => ':requester просит вашего решения.',
        ],
        'lead_created' => [
            'title' => 'Новый лид :number',
            'body' => ':name (:source) назначен вам.',
        ],
        'follow_up_due' => [
            'title' => 'Срок действия по лиду :number',
            'body' => ':note',
        ],
        'payment_received' => [
            'title' => 'Оплата по заказу :number',
            'body' => 'Распределено :amount; статус оплаты: :status.',
        ],
        'tender_deadline' => [
            'title' => 'Тендер :number скоро закрывается',
            'body' => ':title: приём предложений до :at.',
        ],
        'offer_expiring' => [
            'title' => 'Предложение :number скоро истекает',
            'body' => ':customer: действует до :date.',
        ],
    ],
    'preferences' => [
        'title' => 'Настройки уведомлений',
        'intro' => 'Выберите, как сообщать о каждом событии. Уведомления в CRM включены всегда.',
        'event' => 'Событие',
        'always' => 'всегда включено',
        'telegram_not_linked' => 'Чтобы получать уведомления в Telegram, привяжите аккаунт:',
        'saved' => 'Настройки уведомлений сохранены.',
    ],

];
