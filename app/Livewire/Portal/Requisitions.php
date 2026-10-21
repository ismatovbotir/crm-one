<?php

namespace App\Livewire\Portal;

use App\Actions\Requisitions\ManageRequisition;
use App\Enums\RequisitionStatus;
use App\Services\Requisitions\Exceptions\RequisitionException;
use App\Support\PortalContext;
use App\Support\PortalScope;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Portal "Requests" (Spec 10 §5): requesters see their own, purchase managers and portal admins
 * all of the company's; new request; merge several submitted/approved requests (purchase manager).
 */
#[Layout('layouts.portal')]
class Requisitions extends Component
{
    use WithPagination;

    #[Url(except: '')]
    public string $status = '';

    /** @var list<int> */
    public array $selected = [];

    public function create(PortalContext $context, ManageRequisition $manage): void
    {
        abort_unless($context->canRequest(), 403);
        $requisition = $manage->create($context->customer(), $context->user());
        $this->redirectRoute('portal.requisitions.show', $requisition, navigate: true);
    }

    public function merge(PortalScope $scope, PortalContext $context, ManageRequisition $manage): void
    {
        abort_unless($context->canPurchase(), 403);

        try {
            $merged = $manage->merge($scope->requisitions()->whereIn('id', array_map('intval', $this->selected))->get(), $context->user());
        } catch (RequisitionException $exception) {
            $this->addError('selected', $exception->getMessage());

            return;
        }

        session()->flash('status', __('requisitions.flash.merged', ['number' => $merged->number]));
        $this->redirectRoute('portal.requisitions.show', $merged, navigate: true);
    }

    public function render(PortalScope $scope, PortalContext $context): View
    {
        $requisitions = $scope->requisitions()->with(['requester:id,name'])->withCount('lines')
            ->when(! $context->canPurchase(), fn ($q) => $q->where('requester_id', $context->user()->id))
            ->when(RequisitionStatus::tryFrom($this->status) !== null, fn ($q) => $q->where('status', $this->status))
            ->latest('id')->paginate(config('crm.per_page'));

        return view('livewire.portal.requisitions', [
            'requisitions' => $requisitions,
            'statuses' => RequisitionStatus::options(),
            'canRequest' => $context->canRequest(),
            'canPurchase' => $context->canPurchase(),
        ])->title(__('requisitions.portal.title'));
    }
}
