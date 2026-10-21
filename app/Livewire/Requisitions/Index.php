<?php

namespace App\Livewire\Requisitions;

use App\Authorization\PermissionMap;
use App\Enums\RequisitionStatus;
use App\Models\Requisition;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Sales > Customer requests (Spec 10 §6): requests customers sent to us, auto-assigned to the
 * customer's owner; queues new / quoting / offered / all; owner scope from the permission map.
 */
#[Layout('layouts.app')]
class Index extends Component
{
    use WithPagination;

    #[Url(except: 'new')]
    public string $queue = 'new';

    #[Url(except: '')]
    public string $search = '';

    public function mount(): void
    {
        $this->authorize('viewAny', Requisition::class);
    }

    public function updating(string $property): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        $term = trim($this->search);
        $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $term).'%';
        $withUs = [RequisitionStatus::Received->value, RequisitionStatus::Quoting->value, RequisitionStatus::Offered->value, RequisitionStatus::Ordered->value, RequisitionStatus::Declined->value];

        $query = Requisition::query()->with(['organization:id,name', 'owner:id,name', 'purchaseManager:id,name'])->withCount('lines')->whereIn('status', $withUs);
        PermissionMap::applyScope($query, auth()->user(), 'requisitions.view', 'owner_id');

        $requisitions = $query
            ->when($this->queue === 'new', fn ($q) => $q->where('status', RequisitionStatus::Received->value))
            ->when($this->queue === 'quoting', fn ($q) => $q->where('status', RequisitionStatus::Quoting->value))
            ->when($this->queue === 'offered', fn ($q) => $q->where('status', RequisitionStatus::Offered->value))
            ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w->where('number', 'like', $like)->orWhere('title', 'like', $like)->orWhereHas('organization', fn ($o) => $o->where('name', 'like', $like))))
            ->orderBy('sent_at')->paginate(config('crm.per_page'));

        return view('livewire.requisitions.index', ['requisitions' => $requisitions, 'queues' => ['new', 'quoting', 'offered', 'all']])->title(__('requisitions.staff.title'));
    }
}
