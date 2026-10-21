<?php

namespace App\Policies;

use App\Authorization\PermissionMap;
use App\Models\ServiceTicket;
use App\Models\User;

/**
 * Staff access to service tickets (Spec 09 §7): the service team works the queue (`service.manage`),
 * managers and admins read (`service.view`), the sales owner sees their customers' tickets.
 */
class ServiceTicketPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isInternal() && PermissionMap::allows($user, 'service.view');
    }

    public function view(User $user, ServiceTicket $ticket): bool
    {
        return $user->isInternal() && (PermissionMap::allowsFor($user, 'service.view', $ticket->organization?->owner_id) || PermissionMap::allowsFor($user, 'service.register', $ticket->organization?->owner_id));
    }

    public function manage(User $user, ServiceTicket $ticket): bool
    {
        return $user->isInternal() && PermissionMap::allows($user, 'service.manage');
    }

    public function create(User $user): bool
    {
        return $user->isInternal() && PermissionMap::allows($user, 'service.manage');
    }
}
