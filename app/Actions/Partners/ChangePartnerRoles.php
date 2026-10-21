<?php

namespace App\Actions\Partners;

use App\Enums\LeadStatus;
use App\Models\Counterparty;
use App\Models\Lead;
use App\Models\Offer;
use App\Models\Order;
use App\Models\Partner;
use App\Models\User;
use App\Policies\PartnerPolicy;
use App\Services\Organizations\OrganizationRoles;
use App\Services\Partners\Exceptions\PartnerException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "Edit roles" on a partner card (Spec 03 section 24.4). Adds or removes the customer, supplier and
 * carrier roles; at least one role stays. Removing is refused while the role has dependent records:
 * customer → offers, orders, open leads; supplier/carrier → tender invitations, bids, awards,
 * purchase orders. Each role needs the user's right for that role. Nothing is deleted: a removed
 * customer role hides the partner from customer screens; a supplier profile without roles is
 * archived (and restored when a role comes back). Audited on the partner.
 */
class ChangePartnerRoles
{
    /** Tables that depend on the supplier profile (counterparty_id). */
    public const SUPPLIER_DEPENDENTS = ['tender_invitations', 'tender_bids', 'tender_awards', 'purchase_orders'];

    public function __construct(protected OrganizationRoles $roles) {}

    /**
     * @param  list<string>  $wanted  customer|supplier|carrier
     */
    public function handle(Partner $partner, array $wanted, User $by): Partner
    {
        $wanted = array_values(array_intersect(CreatePartner::ROLES, $wanted));

        if ($wanted === []) {
            throw new PartnerException('role_required');
        }

        $current = $partner->roles();
        $allowed = app(PartnerPolicy::class)->allowedRoles($by);
        $changed = array_merge(array_diff($wanted, $current), array_diff($current, $wanted));

        foreach ($changed as $role) {
            if (! in_array($role, $allowed, true)) {
                throw new PartnerException('role_not_allowed', ['role' => __('partners.roles.'.$role)]);
            }
        }

        if ($changed === []) {
            return $partner;
        }

        return DB::transaction(function () use ($partner, $wanted, $current, $by): Partner {
            $fresh = Partner::withTrashed()->lockForUpdate()->findOrFail($partner->id);

            // Customer role.
            if (in_array('customer', $current, true) && ! in_array('customer', $wanted, true)) {
                $this->assertNoCustomerDependents($fresh);
                $fresh->forceFill(['is_customer' => false])->save();
            } elseif (! in_array('customer', $current, true) && in_array('customer', $wanted, true)) {
                $this->roles->addCustomerRole($fresh, $by);
            }

            // Supplier / carrier roles live on the supplier profile.
            $supplier = in_array('supplier', $wanted, true);
            $carrier = in_array('carrier', $wanted, true);
            $profile = Counterparty::withTrashed()->where('partner_id', $fresh->id)->first();

            if (($supplier || $carrier) && ($profile === null || $profile->trashed() || $profile->is_supplier !== $supplier || $profile->is_carrier !== $carrier)) {
                $profile = $this->roles->addSupplierRole(Partner::withTrashed()->findOrFail($fresh->id), ['is_supplier' => $supplier, 'is_carrier' => $carrier], $by);
            }

            if ($profile !== null && ! $profile->trashed()) {
                $removing = ($profile->is_supplier && ! $supplier) || ($profile->is_carrier && ! $carrier);

                if ($removing && ! $supplier && ! $carrier) {
                    $this->assertNoSupplierDependents($profile);
                    $profile->forceFill(['is_supplier' => false, 'is_carrier' => false])->save();
                    $profile->delete();
                } elseif ($removing) {
                    $profile->forceFill(['is_supplier' => $supplier, 'is_carrier' => $carrier])->save();
                }
            }

            $result = Partner::withTrashed()->findOrFail($fresh->id);
            $result->logActivity('roles_changed', ['old' => ['roles' => $current], 'new' => ['roles' => $result->roles()], 'by' => $by->name]);

            return $result;
        });
    }

    protected function assertNoCustomerDependents(Partner $partner): void
    {
        $counts = [
            'offers' => Offer::query()->where('customer_id', $partner->id)->count(),
            'orders' => Order::query()->where('customer_id', $partner->id)->count(),
            'leads' => Lead::query()->where('customer_id', $partner->id)->whereIn('status', LeadStatus::openValues())->count(),
        ];

        $this->refuseIf('customer', array_filter($counts));
    }

    protected function assertNoSupplierDependents(Counterparty $profile): void
    {
        $counts = [];

        foreach (self::SUPPLIER_DEPENDENTS as $table) {
            if (Schema::hasColumn($table, 'counterparty_id')) {
                $counts[$table] = DB::table($table)->where('counterparty_id', $profile->id)->count();
            }
        }

        $this->refuseIf('supplier', array_filter($counts));
    }

    /**
     * @param  array<string, int>  $counts
     */
    protected function refuseIf(string $role, array $counts): void
    {
        if ($counts === []) {
            return;
        }

        $details = collect($counts)->map(fn (int $count, string $what): string => __('partners.dependents.'.$what, ['count' => $count]))->join(', ');

        throw new PartnerException('role_in_use', ['role' => __('partners.roles.'.$role), 'details' => $details]);
    }
}
