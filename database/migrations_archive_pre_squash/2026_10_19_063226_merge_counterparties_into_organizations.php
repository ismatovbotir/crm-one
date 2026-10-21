<?php

use App\Support\CustomerNameNormalizer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    /** Identity fields compared between a customer and a counterparty with the same tax ID. */
    private const COMPARED = ['name', 'legal_name', 'country', 'city', 'address', 'phone', 'email', 'website'];

    /**
     * Spec 03 section 22.3 step 2 (lossless data migration, query builder only, chunks of 200):
     * every counterparty gets its organization (merged by normalized tax ID into an existing live
     * organization, else a new supplier/carrier-only organization), supplier contacts are copied
     * into `contacts`, portal memberships get their organization. Nothing on an existing customer
     * is overwritten; conflicts go to `organization_merges`.
     */
    public function up(): void
    {
        DB::transaction(function (): void {
            $now = now();
            $fallbackOwner = DB::table('users')->where('is_active', true)->whereIn('role', ['manager', 'admin'])->orderByRaw("case role when 'manager' then 0 else 1 end")->orderBy('id')->value('id')
                ?? DB::table('users')->orderBy('id')->value('id');
            $currency = (string) config('crm.base_currency', 'UZS');

            DB::table('counterparties')->orderBy('id')->chunkById(200, function ($counterparties) use ($now, $fallbackOwner, $currency): void {
                foreach ($counterparties as $cp) {
                    $tax = $this->tax($cp->tax_id);
                    $match = $tax !== null
                        ? DB::table('customers')->where('tax_id', $tax)->whereNull('deleted_at')->orderByRaw("case origin when 'crm' then 0 else 1 end")->orderBy('id')->first()
                        : null;

                    if ($match !== null) {
                        $conflicts = [];

                        foreach (self::COMPARED as $field) {
                            $left = trim((string) ($match->{$field} ?? ''));
                            $right = trim((string) ($cp->{$field} ?? ''));

                            if ($left !== '' && $right !== '' && mb_strtolower($left) !== mb_strtolower($right)) {
                                $conflicts[$field] = ['organization' => $left, 'counterparty' => $right];
                            }
                        }

                        // A second counterparty with the same tax ID: same organization, flagged for a manual merge.
                        if (DB::table('counterparties')->where('organization_id', $match->id)->exists()) {
                            $conflicts['duplicate_profile'] = ['organization' => (string) DB::table('counterparties')->where('organization_id', $match->id)->orderBy('id')->value('id'), 'counterparty' => (string) $cp->id];
                        }

                        DB::table('customers')->where('id', $match->id)->update([
                            'is_supplier' => $match->is_supplier || ($cp->deleted_at === null && $cp->is_supplier),
                            'is_carrier' => $match->is_carrier || ($cp->deleted_at === null && $cp->is_carrier),
                        ]);
                        $organizationId = (int) $match->id;
                        $action = 'merged';
                    } else {
                        $name = trim((string) $cp->name);
                        $organizationId = (int) DB::table('customers')->insertGetId([
                            'uuid' => (string) Str::uuid(),
                            'origin' => 'crm',
                            'name' => $name,
                            'name_normalized' => CustomerNameNormalizer::normalize($name),
                            'legal_name' => $cp->legal_name,
                            'legal_type' => 'legal_entity',
                            'tax_id' => $tax,
                            'country' => strtoupper((string) ($cp->country ?: config('crm.default_country', 'UZ'))),
                            'city' => $cp->city,
                            'address' => $cp->address,
                            'phone' => $cp->phone,
                            'email' => filled($cp->email) ? mb_strtolower(trim((string) $cp->email)) : null,
                            'website' => $cp->website,
                            'currency' => $currency,
                            'owner_id' => $cp->owner_id ?? $cp->qualified_by ?? $fallbackOwner,
                            'preferred_locale' => $cp->preferred_locale,
                            'notes' => $cp->notes,
                            'sync_status' => 'not_synced',
                            'sync_attempts' => 0,
                            'has_duplicate_tax_id' => false,
                            'manual_hold' => false,
                            'portal_enabled' => false,
                            'sale_type' => 'b2b',
                            'is_customer' => false,
                            'is_supplier' => (bool) $cp->is_supplier,
                            'is_carrier' => (bool) $cp->is_carrier,
                            'created_at' => $cp->created_at ?? $now,
                            'updated_at' => $cp->updated_at ?? $now,
                            'deleted_at' => $cp->deleted_at,
                        ]);
                        $conflicts = [];
                        $action = 'created';
                    }

                    DB::table('counterparties')->where('id', $cp->id)->update(['organization_id' => $organizationId]);
                    DB::table('organization_merges')->insert([
                        'organization_id' => $organizationId,
                        'counterparty_id' => $cp->id,
                        'action' => $action,
                        'tax_id' => $tax,
                        'conflicts' => $conflicts !== [] ? json_encode($conflicts, JSON_UNESCAPED_UNICODE) : null,
                        'created_at' => $now,
                    ]);
                }
            });

            DB::table('counterparty_contacts')->orderBy('id')->chunkById(200, function ($contacts) use ($now): void {
                foreach ($contacts as $cc) {
                    $organizationId = DB::table('counterparties')->where('id', $cc->counterparty_id)->value('organization_id');
                    $parts = preg_split('/\s+/', trim((string) $cc->name), 2) ?: [''];
                    $hasPrimary = DB::table('contacts')->where('customer_id', $organizationId)->where('is_primary', true)->whereNull('deleted_at')->exists();

                    DB::table('contacts')->insert([
                        'uuid' => (string) Str::uuid(),
                        'customer_id' => $organizationId,
                        'first_name' => mb_substr($parts[0] !== '' ? $parts[0] : '—', 0, 100),
                        'last_name' => isset($parts[1]) ? mb_substr($parts[1], 0, 100) : null,
                        'position' => $cc->position,
                        'email' => filled($cc->email) ? mb_strtolower(trim((string) $cc->email)) : null,
                        'phone' => $cc->phone,
                        'telegram_username' => filled($cc->telegram) ? mb_substr(ltrim((string) $cc->telegram, '@'), 0, 64) : null,
                        'preferred_locale' => $cc->preferred_locale,
                        'is_primary' => (bool) $cc->is_primary && ! $hasPrimary,
                        'is_billing_contact' => false,
                        'is_signatory' => false,
                        'legacy_counterparty_contact_id' => $cc->id,
                        'created_at' => $cc->created_at ?? $now,
                        'updated_at' => $cc->updated_at ?? $now,
                    ]);
                }
            });

            DB::table('portal_memberships')->whereNotNull('customer_id')->update(['organization_id' => DB::raw('customer_id')]);

            foreach (DB::table('portal_memberships')->whereNull('organization_id')->whereNotNull('counterparty_id')->get(['id', 'counterparty_id']) as $membership) {
                DB::table('portal_memberships')->where('id', $membership->id)->update(['organization_id' => DB::table('counterparties')->where('id', $membership->counterparty_id)->value('organization_id')]);
            }
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            DB::table('portal_memberships')->update(['organization_id' => null]);
            DB::table('contacts')->whereNotNull('legacy_counterparty_contact_id')->delete();
            $created = DB::table('organization_merges')->where('action', 'created')->pluck('organization_id')->all();
            DB::table('counterparties')->update(['organization_id' => null]);
            DB::table('organization_merges')->delete();
            DB::table('customers')->whereIn('id', $created)->where('is_customer', false)->delete();
            DB::table('customers')->update(['is_supplier' => false, 'is_carrier' => false]);
        });
    }

    private function tax(?string $taxId): ?string
    {
        $normalized = strtoupper((string) preg_replace('/[^0-9A-Za-z]/', '', (string) $taxId));

        return $normalized === '' ? null : $normalized;
    }
};
