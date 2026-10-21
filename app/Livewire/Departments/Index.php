<?php

namespace App\Livewire\Departments;

use App\Authorization\PermissionMap;
use App\Enums\DataScope;
use App\Models\Department;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts.app')]
class Index extends Component
{
    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $status = '';

    public function mount(): void
    {
        $this->authorize('viewAny', Department::class);
    }

    /**
     * Departments the user may see: all for admin/manager, own ones for heads and specialists.
     *
     * @return Collection<int, Department>
     */
    #[Computed]
    public function departments(): Collection
    {
        $user = auth()->user();
        $search = mb_strtolower(trim($this->search));

        $departments = Department::query()
            ->with(['members' => fn ($query) => $query->orderBy('name')])
            ->withCount('members')
            ->when(PermissionMap::scopeFor($user, 'departments.view') !== DataScope::All, fn ($query) => $query->whereIn('id', $user->activeDepartments()->pluck('id')))
            ->when($this->status === 'active', fn ($query) => $query->where('is_active', true))
            ->when($this->status === 'inactive', fn ($query) => $query->where('is_active', false))
            ->orderBy('sort_order')
            ->orderBy('code')
            ->get();

        if ($search === '') {
            return $departments;
        }

        return $departments->filter(
            fn (Department $department): bool => str_contains(mb_strtolower($department->displayName().' '.$department->code), $search),
        )->values();
    }

    public function render(): View
    {
        return view('livewire.departments.index')->title(__('departments.title'));
    }
}
