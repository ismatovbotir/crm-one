<?php

namespace App\Support;

use App\Enums\AgreementStatus;
use App\Enums\InvoiceStatus;
use App\Enums\OfferStatus;
use App\Models\Agreement;
use App\Models\CustomerAsset;
use App\Models\Invoice;
use App\Models\ItemSerial;
use App\Models\Offer;
use App\Models\Order;
use App\Models\Requisition;
use App\Models\ServiceTicket;
use Illuminate\Database\Eloquent\Builder;

/**
 * Company-scoped queries of the customer portal (Spec 03 AC-13, DEC-13): every portal list and
 * record lookup goes through these, so another company's records simply do not exist (404).
 * Internal-only states (offer drafts and approvals, agreement drafts) are never shown.
 */
final class PortalScope
{
    public function __construct(private PortalContext $context) {}

    /**
     * @return Builder<Offer>
     */
    public function offers(): Builder
    {
        return Offer::query()->where('customer_id', $this->context->customerId())
            ->whereIn('status', [OfferStatus::Sent, OfferStatus::Viewed, OfferStatus::PartiallySelected, OfferStatus::Accepted, OfferStatus::Rejected, OfferStatus::Expired]);
    }

    /**
     * @return Builder<Order>
     */
    public function orders(): Builder
    {
        return Order::query()->where('customer_id', $this->context->customerId());
    }

    /**
     * @return Builder<Agreement>
     */
    public function agreements(): Builder
    {
        return Agreement::query()->where('customer_id', $this->context->customerId())
            ->whereIn('status', [AgreementStatus::SentForSignature, AgreementStatus::Signed, AgreementStatus::Active, AgreementStatus::Expired, AgreementStatus::Terminated, AgreementStatus::Renewed]);
    }

    /**
     * @return Builder<Invoice>
     */
    public function invoices(): Builder
    {
        return Invoice::query()->where('customer_id', $this->context->customerId())->where('status', InvoiceStatus::Issued);
    }

    /**
     * Serials sold to this company (warranty lookup).
     *
     * @return Builder<ItemSerial>
     */
    public function soldSerials(): Builder
    {
        return ItemSerial::query()->where('customer_id', $this->context->customerId())->whereNotNull('sold_at');
    }

    /**
     * Equipment of this company (Spec 09; tenant isolation).
     *
     * @return Builder<CustomerAsset>
     */
    public function assets(): Builder
    {
        return CustomerAsset::query()->where('partner_id', $this->context->customerId());
    }

    /**
     * Service tickets of this company.
     *
     * @return Builder<ServiceTicket>
     */
    public function serviceTickets(): Builder
    {
        return ServiceTicket::query()->where('partner_id', $this->context->customerId());
    }

    /**
     * Requests (requisitions) of this company (Spec 10; tenant isolation). Role filtering
     * (requesters see their own) is applied by the screens.
     *
     * @return Builder<Requisition>
     */
    public function requisitions(): Builder
    {
        return Requisition::query()->where('partner_id', $this->context->customerId());
    }
}
