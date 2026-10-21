<?php

namespace App\Services\Agreements;

use App\Enums\Locale;
use App\Models\Agreement;
use App\Models\CompanyBankAccount;
use App\Models\CompanySignatory;
use App\Models\CustomerBankAccount;
use App\Models\Order;
use App\Models\Partner;
use App\Support\CompanyProfile;
use Carbon\CarbonInterface;

/**
 * The placeholder whitelist and the frozen variables snapshot of an agreement (Spec 06 BR-AG.1).
 * Values are raw (strings, or typed arrays such as ['t' => 'money', 'a' => minor, 'c' => 'UZS'])
 * and formatted per document language by TemplateRenderer, so one snapshot prints in uz/ru/en.
 */
class AgreementVariables
{
    /** @var list<string> */
    public const KEYS = [
        'company.legal_name', 'company.tax_id', 'company.vat_code', 'company.oked', 'company.address', 'company.city',
        'company.phone', 'company.email', 'company.director', 'company.chief_accountant',
        'company_bank.name', 'company_bank.mfo', 'company_bank.account',
        'signatory.name', 'signatory.position', 'signatory.basis',
        'customer.legal_name', 'customer.name', 'customer.tax_id', 'customer.vat_code', 'customer.oked', 'customer.address',
        'customer.phone', 'customer.email', 'customer.signatory_name', 'customer.signatory_position', 'customer.signatory_basis',
        'customer_bank.name', 'customer_bank.mfo', 'customer_bank.account',
        'order.number', 'order.date', 'order.currency', 'order.net', 'order.vat', 'order.total', 'order.total_in_words',
        'order.lines_table', 'order.delivery_address', 'order.offer_number',
        'terms.payment', 'terms.payment_days', 'terms.prepayment_percent', 'terms.delivery', 'terms.warranty',
        'agreement.number', 'agreement.date', 'agreement.starts_on', 'agreement.ends_on',
        'parent.number', 'parent.date',
    ];

    /**
     * Required for a valid agreement (BR-AG.2); company bank/signatory checked separately.
     *
     * @var list<string>
     */
    public const REQUIRED = [
        'company.legal_name', 'company.tax_id', 'company.address',
        'customer.legal_name', 'customer.tax_id', 'customer.address',
        'customer.signatory_name', 'customer_bank.account', 'company_bank.account', 'signatory.name',
    ];

    /**
     * @return array<string, mixed>
     */
    public function resolve(Agreement $agreement, ?Order $order, Partner $customer): array
    {
        $currency = $order !== null ? $order->currency : ($agreement->currency ?? (string) config('crm.base_currency'));
        $signatory = $agreement->our_signatory_id !== null ? CompanySignatory::query()->find($agreement->our_signatory_id) : CompanySignatory::default();
        $companyBank = CompanyBankAccount::defaultFor($currency);
        $customerBank = $customer->bankAccounts()->where('currency', $currency)->first() ?? $customer->bankAccounts()->first();

        return [
            ...$this->company($signatory, $companyBank),
            ...$this->customer($customer, $customerBank),
            ...($order !== null ? $this->order($order) : []),
            'agreement.number' => $agreement->number,
            'agreement.date' => $this->date(now((string) config('orders.timezone'))),
            'agreement.starts_on' => $this->date($agreement->starts_on),
            'agreement.ends_on' => $this->date($agreement->ends_on),
            'parent.number' => $agreement->parent?->number,
            'parent.date' => $this->date($agreement->parent?->signed_on ?? $agreement->parent?->created_at),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function company(?CompanySignatory $signatory, ?CompanyBankAccount $bank): array
    {
        $localized = fn (string $field): array => ['t' => 'i18n', 'v' => collect(Locale::cases())->mapWithKeys(fn (Locale $locale): array => [$locale->value => CompanyProfile::get($field, $locale)])->all()];

        return [
            'company.legal_name' => $localized('legal_name'),
            'company.address' => $localized('address'),
            'company.city' => $localized('city'),
            'company.tax_id' => CompanyProfile::get('tax_id') ?: null,
            'company.vat_code' => CompanyProfile::get('vat_code') ?: null,
            'company.oked' => CompanyProfile::get('oked') ?: null,
            'company.phone' => CompanyProfile::get('phone') ?: null,
            'company.email' => CompanyProfile::get('email') ?: null,
            'company.director' => CompanyProfile::get('director') ?: null,
            'company.chief_accountant' => CompanyProfile::get('chief_accountant') ?: null,
            'company_bank.name' => $bank?->bank_name,
            'company_bank.mfo' => $bank?->mfo,
            'company_bank.account' => $bank?->account_number,
            'signatory.name' => $signatory?->full_name,
            'signatory.position' => $signatory?->position,
            'signatory.basis' => $signatory?->basis,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function customer(Partner $customer, ?CustomerBankAccount $bank): array
    {
        return [
            'customer.legal_name' => $customer->legal_name ?: $customer->name,
            'customer.name' => $customer->name,
            'customer.tax_id' => $customer->tax_id,
            'customer.vat_code' => $customer->vat_registration_code,
            'customer.oked' => $customer->oked,
            'customer.address' => collect([$customer->postal_code, $customer->city, $customer->address])->filter()->implode(', ') ?: null,
            'customer.phone' => $customer->phone,
            'customer.email' => $customer->email,
            'customer.signatory_name' => $customer->signatory_name,
            'customer.signatory_position' => $customer->signatory_position,
            'customer.signatory_basis' => $customer->signatory_basis,
            'customer_bank.name' => $bank?->bank_name,
            'customer_bank.mfo' => $bank?->mfo,
            'customer_bank.account' => $bank?->account_number,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function order(Order $order): array
    {
        $money = fn (int $minor): array => ['t' => 'money', 'a' => $minor, 'c' => $order->currency];
        $rows = $order->lines()->get()->map(fn ($line): array => [
            'name' => $line->name,
            'option' => $line->group_title !== null ? $line->option_label : null,
            'unit' => $line->unit_name,
            'quantity' => (string) $line->quantity,
            'price' => $line->unit_price_minor,
            'net' => $line->line_net_minor,
            'vat_bp' => $line->tax_rate_bp,
            'vat_exempt' => $line->is_vat_exempt,
            'vat' => $line->line_vat_minor,
            'gross' => $line->line_gross_minor,
        ])->all();

        return [
            'order.number' => $order->number,
            'order.date' => $this->date($order->created_at),
            'order.currency' => $order->currency,
            'order.net' => $money($order->net_minor),
            'order.vat' => $money($order->vat_minor),
            'order.total' => $money($order->gross_minor),
            'order.total_in_words' => ['t' => 'words', 'a' => $order->gross_minor, 'c' => $order->currency],
            'order.lines_table' => ['currency' => $order->currency, 'rows' => $rows, 'net' => $order->net_minor, 'vat' => $order->vat_minor, 'gross' => $order->gross_minor],
            'order.delivery_address' => $order->delivery_address,
            'order.offer_number' => $order->offer?->displayNumber(),
            'terms.payment' => $order->payment_terms_text,
            'terms.payment_days' => $order->payment_terms_days,
            'terms.prepayment_percent' => $order->prepayment_bp !== null ? ['t' => 'percent', 'v' => bcdiv((string) $order->prepayment_bp, '100', 2)] : null,
            'terms.delivery' => $order->delivery_terms_text,
            'terms.warranty' => $order->warranty_text,
        ];
    }

    /**
     * @return array{t: string, v: string}|null
     */
    protected function date(?CarbonInterface $date): ?array
    {
        return $date === null ? null : ['t' => 'longdate', 'v' => $date->toDateString()];
    }

    /**
     * Required keys without a value.
     *
     * @param  array<string, mixed>  $variables
     * @return list<string>
     */
    public static function missing(array $variables): array
    {
        return array_values(array_filter(self::REQUIRED, function (string $key) use ($variables): bool {
            $value = $variables[$key] ?? null;

            if (is_array($value) && ($value['t'] ?? null) === 'i18n') {
                return collect($value['v'])->filter()->isEmpty();
            }

            return $value === null || $value === '';
        }));
    }
}
