<?php

namespace App\Livewire\Settings;

use App\Models\AccountingPeriod;
use App\Models\Setting;
use App\Support\BusinessSettings;
use App\Support\Money;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Settings > Accounting (Spec 06 7.16): approval and proof thresholds per currency, payment and
 * hold days, issue-time code validation modes, invoice print columns, auto-close, order and
 * agreement rule switches. Finance edits; managers and accountants view. Stored as `settings`
 * rows that override config defaults (App\Support\BusinessSettings).
 */
#[Layout('layouts.app')]
class Accounting extends Component
{
    public const BOOLEANS = [
        'accounting.count_unconfirmed_payments', 'accounting.auto_close_orders',
        'orders.agreement_required', 'orders.block_fulfilment_without_agreement', 'orders.block_confirm_on_shortage',
    ];

    public const INTEGERS = ['accounting.prepayment_due_days', 'accounting.overdue_grace_days', 'accounting.hold_overdue_days'];

    public const MONEY = ['accounting.payment_approval_threshold_minor', 'accounting.proof_required_above_minor', 'agreements.review_above_minor', 'stock_off.high_approval_from_minor'];

    /** @var array<string, bool> */
    public array $switches = [];

    /** @var array<string, string> */
    public array $numbers = [];

    /** @var array<string, array<string, string>> key => currency => major amount */
    public array $amounts = [];

    /** @var array<string, string> */
    public array $codeModes = [];

    /** @var array<string, bool> */
    public array $printColumns = [];

    public function mount(): void
    {
        $this->authorize('viewSettings', AccountingPeriod::class);

        foreach (self::BOOLEANS as $key) {
            $this->switches[str_replace('.', '__', $key)] = BusinessSettings::bool($key);
        }

        foreach (self::INTEGERS as $key) {
            $this->numbers[str_replace('.', '__', $key)] = (string) BusinessSettings::int($key);
        }

        foreach (self::MONEY as $key) {
            foreach ($this->currencies() as $currency) {
                $minor = BusinessSettings::array($key)[$currency] ?? null;
                $this->amounts[str_replace('.', '__', $key)][$currency] = $minor === null ? '' : Money::of((int) $minor, $currency)->toMajor();
            }
        }

        $this->codeModes = array_merge(['accountant_code' => 'warn', 'class_code' => 'warn', 'package_code' => 'warn'], BusinessSettings::array('accounting.invoice_code_validation'));
        $this->printColumns = array_map('boolval', array_merge(['accountant_code' => false, 'class_code' => true, 'package_code' => true], BusinessSettings::array('accounting.invoice_print_columns')));
    }

    /**
     * @return list<string>
     */
    protected function currencies(): array
    {
        return array_keys((array) config('crm.currencies'));
    }

    public function save(): void
    {
        $this->authorize('editSettings', AccountingPeriod::class);

        $rules = [
            'codeModes.*' => ['required', 'in:off,warn,block'],
            'printColumns.*' => ['boolean'],
            'switches.*' => ['boolean'],
        ];

        foreach (self::INTEGERS as $key) {
            $rules['numbers.'.str_replace('.', '__', $key)] = ['required', 'integer', 'min:0', 'max:365'];
        }

        $this->validate($rules);
        $rows = [];

        foreach (self::BOOLEANS as $key) {
            $rows[$key] = ! empty($this->switches[str_replace('.', '__', $key)]) ? '1' : '0';
        }

        foreach (self::INTEGERS as $key) {
            $rows[$key] = (string) (int) $this->numbers[str_replace('.', '__', $key)];
        }

        foreach (self::MONEY as $key) {
            $values = [];

            foreach ($this->currencies() as $currency) {
                $major = trim((string) ($this->amounts[str_replace('.', '__', $key)][$currency] ?? ''));

                if ($major === '') {
                    continue;
                }

                if (! Money::isValidMajor($major, $currency)) {
                    $this->addError('amounts.'.str_replace('.', '__', $key).'.'.$currency, __('accounting.errors.amount_invalid'));

                    return;
                }

                $values[$currency] = Money::fromMajor($major, $currency)->amount;
            }

            $rows[$key] = (string) json_encode($values);
        }

        $rows['accounting.invoice_code_validation'] = (string) json_encode(array_intersect_key($this->codeModes, array_flip(['accountant_code', 'class_code', 'package_code'])));
        $rows['accounting.invoice_print_columns'] = (string) json_encode(array_map('boolval', array_intersect_key($this->printColumns, array_flip(['accountant_code', 'class_code', 'package_code']))));

        Setting::putMany($rows, auth()->user());
        session()->flash('status', __('accounting.settings.saved'));
    }

    public function render(): View
    {
        return view('livewire.settings.accounting', [
            'currencies' => $this->currencies(),
            'canEdit' => auth()->user()->can('editSettings', AccountingPeriod::class),
        ])->title(__('accounting.settings.title'));
    }
}
