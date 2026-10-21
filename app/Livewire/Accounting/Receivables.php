<?php

namespace App\Livewire\Accounting;

use App\Models\AccountingPeriod;
use App\Services\Accounting\Receivables as ReceivablesReport;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Receivables aging per customer and currency (Spec 06 10.2, BR-AC.16, AC-31).
 */
#[Layout('layouts.app')]
class Receivables extends Component
{
    public function mount(): void
    {
        $this->authorize('viewReceivables', AccountingPeriod::class);
    }

    /**
     * @return list<array{customer_id: int, customer: string, currency: string, buckets: array<string, int>, total: int, advance: int}>
     */
    #[Computed]
    public function rows(): array
    {
        return app(ReceivablesReport::class)->aging(auth()->user());
    }

    public function render(): View
    {
        $report = app(ReceivablesReport::class);

        return view('livewire.accounting.receivables', [
            'buckets' => ReceivablesReport::BUCKETS,
            'totals' => $report->totals($this->rows),
        ])->title(__('accounting.receivables.title'));
    }
}
