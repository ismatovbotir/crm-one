<?php

namespace App\Policies;

use App\Authorization\PermissionMap;
use App\Models\AgreementTemplate;
use App\Models\User;

/**
 * Agreement templates (Spec 06 4.2): managers and finance edit and publish (`agreement_templates.manage`).
 */
class AgreementTemplatePolicy
{
    public function viewAny(User $user): bool
    {
        return PermissionMap::allows($user, 'agreement_templates.manage');
    }

    public function manage(User $user): bool
    {
        return PermissionMap::allows($user, 'agreement_templates.manage');
    }

    public function update(User $user, AgreementTemplate $template): bool
    {
        return $this->manage($user);
    }

    public function delete(User $user, AgreementTemplate $template): bool
    {
        return false;
    }
}
