<?php

namespace App\Policies;

use App\Authorization\PermissionMap;
use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\User;

/**
 * Spec 06 4.2/4.3 (`invoices.*`): accountants issue proformas and register tax documents, finance
 * approves voids (not the requester); sales see invoices of their orders.
 */
class InvoicePolicy
{
    public function viewAny(User $user): bool
    {
        return PermissionMap::allows($user, 'invoices.view');
    }

    public function view(User $user, Invoice $invoice): bool
    {
        return PermissionMap::allowsFor($user, 'invoices.view', $invoice->order?->owner_id);
    }

    public function print(User $user, Invoice $invoice): bool
    {
        return $this->view($user, $invoice);
    }

    public function requestVoid(User $user, Invoice $invoice): bool
    {
        return $invoice->status === InvoiceStatus::Issued && $invoice->void_requested_at === null
            && PermissionMap::allows($user, $invoice->type->isTaxDocument() ? 'invoices.register_tax_doc' : 'invoices.issue');
    }

    public function approveVoid(User $user, Invoice $invoice): bool
    {
        return $invoice->status === InvoiceStatus::Issued && $invoice->void_requested_at !== null
            && $invoice->void_requested_by !== $user->id && PermissionMap::allows($user, 'invoices.void_approve');
    }

    public function updateEsf(User $user, Invoice $invoice): bool
    {
        return $invoice->type->isTaxDocument() && $invoice->status === InvoiceStatus::Issued && PermissionMap::allows($user, 'invoices.register_tax_doc');
    }

    public function delete(User $user, Invoice $invoice): bool
    {
        return false;
    }
}
