<?php

namespace App\Livewire\Portal;

use App\Support\PortalScope;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The company's orders in the portal (Spec 03 P-4): number, status, total, promised delivery.
 */
#[Layout('layouts.portal')]
class Orders extends Component
{
    use WithPagination;

    public function render(PortalScope $scope): View
    {
        return view('livewire.portal.orders', [
            'orders' => $scope->orders()->latest('id')->paginate(config('crm.per_page')),
        ])->title(__('portal.orders.title'));
    }
}
