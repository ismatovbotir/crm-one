<?php

namespace App\Livewire\Service;

use App\Models\CustomerAsset;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Service > Equipment lookup (Spec 09 §9.2): serial search across customers for the service team
 * (`service.view`), with unverified and disputed registrations surfaced.
 */
#[Layout('layouts.app')]
class Assets extends Component
{
    use WithPagination;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $filter = '';

    public function mount(): void
    {
        $this->authorize('viewAny', CustomerAsset::class);
    }

    public function updating(string $property): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        $term = mb_strtoupper(preg_replace('/\s+/', '', trim($this->search)) ?? '');

        $assets = CustomerAsset::query()->visibleTo(auth()->user())->with(['organization:id,name', 'item:id,name'])
            ->when($term !== '', fn ($q) => $q->where('serial_normalized', 'like', '%'.str_replace(['%', '_'], ['\\%', '\\_'], $term).'%'))
            ->when($this->filter === 'unverified', fn ($q) => $q->whereNull('verified_at'))
            ->when($this->filter === 'disputed', fn ($q) => $q->whereNotNull('disputed_at'))
            ->orderByDesc('id')
            ->paginate(config('crm.per_page'));

        return view('livewire.service.assets', ['assets' => $assets])->title(__('service.staff.lookup_title'));
    }
}
