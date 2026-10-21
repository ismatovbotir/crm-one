<?php

namespace App\Livewire\Portal;

use App\Enums\OfferStatus;
use App\Enums\OrderStatus;
use App\Support\PortalContext;
use App\Support\PortalScope;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Portal home (Spec 03 section 9): open offers waiting for the customer, active orders and, for
 * finance roles, issued invoices; each list links to its record.
 */
#[Layout('layouts.portal')]
class Dashboard extends Component
{
    public function render(PortalScope $scope, PortalContext $context): View
    {
        return view('livewire.portal.dashboard', [
            'openOffers' => $scope->offers()->whereIn('status', [OfferStatus::Sent, OfferStatus::Viewed, OfferStatus::PartiallySelected])->latest('id')->limit(10)->get(),
            'orders' => $scope->orders()->whereNotIn('status', [OrderStatus::Closed, OrderStatus::Cancelled])->latest('id')->limit(10)->get(),
            'invoices' => $context->canSeeFinance() ? $scope->invoices()->latest('id')->limit(10)->get() : collect(),
        ])->title(__('portal.dashboard.title'));
    }
}
