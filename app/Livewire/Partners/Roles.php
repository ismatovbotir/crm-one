<?php

namespace App\Livewire\Partners;

use App\Actions\Partners\ChangePartnerRoles;
use App\Models\Partner;
use App\Policies\PartnerPolicy;
use App\Services\Partners\Exceptions\PartnerException;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * "Edit roles" on the customer and supplier cards (Spec 03 section 24.4): role badges and, for users
 * with the right, checkboxes to add or remove roles. Refusals (role in use, missing right) are shown
 * as a message; nothing is deleted.
 */
class Roles extends Component
{
    #[Locked]
    public int $partnerId;

    public bool $editing = false;

    /** @var list<string> */
    public array $roles = [];

    public function mount(int $partnerId): void
    {
        $this->partnerId = $partnerId;
        $this->roles = $this->partner->roles();
    }

    #[Computed]
    public function partner(): Partner
    {
        return Partner::withTrashed()->findOrFail($this->partnerId);
    }

    public function edit(): void
    {
        $this->authorize('editRoles', $this->partner);
        $this->roles = $this->partner->roles();
        $this->editing = true;
        $this->resetErrorBag();
    }

    public function save(ChangePartnerRoles $change): void
    {
        $this->authorize('editRoles', $this->partner);

        try {
            $partner = $change->handle($this->partner, $this->roles, auth()->user());
        } catch (PartnerException $exception) {
            $this->addError('roles', $exception->getMessage());

            return;
        }

        $this->editing = false;
        unset($this->partner);
        session()->flash('status', __('partners.roles_saved', ['name' => $partner->name]));
        $this->redirect(Index::cardUrl($partner, auth()->user()) ?? route('partners.index'), navigate: true);
    }

    public function render(): View
    {
        return view('livewire.partners.roles', ['allowed' => app(PartnerPolicy::class)->allowedRoles(auth()->user())]);
    }
}
