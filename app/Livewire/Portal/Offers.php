<?php

namespace App\Livewire\Portal;

use App\Support\PortalScope;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The company's offers in the portal (Spec 03 P-4): sent and decided offers only.
 */
#[Layout('layouts.portal')]
class Offers extends Component
{
    use WithPagination;

    public function render(PortalScope $scope): View
    {
        return view('livewire.portal.offers', [
            'offers' => $scope->offers()->latest('id')->paginate(config('crm.per_page')),
        ])->title(__('portal.offers.title'));
    }
}
