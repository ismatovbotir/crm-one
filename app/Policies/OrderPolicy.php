<?php

namespace App\Policies;

use App\Authorization\PermissionMap;
use App\Enums\HoldReason;
use App\Enums\OrderPaymentStatus;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\User;
use App\Services\Orders\OrderAmendmentRules;

/**
 * Spec 06 4.2/4.3 via config/permissions.php (`orders.*`): sales their own orders, sales heads
 * their department, managers, logistics, accountants and finance all (read). Orders are never
 * created by hand (only from an accepted offer) and never deleted.
 */
class OrderPolicy
{
    public function viewAny(User $user): bool
    {
        return PermissionMap::allows($user, 'orders.view');
    }

    public function view(User $user, Order $order): bool
    {
        return PermissionMap::allowsFor($user, 'orders.view', $order->owner_id);
    }

    /** Spec 06 section 31: manual order (sales, sales head, manager; never admin). */
    public function create(User $user): bool
    {
        return PermissionMap::allows($user, 'orders.create');
    }

    public function updateDetails(User $user, Order $order): bool
    {
        return $order->isBeforeFulfilment() && $this->allows($user, $order, 'orders.confirm');
    }

    public function confirm(User $user, Order $order): bool
    {
        return $order->status === OrderStatus::New && $this->allows($user, $order, 'orders.confirm');
    }

    /** Spec 06 section 25: warehouse starts packaging once confirmed (again after a partial delivery). */
    public function startPackaging(User $user, Order $order): bool
    {
        return in_array($order->status, [OrderStatus::Confirmed, OrderStatus::PartiallyDelivered], true) && $this->allows($user, $order, 'orders.pack');
    }

    /** Packaging queue screen (warehouse). */
    public function viewPackaging(User $user): bool
    {
        return PermissionMap::allows($user, 'orders.pack');
    }

    /** Override the sale type while the order is new (section 25, DEC-25.2). */
    public function changeSaleType(User $user, Order $order): bool
    {
        return $order->isBeforeFulfilment() && $order->paid_minor === 0 && $this->allows($user, $order, 'orders.confirm');
    }

    /**
     * Owner (sales) only while `new`; sales head up to confirmed; manager while nothing delivered.
     */
    public function cancel(User $user, Order $order): bool
    {
        if (! $order->status->canTransitionTo(OrderStatus::Cancelled) || ! $this->allows($user, $order, 'orders.cancel')) {
            return false;
        }

        if (PermissionMap::matchesGrant($user, 'role:manager')) {
            return true;
        }

        if (PermissionMap::matchesGrant($user, 'dept:sales:head')) {
            return in_array($order->status, [OrderStatus::New, OrderStatus::Confirmed], true);
        }

        return $order->status === OrderStatus::New;
    }

    /**
     * Credit and overdue: finance, or a manager on someone else's order; manual: finance only;
     * agreement and prepayment: finance or manager.
     */
    public function overrideHold(User $user, Order $order, ?HoldReason $reason = null): bool
    {
        if ($order->status->isFinal() || ! PermissionMap::allows($user, 'orders.override_hold')) {
            return false;
        }

        $controller = PermissionMap::allows($user, 'accounting.credit');

        return match ($reason) {
            HoldReason::Manual => $controller,
            HoldReason::CreditLimit, HoldReason::Overdue => $controller || $order->owner_id !== $user->id,
            default => true,
        };
    }

    public function waiveAgreement(User $user, Order $order): bool
    {
        return ! $order->status->isFinal() && $order->agreement_required && PermissionMap::allows($user, 'orders.waive_agreement');
    }

    /** Spec 06 section 28: tick "power of attorney received". */
    public function tickPoa(User $user, Order $order): bool
    {
        return ! $order->status->isFinal() && $order->poa_required && $order->poa_received_at === null && PermissionMap::allowsFor($user, 'orders.poa_tick', $order->owner_id);
    }

    public function untickPoa(User $user, Order $order): bool
    {
        return ! $order->status->isFinal() && $order->poa_received_at !== null && PermissionMap::allows($user, 'orders.poa_untick');
    }

    public function changePoaRequirement(User $user, Order $order): bool
    {
        return ! $order->status->isFinal() && ! $order->sale_type->isRetail() && PermissionMap::allows($user, 'orders.poa_requirement');
    }

    public function overridePoa(User $user, Order $order): bool
    {
        return ! $order->status->isFinal() && ! $order->poaSatisfied() && PermissionMap::allows($user, 'orders.poa_override');
    }

    public function linkFramework(User $user, Order $order): bool
    {
        return ! $order->status->isFinal() && PermissionMap::allows($user, 'agreements.prepare');
    }

    public function viewPayments(User $user, Order $order): bool
    {
        return $this->view($user, $order) && PermissionMap::allowsFor($user, 'payments.view', $order->owner_id);
    }

    /** Spec 06 6.4: request an amendment of a started order. */
    public function amend(User $user, Order $order): bool
    {
        return in_array($order->status, OrderAmendmentRules::AMENDABLE, true) && PermissionMap::allowsFor($user, 'orders.amend', $order->owner_id);
    }

    public function amendSchedule(User $user, Order $order): bool
    {
        return ! $order->status->isFinal() && PermissionMap::allows($user, 'orders.amend_schedule');
    }

    /** B2B payment document (section 25): proforma. */
    public function issueProforma(User $user, Order $order): bool
    {
        return ! $order->sale_type->isRetail() && ! in_array($order->status, [OrderStatus::New, OrderStatus::Cancelled, OrderStatus::Closed], true) && PermissionMap::allows($user, 'invoices.issue');
    }

    /** Retail payment document (section 25): sales receipt once the order is fully paid, one per order. */
    public function issueReceipt(User $user, Order $order): bool
    {
        return $order->sale_type->isRetail()
            && ! in_array($order->status, [OrderStatus::New, OrderStatus::Cancelled], true)
            && $order->payment_status === OrderPaymentStatus::Paid
            && ! $order->hasIssuedReceipt()
            && PermissionMap::allows($user, 'invoices.issue');
    }

    /** B2B only: tax documents for delivered quantities (retail sells against the receipt). */
    public function registerTaxDocument(User $user, Order $order): bool
    {
        return ! $order->sale_type->isRetail() && in_array($order->status, [OrderStatus::Packed, OrderStatus::PartiallyDelivered, OrderStatus::Delivered], true) && PermissionMap::allows($user, 'invoices.register_tax_doc');
    }

    public function close(User $user, Order $order): bool
    {
        $closable = $order->status === OrderStatus::Invoiced || ($order->status === OrderStatus::Delivered && $order->sale_type->isRetail());

        return $closable && PermissionMap::allows($user, 'orders.close');
    }

    public function print(User $user, Order $order): bool
    {
        return $this->view($user, $order);
    }

    public function viewActivity(User $user, Order $order): bool
    {
        return $this->view($user, $order) && PermissionMap::allows($user, 'activity.view');
    }

    public function delete(User $user, Order $order): bool
    {
        return false;
    }

    public function forceDelete(User $user, Order $order): bool
    {
        return false;
    }

    private function allows(User $user, Order $order, string $ability): bool
    {
        return PermissionMap::allowsFor($user, $ability, $order->owner_id);
    }
}
