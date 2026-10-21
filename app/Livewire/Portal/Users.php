<?php

namespace App\Livewire\Portal;

use App\Actions\Customers\ManagePortalAccess;
use App\Enums\CustomerPortalRole;
use App\Models\PortalMembership;
use App\Support\Money;
use App\Support\PortalContext;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Company users (Spec 03 US-6, AC-14): the portal admin gives colleagues access, changes roles and
 * deactivates leavers, only in their own company and never the last portal admin.
 */
#[Layout('layouts.portal')]
class Users extends Component
{
    /** @var array{email: string, name: string, roles: list<string>, approval_limit: string} */
    public array $form = ['email' => '', 'name' => '', 'roles' => ['buyer'], 'approval_limit' => ''];

    public function mount(PortalContext $context): void
    {
        abort_unless($context->canManageUsers(), 403);
    }

    public function grant(ManagePortalAccess $access, PortalContext $context): void
    {
        abort_unless($context->canManageUsers(), 403);
        $this->validate([
            'form.email' => ['required', 'email', 'max:255'],
            'form.name' => ['required', 'string', 'max:255'],
            'form.roles' => ['required', 'array', 'min:1'],
            'form.roles.*' => [Rule::enum(CustomerPortalRole::class)],
            'form.approval_limit' => ['nullable', 'string', 'max:30'],
        ], [], ['form.email' => __('portal.users.email'), 'form.name' => __('portal.users.name')]);

        try {
            $limit = blank($this->form['approval_limit']) ? null : Money::fromMajor($this->form['approval_limit'], $context->customer()->currency)->amount;
            $access->grant($context->customer(), $this->form['email'], $this->form['name'], $this->form['roles'], null, $limit, $context->user());
        } catch (ValidationException $exception) {
            $this->setErrorBag($exception->validator->errors());

            return;
        } catch (InvalidArgumentException) {
            $this->addError('form.approval_limit', __('validation.numeric', ['attribute' => __('portal.users.approval_limit')]));

            return;
        }

        $this->reset('form');
        session()->flash('status', __('portal.users.granted'));
    }

    public function deactivate(ManagePortalAccess $access, PortalContext $context, int $membershipId): void
    {
        abort_unless($context->canManageUsers(), 403);
        $membership = PortalMembership::query()->where('customer_id', $context->customerId())->findOrFail($membershipId);

        try {
            $access->deactivate($membership, $context->user());
        } catch (ValidationException $exception) {
            session()->flash('error', $exception->validator->errors()->first());

            return;
        }

        session()->flash('status', __('portal.users.deactivated'));
    }

    public function render(PortalContext $context): View
    {
        /** @var Collection<int, PortalMembership> $members */
        $members = PortalMembership::query()->where('customer_id', $context->customerId())->with('user.telegramLink')->orderByDesc('is_active')->orderBy('id')->get();

        return view('livewire.portal.users', ['members' => $members, 'roles' => CustomerPortalRole::options()])->title(__('portal.users.title'));
    }
}
