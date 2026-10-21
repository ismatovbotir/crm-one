<?php

namespace App\Actions\Customers;

use App\Enums\CustomerPortalRole;
use App\Enums\Locale;
use App\Enums\UserType;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\Partner;
use App\Models\PortalMembership;
use App\Models\User;
use App\Support\CompanyProfile;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/**
 * Customer portal access (Spec 03 4.7, BR-O.3, AC-14): switch the portal on for a customer, give a
 * colleague access (portal user without password + membership with roles; Phase 1 one active
 * company per user), change roles, deactivate (never the last active portal admin when a portal
 * user acts). Access is announced by e-mail with the portal login address; the OTP login itself
 * proves control of the mailbox. Deactivation takes effect on the next request (BR-A.3).
 */
class ManagePortalAccess
{
    public function configure(Partner $customer, bool $enabled, ?int $thresholdMinor): void
    {
        $customer->forceFill(['portal_enabled' => $enabled, 'portal_approval_threshold_minor' => $thresholdMinor])->save();
    }

    /**
     * @param  list<string>  $roles
     */
    public function grant(Partner $customer, string $email, string $name, array $roles, ?int $contactId, ?int $approvalLimitMinor, User $by): PortalMembership
    {
        $email = mb_strtolower(trim($email));
        $roles = array_values(array_intersect($roles, array_map(fn (CustomerPortalRole $r): string => $r->value, CustomerPortalRole::cases())));

        if ($roles === []) {
            throw ValidationException::withMessages(['roles' => __('portal.users.errors.roles_required')]);
        }

        if ($contactId !== null && ! Contact::query()->whereKey($contactId)->where('partner_id', $customer->id)->exists()) {
            throw ValidationException::withMessages(['contact' => __('portal.users.errors.contact_foreign')]);
        }

        $membership = DB::transaction(function () use ($customer, $email, $name, $roles, $contactId, $approvalLimitMinor, $by): PortalMembership {
            $user = User::query()->where('email', $email)->lockForUpdate()->first();

            if ($user !== null && ! $user->isPortal()) {
                throw ValidationException::withMessages(['email' => __('portal.users.errors.email_internal')]);
            }

            if ($user !== null && $user->portalMemberships()->where('is_active', true)->where('customer_id', '!=', $customer->id)->exists()) {
                throw ValidationException::withMessages(['email' => __('portal.users.errors.other_company')]);
            }

            if ($user === null) {
                $user = new User(['name' => trim($name), 'email' => $email, 'is_active' => true]);
                $user->type = UserType::Portal;
                $user->password = null;
                $user->save();
            } else {
                $user->forceFill(['is_active' => true, 'deactivated_at' => null])->save();
            }

            return PortalMembership::query()->updateOrCreate(['user_id' => $user->id, 'customer_id' => $customer->id], [
                'contact_id' => $contactId,
                'roles' => $roles,
                'approval_limit_minor' => $approvalLimitMinor,
                'is_active' => true,
                'invited_by' => $by->id,
                'activated_at' => now(),
                'deactivated_at' => null,
                'deactivated_by' => null,
            ]);
        });

        // Spec 04 section 18.5: granting access settles a pending invitation from lead conversion.
        Contact::query()->where('partner_id', $customer->id)->whereNotNull('portal_invite_requested_at')
            ->where(fn ($query) => $query->when($contactId !== null, fn ($q) => $q->whereKey($contactId))->orWhere('email', $email))
            ->update(['portal_invite_requested_at' => null, 'portal_invite_requested_by' => null]);

        $customer->logActivity('portal_access_granted', ['email' => $email, 'roles' => $roles]);
        $this->announce($membership->user, $customer);

        return $membership;
    }

    /**
     * @param  list<string>  $roles
     */
    public function updateRoles(PortalMembership $membership, array $roles, ?int $approvalLimitMinor, User $by): void
    {
        $roles = array_values(array_intersect($roles, array_map(fn (CustomerPortalRole $r): string => $r->value, CustomerPortalRole::cases())));

        if ($roles === []) {
            throw ValidationException::withMessages(['roles' => __('portal.users.errors.roles_required')]);
        }

        if ($by->isPortal() && ! in_array(CustomerPortalRole::PortalAdmin->value, $roles, true) && $this->isLastAdmin($membership)) {
            throw ValidationException::withMessages(['roles' => __('portal.users.errors.last_admin')]);
        }

        $membership->update(['roles' => $roles, 'approval_limit_minor' => $approvalLimitMinor]);
    }

    public function deactivate(PortalMembership $membership, User $by): void
    {
        if ($by->isPortal() && $this->isLastAdmin($membership)) {
            throw ValidationException::withMessages(['membership' => __('portal.users.errors.last_admin')]);
        }

        DB::transaction(function () use ($membership, $by): void {
            $membership->update(['is_active' => false, 'deactivated_at' => now(), 'deactivated_by' => $by->id]);
            $user = $membership->user;

            if (! $user->portalMemberships()->where('is_active', true)->exists()) {
                $user->forceFill(['is_active' => false, 'deactivated_at' => now(), 'remember_token' => null])->save();
            }
        });

        $membership->customer?->logActivity('portal_access_revoked', ['email' => $membership->user?->email]);
    }

    protected function isLastAdmin(PortalMembership $membership): bool
    {
        if (! $membership->hasRole(CustomerPortalRole::PortalAdmin) || ! $membership->is_active) {
            return false;
        }

        return PortalMembership::query()->where('customer_id', $membership->customer_id)->where('is_active', true)->whereKeyNot($membership->id)
            ->get()->doesntContain(fn (PortalMembership $other): bool => $other->hasRole(CustomerPortalRole::PortalAdmin));
    }

    protected function announce(User $user, Partner $customer): void
    {
        $locale = Locale::fromAvailable($customer->preferred_locale) ?? Locale::default();
        $previous = App::getLocale();
        App::setLocale($locale->value);

        try {
            $company = CompanyProfile::seller($locale)['name'];
            Mail::send('mail.portal-access', ['company' => $company, 'customer' => $customer->name, 'url' => route('portal.login')], fn ($message) => $message->to((string) $user->email)->subject(__('portal.mail.access_subject', ['company' => $company])));
        } finally {
            App::setLocale($previous);
        }
    }
}
