<?php

namespace App\Services\Import;

use App\Actions\Customers\SaveContact;
use App\Enums\CustomerOrigin;
use App\Enums\SaleType;
use App\Enums\SyncStatus;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\User;
use App\Services\Customers\DuplicateCustomerFinder;
use App\Services\OneC\OneCSettings;
use App\Support\UzbekIdentifiers;
use Illuminate\Support\Facades\DB;

/**
 * Go-live customer import (roadmap step 7). Columns (header names, any order): name (required),
 * legal_name, tax_id, phone, email, city, address, currency, payment_terms_days, sale_type
 * (b2b|retail), owner_email, contact_first_name, contact_last_name, contact_position,
 * contact_phone, contact_email. Rows are checked first (preview); an existing tax ID or a repeated
 * tax ID in the file is a duplicate and never overwritten. Created customers are CRM customers
 * (not synced yet) and become available for 1C to pull like customers entered by hand.
 */
class CustomerImporter
{
    public const COLUMNS = ['name', 'legal_name', 'tax_id', 'phone', 'email', 'city', 'address', 'currency', 'payment_terms_days', 'sale_type', 'owner_email', 'contact_first_name', 'contact_last_name', 'contact_position', 'contact_phone', 'contact_email'];

    public function __construct(protected DuplicateCustomerFinder $duplicates) {}

    /**
     * @param  list<array{row_no: int, values: array<string, string>, formula: bool}>  $rows
     * @return list<array{row_no: int, status: 'ok'|'duplicate'|'error', messages: list<string>, data: array<string, mixed>}>
     */
    public function preview(array $rows): array
    {
        $seenTax = [];
        $results = [];
        $currencies = array_keys((array) config('crm.currencies'));

        foreach ($rows as $row) {
            $v = $row['values'];
            $messages = [];
            $status = 'ok';
            $taxId = Customer::normalizeTaxId($v['tax_id'] ?? null);

            if ($row['formula']) {
                $messages[] = __('import.messages.formula');
            }

            if (($v['name'] ?? '') === '' || mb_strlen($v['name']) > 255) {
                $messages[] = __('import.messages.name_required');
            }

            if (($v['tax_id'] ?? '') !== '' && UzbekIdentifiers::taxIdError($v['tax_id']) !== null) {
                $messages[] = __('leads.tax_id_errors.'.UzbekIdentifiers::taxIdError($v['tax_id']));
            }

            if (($v['email'] ?? '') !== '' && filter_var($v['email'], FILTER_VALIDATE_EMAIL) === false) {
                $messages[] = __('import.messages.email_invalid');
            }

            $currency = strtoupper($v['currency'] ?? '') ?: (string) config('crm.base_currency');

            if (! in_array($currency, $currencies, true)) {
                $messages[] = __('import.messages.currency_invalid', ['currency' => $currency]);
            }

            if (($v['payment_terms_days'] ?? '') !== '' && (! ctype_digit($v['payment_terms_days']) || (int) $v['payment_terms_days'] > 365)) {
                $messages[] = __('import.messages.terms_invalid');
            }

            if (($v['sale_type'] ?? '') !== '' && SaleType::tryFrom(strtolower($v['sale_type'])) === null) {
                $messages[] = __('import.messages.sale_type_invalid');
            }

            $owner = ($v['owner_email'] ?? '') !== '' ? User::query()->internal()->active()->whereRaw('lower(email) = ?', [mb_strtolower($v['owner_email'])])->first() : null;

            if (($v['owner_email'] ?? '') !== '' && ($owner === null || ! $owner->can('create', Lead::class))) {
                $messages[] = __('import.messages.owner_unknown', ['email' => $v['owner_email']]);
            }

            if ($messages !== []) {
                $status = 'error';
            } elseif ($taxId !== null && (isset($seenTax[$taxId]) || $this->duplicates->findByTaxId($taxId) !== null)) {
                $status = 'duplicate';
                $messages[] = __('import.messages.duplicate_tax_id', ['tax_id' => $taxId]);
            }

            if ($taxId !== null) {
                $seenTax[$taxId] = true;
            }

            $results[] = [
                'row_no' => $row['row_no'],
                'status' => $status,
                'messages' => $messages,
                'data' => [...$v, 'tax_id' => $taxId, 'currency' => $currency, 'owner_id' => $owner?->id],
            ];
        }

        return $results;
    }

    /**
     * Creates the customers of the valid rows in one transaction; returns the number created.
     *
     * @param  list<array{row_no: int, status: string, messages: list<string>, data: array<string, mixed>}>  $results
     */
    public function commit(array $results, User $user): int
    {
        $valid = array_values(array_filter($results, fn (array $row): bool => $row['status'] === 'ok'));
        $syncError = app(OneCSettings::class)->customersEnabled() ? null : 'integration_disabled';

        return DB::transaction(function () use ($valid, $user, $syncError): int {
            foreach ($valid as $row) {
                $d = $row['data'];
                $customer = Customer::query()->create([
                    'name' => $d['name'],
                    'legal_name' => ($d['legal_name'] ?? '') ?: null,
                    'tax_id' => $d['tax_id'],
                    'phone' => UzbekIdentifiers::normalizePhone($d['phone'] ?? null),
                    'email' => ($d['email'] ?? '') !== '' ? mb_strtolower($d['email']) : null,
                    'city' => ($d['city'] ?? '') ?: null,
                    'address' => ($d['address'] ?? '') ?: null,
                    'country' => (string) config('crm.default_country', 'UZ'),
                    'currency' => $d['currency'],
                    'payment_terms_days' => ($d['payment_terms_days'] ?? '') !== '' ? (int) $d['payment_terms_days'] : null,
                    'sale_type' => SaleType::tryFrom(strtolower((string) ($d['sale_type'] ?? ''))) ?? SaleType::default(),
                    'owner_id' => $d['owner_id'] ?? $user->id,
                    'origin' => CustomerOrigin::Crm,
                    'sync_status' => SyncStatus::NotSynced,
                    'sync_error' => $syncError,
                ]);

                if (($d['contact_first_name'] ?? '') !== '') {
                    app(SaveContact::class)->handle($customer, [
                        'first_name' => $d['contact_first_name'],
                        'last_name' => ($d['contact_last_name'] ?? '') ?: null,
                        'position' => ($d['contact_position'] ?? '') ?: null,
                        'email' => ($d['contact_email'] ?? '') !== '' ? mb_strtolower($d['contact_email']) : null,
                        'phone' => UzbekIdentifiers::normalizePhone($d['contact_phone'] ?? null),
                        'is_primary' => true,
                    ]);
                }

                $customer->logActivity('imported', ['row' => $row['row_no']]);
            }

            return count($valid);
        });
    }
}
