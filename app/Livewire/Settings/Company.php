<?php

namespace App\Livewire\Settings;

use App\Enums\Locale;
use App\Models\CompanyBankAccount;
use App\Models\CompanySignatory;
use App\Support\CompanyProfile;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Settings > Company (Spec 06 7.9): legal profile per document language (printed on offers,
 * orders and agreements), our bank accounts (one default per currency) and the people who sign
 * for the company (the CEO by default; others with a power of attorney and validity date).
 */
#[Layout('layouts.app')]
class Company extends Component
{
    /** @var array<string, string|array<string, string>> nested: localized fields as field => [locale => value] */
    public array $profile = [];

    /** @var array{bank_name: string, mfo: string, account_number: string, currency: string, is_default: bool} */
    public array $bank = ['bank_name' => '', 'mfo' => '', 'account_number' => '', 'currency' => 'UZS', 'is_default' => false];

    #[Locked]
    public ?int $bankId = null;

    /** @var array{full_name: string, position: string, basis: string, valid_until: string, is_default: bool} */
    public array $signatory = ['full_name' => '', 'position' => '', 'basis' => '', 'valid_until' => '', 'is_default' => false];

    #[Locked]
    public ?int $signatoryId = null;

    public string $editing = '';

    public function mount(): void
    {
        $this->authorize('manage', CompanyBankAccount::class);
        $this->profile = Arr::undot(CompanyProfile::formValues());
    }

    /**
     * @return Collection<int, CompanyBankAccount>
     */
    #[Computed]
    public function bankAccounts(): Collection
    {
        return CompanyBankAccount::query()->orderBy('currency')->orderByDesc('is_default')->orderBy('id')->get();
    }

    /**
     * @return Collection<int, CompanySignatory>
     */
    #[Computed]
    public function signatories(): Collection
    {
        return CompanySignatory::query()->orderByDesc('is_active')->orderByDesc('is_default')->orderBy('full_name')->get();
    }

    public function saveProfile(): void
    {
        $this->authorize('manage', CompanyBankAccount::class);

        $rules = [];

        foreach (CompanyProfile::LOCALIZED as $field) {
            foreach (Locale::cases() as $locale) {
                $rules['profile.'.$field.'.'.$locale->value] = ['nullable', 'string', 'max:500'];
            }
        }

        $rules += [
            'profile.tax_id' => ['nullable', 'string', 'regex:/^\d{9}$/'],
            'profile.vat_code' => ['nullable', 'string', 'max:20'],
            'profile.oked' => ['nullable', 'string', 'max:10'],
            'profile.phone' => ['nullable', 'string', 'max:40'],
            'profile.email' => ['nullable', 'email', 'max:255'],
            'profile.director' => ['nullable', 'string', 'max:255'],
            'profile.chief_accountant' => ['nullable', 'string', 'max:255'],
        ];

        $this->validate($rules, ['profile.tax_id.regex' => __('settings.company.tax_id_invalid')], ['profile.tax_id' => __('settings.company.fields.tax_id'), 'profile.email' => __('settings.company.fields.email')]);
        CompanyProfile::save(Arr::dot($this->profile), auth()->user());
        session()->flash('status', __('settings.company.saved'));
    }

    public function editBank(?int $id = null): void
    {
        $this->resetValidation();
        $account = $id !== null ? CompanyBankAccount::query()->findOrFail($id) : null;
        $this->bankId = $account?->id;
        $this->bank = [
            'bank_name' => (string) ($account?->bank_name ?? ''),
            'mfo' => (string) ($account?->mfo ?? ''),
            'account_number' => (string) ($account?->account_number ?? ''),
            'currency' => (string) ($account?->currency ?? config('crm.base_currency')),
            'is_default' => (bool) ($account?->is_default ?? false),
        ];
        $this->editing = 'bank';
    }

    public function saveBank(): void
    {
        $this->authorize('manage', CompanyBankAccount::class);
        $data = $this->validate([
            'bank.bank_name' => ['required', 'string', 'max:255'],
            'bank.mfo' => ['required', 'regex:/^\d{5}$/'],
            'bank.account_number' => ['required', 'regex:/^\d{20}$/'],
            'bank.currency' => ['required', 'in:'.implode(',', array_keys((array) config('crm.currencies')))],
            'bank.is_default' => ['boolean'],
        ], [
            'bank.mfo.regex' => __('settings.company.mfo_invalid'),
            'bank.account_number.regex' => __('settings.company.account_invalid'),
        ], [
            'bank.bank_name' => __('settings.company.fields.bank_name'),
            'bank.mfo' => __('settings.company.fields.mfo'),
            'bank.account_number' => __('settings.company.fields.account_number'),
            'bank.currency' => __('settings.company.fields.currency'),
        ])['bank'];

        DB::transaction(function () use ($data): void {
            if ($data['is_default']) {
                CompanyBankAccount::query()->where('currency', $data['currency'])->update(['is_default' => false]);
            }

            CompanyBankAccount::query()->updateOrCreate(['id' => $this->bankId], [...$data, 'is_active' => true]);
        });

        $this->editing = '';
        unset($this->bankAccounts);
        session()->flash('status', __('settings.company.bank_saved'));
    }

    public function toggleBank(int $id): void
    {
        $this->authorize('manage', CompanyBankAccount::class);
        $account = CompanyBankAccount::query()->findOrFail($id);
        $account->update(['is_active' => ! $account->is_active, 'is_default' => $account->is_active ? false : $account->is_default]);
        unset($this->bankAccounts);
    }

    public function editSignatory(?int $id = null): void
    {
        $this->resetValidation();
        $person = $id !== null ? CompanySignatory::query()->findOrFail($id) : null;
        $this->signatoryId = $person?->id;
        $this->signatory = [
            'full_name' => (string) ($person?->full_name ?? ''),
            'position' => (string) ($person?->position ?? ''),
            'basis' => (string) ($person?->basis ?? ''),
            'valid_until' => (string) $person?->valid_until?->toDateString(),
            'is_default' => (bool) ($person?->is_default ?? false),
        ];
        $this->editing = 'signatory';
    }

    public function saveSignatory(): void
    {
        $this->authorize('manage', CompanyBankAccount::class);
        $data = $this->validate([
            'signatory.full_name' => ['required', 'string', 'max:255'],
            'signatory.position' => ['required', 'string', 'max:255'],
            'signatory.basis' => ['nullable', 'string', 'max:255'],
            'signatory.valid_until' => ['nullable', 'date'],
            'signatory.is_default' => ['boolean'],
        ], [], [
            'signatory.full_name' => __('settings.company.fields.full_name'),
            'signatory.position' => __('settings.company.fields.position'),
            'signatory.basis' => __('settings.company.fields.basis'),
            'signatory.valid_until' => __('settings.company.fields.valid_until'),
        ])['signatory'];

        DB::transaction(function () use ($data): void {
            if ($data['is_default']) {
                CompanySignatory::query()->update(['is_default' => false]);
            }

            CompanySignatory::query()->updateOrCreate(['id' => $this->signatoryId], [...$data, 'valid_until' => $data['valid_until'] ?: null, 'basis' => $data['basis'] ?: null, 'is_active' => true]);
        });

        $this->editing = '';
        unset($this->signatories);
        session()->flash('status', __('settings.company.signatory_saved'));
    }

    public function toggleSignatory(int $id): void
    {
        $this->authorize('manage', CompanyBankAccount::class);
        $person = CompanySignatory::query()->findOrFail($id);
        $person->update(['is_active' => ! $person->is_active, 'is_default' => $person->is_active ? false : $person->is_default]);
        unset($this->signatories);
    }

    public function cancelEdit(): void
    {
        $this->editing = '';
        $this->resetValidation();
    }

    public function render(): View
    {
        return view('livewire.settings.company', [
            'locales' => Locale::cases(),
            'currencies' => array_keys((array) config('crm.currencies')),
        ])->title(__('settings.company.title'));
    }
}
