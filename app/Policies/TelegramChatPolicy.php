<?php

namespace App\Policies;

use App\Authorization\PermissionMap;
use App\Models\TelegramChat;
use App\Models\User;

/**
 * Telegram chats (Spec 04 section 17). Reading follows what the chat belongs to: the linked
 * customer, else its lead, else (unlinked, no lead) only users who may assign leads or admins
 * (system view of the bot's chats). Changing links, merging, refreshing members and creating
 * contacts need `leads.update` plus rights on the customer involved; admins only read.
 */
class TelegramChatPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin() || PermissionMap::allows($user, 'leads.update') || PermissionMap::allows($user, 'customers.create');
    }

    public function view(User $user, TelegramChat $chat): bool
    {
        if (! $user->isInternal()) {
            return false;
        }

        if ($user->isAdmin()) {
            return true;
        }

        if ($chat->customer !== null) {
            return $user->can('view', $chat->customer);
        }

        if ($chat->lead !== null) {
            return $user->can('view', $chat->lead);
        }

        return PermissionMap::allows($user, 'leads.assign');
    }

    /** Link to a customer, unlink, refresh members, merge duplicates. */
    public function manage(User $user, TelegramChat $chat): bool
    {
        return $chat->isGroup() && ! $chat->isInternal() && ! $user->isAdmin() && PermissionMap::allows($user, 'leads.update') && $this->view($user, $chat);
    }

    /** Create customer contacts (and optionally portal users) from members of a linked group. */
    public function createContacts(User $user, TelegramChat $chat): bool
    {
        return $this->manage($user, $chat) && $chat->customer !== null && $user->can('update', $chat->customer);
    }
}
