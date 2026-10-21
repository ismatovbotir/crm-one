<?php

namespace App\Policies;

use App\Actions\Stock\ManageGoodsReceipt;
use App\Actions\Stock\TransitionGoodsReceipt;
use App\Authorization\PermissionMap;
use App\Enums\GoodsReceiptStatus;
use App\Enums\ReceiptSource;
use App\Models\GoodsReceipt;
use App\Models\User;

/**
 * Spec 06 21.3 / 26.4: supplier receipts come from 1C; stock managers and the warehouse head
 * complete them (received / damaged quantities, serials), mark them checked and post them, and
 * create only customer returns and opening balances. The warehouse head releases holds, cancels
 * 1C receipts and approves over-receipt. Managers, logistics, accountants and finance read them.
 */
class GoodsReceiptPolicy
{
    public function viewAny(User $user): bool
    {
        return PermissionMap::allows($user, 'stock.view');
    }

    public function view(User $user, GoodsReceipt $receipt): bool
    {
        return $this->viewAny($user);
    }

    /** Creating the CRM kinds (customer return, opening balance); supplier receipts come from 1C. */
    public function create(User $user): bool
    {
        return PermissionMap::allows($user, 'stock.receive');
    }

    public function createKind(User $user, ReceiptSource $source): bool
    {
        return in_array($source, ManageGoodsReceipt::CRM_KINDS, true) && $this->create($user);
    }

    /** Entering received / damaged quantities, notes and serials. */
    public function update(User $user, GoodsReceipt $receipt): bool
    {
        return $receipt->status->isEditable() && PermissionMap::allows($user, 'stock.receive');
    }

    public function transition(User $user, GoodsReceipt $receipt, GoodsReceiptStatus $to): bool
    {
        return TransitionGoodsReceipt::allows($user, $receipt, $to);
    }

    public function post(User $user, GoodsReceipt $receipt): bool
    {
        return $this->transition($user, $receipt, GoodsReceiptStatus::Posted);
    }

    /** Clearing a 1C review flag (update after posting, cancel request with serials). */
    public function resolveReview(User $user, GoodsReceipt $receipt): bool
    {
        return $receipt->needs_review && PermissionMap::allows($user, 'stock.adjust_approve');
    }

    public function print(User $user, GoodsReceipt $receipt): bool
    {
        return $this->view($user, $receipt);
    }

    public function delete(User $user, GoodsReceipt $receipt): bool
    {
        return false;
    }
}
