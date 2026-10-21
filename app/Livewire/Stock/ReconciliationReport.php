<?php

namespace App\Livewire\Stock;

use App\Models\Item;
use App\Models\ItemSerial;
use App\Services\Stock\Reconciliation;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Serials vs 1C quantities (Spec 06 21.11) and the "serials missing" queue (BR-SN.11): negative
 * discrepancies mean registered serials are missing (opening balance receipt needed).
 */
#[Layout('layouts.app')]
class ReconciliationReport extends Component
{
    /** Rows rendered at most; sorted by largest shortage first. */
    public const MAX_ROWS = 500;

    /** Default: only rows with a discrepancy (the work queue); all rows on demand. */
    #[Url(as: 'all')]
    public bool $showAll = false;

    public function mount(): void
    {
        $this->authorize('viewReconciliation', ItemSerial::class);
    }

    /**
     * @return list<array{item: Item, warehouse_id: int, warehouse: string, crm: int, onec: string, lag: int, discrepancy: string}>
     */
    #[Computed]
    public function rows(): array
    {
        return app(Reconciliation::class)->rows(onlyDiscrepancies: ! $this->showAll);
    }

    public function render(): View
    {
        $rows = $this->rows;

        return view('livewire.stock.reconciliation', [
            'visibleRows' => array_slice($rows, 0, self::MAX_ROWS),
            'total' => count($rows),
        ])->title(__('stock.reconciliation.title'));
    }
}
