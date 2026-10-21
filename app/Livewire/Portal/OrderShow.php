<?php

namespace App\Livewire\Portal;

use App\Enums\StockDocumentStatus;
use App\Models\Order;
use App\Support\PortalContext;
use App\Support\PortalScope;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Order in the portal (Spec 03 P-4): status, lines, posted delivery notes, payment status for
 * finance roles, prints (order confirmation, delivery notes, warranty cards). No internal notes.
 */
#[Layout('layouts.portal')]
class OrderShow extends Component
{
    #[Locked]
    public int $orderId;

    public function mount(Order $order, PortalScope $scope): void
    {
        abort_unless($scope->orders()->whereKey($order->id)->exists(), 404);
        $this->orderId = $order->id;
    }

    public function render(PortalScope $scope, PortalContext $context): View
    {
        $order = $scope->orders()->with(['lines', 'deliveries' => fn ($q) => $q->where('status', StockDocumentStatus::Posted)])->findOrFail($this->orderId);

        return view('livewire.portal.order-show', [
            'order' => $order,
            'showPayments' => $context->canSeeFinance(),
        ])->title($order->number);
    }
}
