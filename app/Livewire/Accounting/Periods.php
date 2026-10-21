<?php

namespace App\Livewire\Accounting;

use App\Actions\Accounting\ManagePeriods;
use App\Models\AccountingPeriod;
use App\Models\FxRate;
use App\Services\Accounting\AccountingPeriods;
use App\Services\Accounting\Exceptions\AccountingActionException;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Accounting periods and exchange rates (Spec 06 7.14, BR-AC.13): the last 12 months with status
 * and lock checklist, lock (finance) and reopen (another finance user, reason); manual CBU rates.
 */
#[Layout('layouts.app')]
class Periods extends Component
{
    public string $lockPeriod = '';

    public bool $confirmOpenItems = false;

    public string $reopenId = '';

    public string $reopenReason = '';

    /** @var array{currency: string, date: string, rate: string} */
    public array $rate = ['currency' => 'USD', 'date' => '', 'rate' => ''];

    public function mount(): void
    {
        $this->authorize('viewAny', AccountingPeriod::class);
        $this->rate['date'] = AccountingPeriods::today()->toDateString();
    }

    /**
     * @return list<array{period: string, status: string, checklist: array<string, int>, model: AccountingPeriod|null}>
     */
    #[Computed]
    public function months(): array
    {
        $stored = AccountingPeriod::query()->with('locker')->get()->keyBy('period');
        $manage = app(ManagePeriods::class);
        $months = [];

        for ($i = 0; $i < 12; $i++) {
            $period = AccountingPeriods::today()->startOfMonth()->subMonths($i)->format('Y-m');
            $model = $stored->get($period);
            $months[] = ['period' => $period, 'status' => $model?->status->value ?? 'open', 'checklist' => $manage->checklist($period), 'model' => $model];
        }

        return $months;
    }

    /**
     * @return Collection<int, FxRate>
     */
    #[Computed]
    public function rates(): Collection
    {
        return FxRate::query()->orderByDesc('rate_date')->orderBy('currency')->limit(30)->get();
    }

    public function lock(ManagePeriods $manage, string $period): void
    {
        $this->authorize('manage', AccountingPeriod::class);
        $this->run(fn () => $manage->lock($period, auth()->user(), $this->lockPeriod === $period && $this->confirmOpenItems), 'accounting.flash.period_locked');
        $this->lockPeriod = $period;
    }

    public function reopen(ManagePeriods $manage): void
    {
        $this->authorize('manage', AccountingPeriod::class);
        $this->validate(['reopenReason' => ['required', 'string', 'max:500']], [], ['reopenReason' => __('accounting.fields.reason')]);
        $period = AccountingPeriod::query()->findOrFail((int) $this->reopenId);
        $this->run(fn () => $manage->reopen($period, auth()->user(), trim($this->reopenReason)), 'accounting.flash.period_reopened');
        $this->reset(['reopenId', 'reopenReason']);
    }

    public function saveRate(ManagePeriods $manage): void
    {
        $this->authorize('manage', AccountingPeriod::class);
        $currencies = array_diff(array_keys((array) config('crm.currencies')), ['UZS']);
        $this->validate([
            'rate.currency' => ['required', 'in:'.implode(',', $currencies)],
            'rate.date' => ['required', 'date'],
            'rate.rate' => ['required', 'numeric', 'gt:0', 'regex:/^\d{1,10}(\.\d{1,8})?$/'],
        ], [], ['rate.currency' => __('accounting.fields.currency'), 'rate.date' => __('accounting.fields.rate_date'), 'rate.rate' => __('accounting.fields.fx_rate')]);
        $this->run(fn () => $manage->saveRate($this->rate['currency'], $this->rate['date'], $this->rate['rate'], auth()->user()), 'accounting.flash.rate_saved');
        $this->rate['rate'] = '';
    }

    protected function run(callable $action, string $successKey): void
    {
        try {
            $action();
        } catch (AccountingActionException $exception) {
            session()->flash('error', $exception->getMessage());
            unset($this->months);

            return;
        }

        session()->flash('status', __($successKey));
        $this->confirmOpenItems = false;
        unset($this->months, $this->rates);
    }

    public function render(): View
    {
        return view('livewire.accounting.periods', [
            'currencies' => array_values(array_diff(array_keys((array) config('crm.currencies')), ['UZS'])),
        ])->title(__('accounting.periods.title'));
    }
}
