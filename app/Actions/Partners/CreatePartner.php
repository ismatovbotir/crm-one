<?php

namespace App\Actions\Partners;

use App\Actions\Customers\SaveContact;
use App\Enums\CounterpartyStatus;
use App\Enums\CustomerOrigin;
use App\Enums\LegalType;
use App\Enums\SaleType;
use App\Enums\SyncStatus;
use App\Models\Counterparty;
use App\Models\Customer;
use App\Models\Partner;
use App\Models\User;
use App\Services\Customers\DuplicateCustomerFinder;
use App\Services\OneC\OneCSettings;
use App\Services\Partners\Exceptions\PartnerException;
use Illuminate\Support\Facades\DB;

/**
 * "New partner" (Spec 03 section 24.3): one organization with one or more roles. Identity is saved
 * once on the partner; the customer role adds the customer fields (sale type, payment terms,
 * currency, owner) and joins the 1C customer exchange as before; the supplier/carrier role creates
 * the supplier profile (status prospect). The tax ID is unique per partner: an existing one is
 * refused with its id so the form can offer "add the role" instead.
 */
class CreatePartner
{
    public const ROLES = ['customer', 'supplier', 'carrier'];

    public const IDENTITY = ['name', 'legal_name', 'legal_type', 'tax_id', 'country', 'city', 'address', 'postal_code', 'phone', 'email', 'website', 'industry', 'preferred_locale', 'notes'];

    public function __construct(protected SaveContact $saveContact, protected DuplicateCustomerFinder $duplicates) {}

    /**
     * @param  array<string, mixed>  $data  identity fields, currency, owner_id, sale_type, payment_terms_days, carrier fields, contact_* fields
     * @param  list<string>  $roles
     */
    public function handle(array $data, array $roles, User $by): Partner
    {
        $roles = array_values(array_intersect(self::ROLES, $roles));

        if ($roles === []) {
            throw new PartnerException('role_required');
        }

        $taxId = Customer::normalizeTaxId($data['tax_id'] ?? null);
        $existing = $this->duplicates->findByTaxId($taxId);

        if ($existing !== null) {
            throw new PartnerException($existing->trashed() ? 'tax_id_archived' : 'tax_id_taken', ['name' => $existing->name], $existing->id);
        }

        $isCustomer = in_array('customer', $roles, true);
        $isSupplier = in_array('supplier', $roles, true);
        $isCarrier = in_array('carrier', $roles, true);

        return DB::transaction(function () use ($data, $by, $taxId, $isCustomer, $isSupplier, $isCarrier): Partner {
            $identity = [];

            foreach (self::IDENTITY as $field) {
                $value = $data[$field] ?? null;
                $identity[$field] = is_string($value) && trim($value) === '' ? null : (is_string($value) ? trim($value) : $value);
            }

            $attributes = [
                ...$identity,
                'tax_id' => $taxId,
                'legal_type' => LegalType::tryFrom((string) ($identity['legal_type'] ?? '')) ?? LegalType::LegalEntity,
                'country' => strtoupper((string) ($identity['country'] ?: config('crm.default_country', 'UZ'))),
                'origin' => CustomerOrigin::Crm,
                'currency' => strtoupper((string) ($data['currency'] ?? config('crm.base_currency', 'UZS'))),
                'owner_id' => (int) ($data['owner_id'] ?? $by->id),
                'sync_status' => SyncStatus::NotSynced,
                'sync_error' => app(OneCSettings::class)->customersEnabled() ? null : 'integration_disabled',
                // Spec 03 section 24.7: managed industry and the UZ region/city (text columns follow).
                'industry_id' => $data['industry_id'] ?? null,
                'region_id' => $data['region_id'] ?? null,
                'city_id' => $data['city_id'] ?? null,
            ];

            if ($attributes['industry_id'] !== null) {
                unset($attributes['industry']);
            }

            if ($isCustomer) {
                $partner = Customer::query()->create([
                    ...$attributes,
                    'sale_type' => SaleType::tryFrom((string) ($data['sale_type'] ?? '')) ?? SaleType::B2b,
                    'payment_terms_days' => isset($data['payment_terms_days']) && $data['payment_terms_days'] !== '' ? (int) $data['payment_terms_days'] : 0,
                    // Spec 02 rev. 4: off unless chosen; never without a tax ID.
                    'sync_with_1c' => (bool) ($data['sync_with_1c'] ?? false) && $taxId !== null,
                ]);
            } else {
                $partner = new Partner($attributes);
                $partner->forceFill(['is_customer' => false])->save();
            }

            if ($isSupplier || $isCarrier) {
                $profile = new Counterparty([
                    ...$partner->only(Customer::IDENTITY_FIELDS),
                    'is_supplier' => $isSupplier,
                    'is_carrier' => $isCarrier,
                    'status' => CounterpartyStatus::Prospect,
                    'owner_id' => $partner->owner_id,
                    'department_id' => $by->activeDepartments()->first()?->id,
                    'fleet_size' => $isCarrier && filled($data['fleet_size'] ?? null) ? (int) $data['fleet_size'] : null,
                    'has_tir' => $isCarrier && (bool) ($data['has_tir'] ?? false),
                    'has_adr' => $isCarrier && (bool) ($data['has_adr'] ?? false),
                    'supplier_category_id' => $data['supplier_category_id'] ?? null,
                ]);
                $profile->partner_id = $partner->id;
                $profile->save();
            }

            if (filled($data['contact_first_name'] ?? null)) {
                $this->saveContact->handle($partner, [
                    'first_name' => trim((string) $data['contact_first_name']),
                    'last_name' => filled($data['contact_last_name'] ?? null) ? trim((string) $data['contact_last_name']) : null,
                    'position' => filled($data['contact_position'] ?? null) ? trim((string) $data['contact_position']) : null,
                    'email' => filled($data['contact_email'] ?? null) ? trim((string) $data['contact_email']) : null,
                    'phone' => filled($data['contact_phone'] ?? null) ? trim((string) $data['contact_phone']) : null,
                    'is_primary' => true,
                ]);
            }

            $fresh = Partner::query()->findOrFail($partner->id);
            $fresh->logActivity('partner_created', ['roles' => $fresh->roles()]);

            return $fresh;
        });
    }
}
