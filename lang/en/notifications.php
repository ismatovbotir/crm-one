<?php

/*
| Notification center (roadmap step 4).
*/

return [

    'title' => 'Notifications',
    'back' => 'All notifications',
    'open' => 'Open',
    'mark_read' => 'Mark as read',
    'mark_all_read' => 'Mark all as read',
    'only_unread' => 'Unread only',
    'unread' => 'unread',
    'unread_count' => '{0} no unread|{1} :count unread|[2,*] :count unread',
    'empty' => 'No notifications.',
    'channels' => [
        'database' => 'In the CRM',
        'mail' => 'E-mail',
        'telegram' => 'Telegram',
    ],
    'kinds' => [
        'offer' => 'offer discount',
        'tender_publication' => 'tender publication',
        'tender_award' => 'tender award',
    ],
    'events' => [
        'payment_reminder' => 'Payment coming due',
        'payment_overdue' => 'Payment overdue',
        'shipped_unpaid' => 'Unpaid order shipped',
        'account_security' => 'Account security',
        'lead_converted' => 'Lead converted to a customer',
        'requisition' => 'Customer requests',
        'service_ticket' => 'Repair requests',
        'warranty_expiring' => 'Warranty ending soon',
        'telegram_bot_removed' => 'Bot removed from a Telegram group',
        'approval_requested' => 'Approval requested',
        'lead_created' => 'New lead for me',
        'follow_up_due' => 'Follow-up due',
        'payment_received' => 'Payment received on my order',
        'tender_deadline' => 'Tender closes within 24 hours',
        'offer_expiring' => 'Offer expires within 2 days',
    ],
    'messages' => [
        'payment_reminder' => [
            'title' => 'Payment for order :number is due on :date',
            'body' => ':customer: :amount to pay.',
        ],
        'payment_overdue' => [
            'title' => 'Order :number: payment is overdue',
            'body' => ':customer: :amount, due :date (:days days overdue).',
        ],
        'payment_overdue_digest' => [
            'title' => ':count payment(s) newly overdue',
            'body' => 'Open Receivables to see the list.',
        ],
        'shipped_unpaid' => [
            'title' => 'Order :number shipped without full payment',
            'body' => ':customer, delivery note :delivery: paid :paid of :total.',
        ],
        'telegram_link_linked_pending' => [
            'title' => 'Telegram linked to your account',
            'body' => ':by linked the Telegram account :username to you. Confirm it in My cabinet; until then it gets no notifications.',
        ],
        'telegram_link_confirmed' => [
            'title' => 'Telegram confirmed',
            'body' => 'The Telegram account :username is confirmed for your account.',
        ],
        'telegram_link_verified_in_person' => [
            'title' => 'Telegram verified in person',
            'body' => ':by verified the Telegram account :username for your account in person.',
        ],
        'telegram_link_unlinked' => [
            'title' => 'Telegram unlinked',
            'body' => 'The Telegram account :username was unlinked from your account (by :by).',
        ],
        'service_stock_arrived' => [
            'title' => 'Stock arrived for queued requests',
            'body' => 'Goods receipt :receipt brought items that these requests wait for: :tickets.',
        ],
        'lead_converted' => [
            'title' => 'Lead :number converted',
            'body' => ':user converted the lead to the customer :name.',
        ],
        'requisition_submitted' => [
            'title' => 'Request :number submitted',
            'body' => ':lines lines are waiting for your review.',
        ],
        'requisition_returned' => [
            'title' => 'Request :number returned',
            'body' => 'Your purchase manager returned the request with a comment.',
        ],
        'requisition_approved' => [
            'title' => 'Request :number approved',
            'body' => 'Your purchase manager approved the request.',
        ],
        'requisition_rejected' => [
            'title' => 'Request :number rejected',
            'body' => 'Your purchase manager rejected the request.',
        ],
        'requisition_received' => [
            'title' => 'New customer request :number',
            'body' => ':customer sent :lines lines.',
        ],
        'requisition_offered' => [
            'title' => 'Offer ready for request :number',
            'body' => 'Open the request to see the offer.',
        ],
        'requisition_clarification' => [
            'title' => 'Question about request :number',
            'body' => 'The supplier asked a question about your request.',
        ],
        'requisition_customer_comment' => [
            'title' => 'Customer comment on :number',
            'body' => ':customer wrote a comment.',
        ],
        'requisition_declined' => [
            'title' => 'Request :number declined',
            'body' => 'The supplier declined the request.',
        ],
        'service_ticket' => [
            'title' => 'Repair request :number',
            'body' => ':customer · :asset · :status',
        ],
        'service_ticket_customer' => [
            'title' => 'Repair request :number: :status',
            'body' => ':asset. :note',
        ],
        'service_claim_refused' => [
            'title' => 'Refused equipment registration',
            'body' => ':claimant tried to register serial :serial (owner: :owner).',
        ],
        'warranty_expiring' => [
            'title' => 'Warranty ends on :date',
            'body' => ':asset (:customer)',
        ],
        'telegram_bot_removed' => [
            'title' => 'Bot removed from :group',
            'body' => 'Messages from this group no longer reach the CRM. Ask the group to add the bot again.',
        ],
        'approval_requested' => [
            'title' => 'Approval needed: :what :number',
            'body' => ':requester asks for your decision.',
        ],
        'lead_created' => [
            'title' => 'New lead :number',
            'body' => ':name (:source) is assigned to you.',
        ],
        'follow_up_due' => [
            'title' => 'Follow-up due on lead :number',
            'body' => ':note',
        ],
        'payment_received' => [
            'title' => 'Payment received for order :number',
            'body' => ':amount allocated; payment status: :status.',
        ],
        'tender_deadline' => [
            'title' => 'Tender :number closes soon',
            'body' => ':title: bids are due :at.',
        ],
        'offer_expiring' => [
            'title' => 'Offer :number expires soon',
            'body' => ':customer: valid until :date.',
        ],
    ],
    'preferences' => [
        'title' => 'Notification settings',
        'intro' => 'Choose how you are told about each event. In-app notifications are always on.',
        'event' => 'Event',
        'always' => 'always on',
        'telegram_not_linked' => 'Link your Telegram account to receive Telegram notifications:',
        'saved' => 'Notification settings saved.',
    ],

];
