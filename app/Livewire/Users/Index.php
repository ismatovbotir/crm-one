<?php

namespace App\Livewire\Users;

use App\Enums\UserRole;
use App\Enums\UserType;
use App\Models\Department;
use App\Models\User;
use App\Services\Auth\TwoFactor;
use App\Services\Telegram\TelegramLinks;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
class Index extends Component
{
    use WithPagination;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $role = '';

    #[Url(except: '')]
    public string $status = '';

    #[Url(except: '')]
    public string $type = '';

    #[Url(except: '')]
    public string $department = '';

    public function mount(): void
    {
        $this->authorize('viewAny', User::class);
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'role', 'status', 'type', 'department'], true)) {
            $this->resetPage();
        }
    }

    /**
     * @return LengthAwarePaginator<int, User>
     */
    #[Computed]
    public function users(): LengthAwarePaginator
    {
        $role = UserRole::tryFrom($this->role);
        $type = UserType::tryFrom($this->type);
        $departmentId = ctype_digit($this->department) ? (int) $this->department : null;
        $status = in_array($this->status, ['active', 'inactive'], true) ? $this->status : null;
        $search = trim($this->search);

        return User::query()
            ->with(['departments', 'telegramLink'])
            ->when($search !== '', function ($query) use ($search): void {
                $term = '%'.$search.'%';
                $query->where(fn ($q) => $q->where('name', 'like', $term)->orWhere('email', 'like', $term));
            })
            ->when($role !== null, fn ($query) => $query->where('role', $role))
            ->when($type !== null, fn ($query) => $query->where('type', $type))
            ->when($departmentId !== null, fn ($query) => $query->whereHas('departments', fn ($q) => $q->whereKey($departmentId)))
            ->when($status !== null, fn ($query) => $query->where('is_active', $status === 'active'))
            ->orderBy('name')
            ->paginate(config('crm.per_page'));
    }

    public function toggleActive(int $userId): void
    {
        $user = User::findOrFail($userId);

        $this->authorize('toggleActive', $user);

        $activate = ! $user->is_active;

        $user->forceFill([
            'is_active' => $activate,
            'deactivated_at' => $activate ? null : now(),
            'deactivated_by' => $activate ? null : auth()->id(),
        ])->save();

        session()->flash('status', $user->is_active
            ? __('users.activated', ['name' => $user->name])
            : __('users.deactivated', ['name' => $user->name]));
    }

    /**
     * Admin force-unlink of a user's Telegram (Spec 03 4.9): lost phone or leaver; audited.
     */
    public function unlinkTelegram(int $userId, TelegramLinks $links): void
    {
        $user = User::findOrFail($userId);
        $this->authorize('update', $user);
        $links->unlink($user, auth()->user());
        unset($this->users);
        session()->flash('status', __('users.telegram_unlinked', ['name' => $user->name]));
    }

    /**
     * Admin reset of another user's 2FA (lost phone; roadmap step 10). The user sets it up again
     * at the next sign-in when enforcement applies to their role; audited.
     */
    public function resetTwoFactor(int $userId, TwoFactor $twoFactor): void
    {
        $user = User::findOrFail($userId);
        $this->authorize('resetTwoFactor', $user);
        $twoFactor->disable($user);
        $user->logActivity('two_factor_reset', ['by' => auth()->id()]);
        unset($this->users);
        session()->flash('status', __('users.two_factor_reset_done', ['name' => $user->name]));
    }

    public function render(): View
    {
        return view('livewire.users.index', [
            'roles' => UserRole::options(),
            'types' => UserType::options(),
            'departments' => Department::query()->orderBy('sort_order')->get(),
        ])->title(__('users.title'));
    }
}
