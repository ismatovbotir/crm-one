<?php

namespace App\Enums;

/**
 * Events of the notification center (roadmap step 4) with the default channels. In-app is always
 * on; e-mail and Telegram follow the user's choice, else these defaults (Telegram only when the
 * account is linked).
 */
enum NotificationEvent: string
{
    case ApprovalRequested = 'approval_requested';
    case LeadCreated = 'lead_created';
    case LeadConverted = 'lead_converted';
    case FollowUpDue = 'follow_up_due';
    case PaymentReceived = 'payment_received';
    case TenderDeadline = 'tender_deadline';
    case OfferExpiring = 'offer_expiring';
    case TelegramBotRemoved = 'telegram_bot_removed';
    case ServiceTicket = 'service_ticket';
    case WarrantyExpiring = 'warranty_expiring';
    case Requisition = 'requisition';
    case ShippedUnpaid = 'shipped_unpaid';
    case AccountSecurity = 'account_security';
    case PaymentReminder = 'payment_reminder';
    case PaymentOverdue = 'payment_overdue';

    public const CHANNELS = ['mail', 'telegram'];

    public function defaultFor(string $channel): bool
    {
        return match ($channel) {
            'mail' => in_array($this, [self::AccountSecurity, self::ApprovalRequested, self::ServiceTicket, self::WarrantyExpiring, self::Requisition, self::PaymentOverdue], true),
            'telegram' => in_array($this, [self::ApprovalRequested, self::LeadCreated, self::LeadConverted, self::FollowUpDue, self::TelegramBotRemoved, self::ServiceTicket, self::Requisition, self::ShippedUnpaid], true),
            default => true,
        };
    }

    public function label(): string
    {
        return __('notifications.events.'.$this->value);
    }
}
