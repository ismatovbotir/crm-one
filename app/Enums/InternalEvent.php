<?php

namespace App\Enums;

/**
 * Events the bot can post to company groups (Spec 03 section 23.2), grouped by category for
 * routing to destination groups. Status-driven events come from `status_transitions`
 * (fromTransition); the rest are published by their action or the scheduler.
 */
enum InternalEvent: string
{
    case LeadCreated = 'lead.created';
    case LeadTelegram = 'lead.telegram';
    case RequisitionReceived = 'requisition.received';
    case OfferSent = 'offer.sent';
    case OfferAccepted = 'offer.accepted';
    case OfferRejected = 'offer.rejected';
    case OrderCreated = 'order.created';
    case OrderConfirmed = 'order.confirmed';
    case OrderPackaged = 'order.packaged';
    case OrderDelivered = 'order.delivered';
    case PaymentReceived = 'payment.received';
    case InvoiceOverdue = 'invoice.overdue';
    case ServiceTicketOpened = 'service_ticket.opened';
    case ServiceTicketClosed = 'service_ticket.closed';
    case TenderCreated = 'tender.created';
    case TenderDeadline = 'tender.deadline';
    case TenderAwardApproval = 'tender.award_approval';
    case GoodsReceiptPosted = 'goods_receipt.posted';
    case ReceiptDiscrepancy = 'goods_receipt.discrepancy';
    case ReportDaily = 'report.daily';
    case ReportWeekly = 'report.weekly';

    public const CATEGORIES = ['leads', 'requests', 'offers', 'orders', 'finance', 'service', 'procurement', 'warehouse', 'reports'];

    public function category(): string
    {
        return match ($this) {
            self::LeadCreated, self::LeadTelegram => 'leads',
            self::RequisitionReceived => 'requests',
            self::OfferSent, self::OfferAccepted, self::OfferRejected => 'offers',
            self::OrderCreated, self::OrderConfirmed, self::OrderPackaged, self::OrderDelivered => 'orders',
            self::PaymentReceived, self::InvoiceOverdue => 'finance',
            self::ServiceTicketOpened, self::ServiceTicketClosed => 'service',
            self::TenderCreated, self::TenderDeadline, self::TenderAwardApproval => 'procurement',
            self::GoodsReceiptPosted, self::ReceiptDiscrepancy => 'warehouse',
            self::ReportDaily, self::ReportWeekly => 'reports',
        };
    }

    public function isReport(): bool
    {
        return $this->category() === 'reports';
    }

    public function label(?string $locale = null): string
    {
        return __('telegram.internal.events.'.str_replace('.', '_', $this->value), [], $locale);
    }

    /**
     * The event for a status change, or null when it is not posted.
     *
     * @param  array<string, mixed>  $context  e.g. ['source' => 'telegram'] for leads
     */
    public static function fromTransition(string $subjectType, ?string $from, string $to, array $context = []): ?self
    {
        return match (true) {
            $subjectType === 'lead' && $from === null => ($context['source'] ?? null) === 'telegram' ? self::LeadTelegram : self::LeadCreated,
            $subjectType === 'requisition' && $to === 'received' => self::RequisitionReceived,
            $subjectType === 'offer' && $to === 'sent' => self::OfferSent,
            $subjectType === 'offer' && $to === 'accepted' => self::OfferAccepted,
            $subjectType === 'offer' && $to === 'rejected' => self::OfferRejected,
            $subjectType === 'order' && $from === null => self::OrderCreated,
            $subjectType === 'order' && $to === 'confirmed' => self::OrderConfirmed,
            $subjectType === 'order' && $to === 'packed' => self::OrderPackaged,
            $subjectType === 'order' && $to === 'delivered' => self::OrderDelivered,
            $subjectType === 'service_ticket' && $from === null => self::ServiceTicketOpened,
            $subjectType === 'service_ticket' && $to === 'closed' => self::ServiceTicketClosed,
            $subjectType === 'tender' && $to === 'published' => self::TenderCreated,
            default => null,
        };
    }
}
