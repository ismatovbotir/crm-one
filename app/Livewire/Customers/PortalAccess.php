<?php

namespace App\Livewire\Customers;

use App\Actions\Customers\ManagePortalAccess;
use App\Enums\CustomerPortalRole;
use App\Models\Customer;
use App\Models\PortalMembership;
use App\Support\Money;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Customer card > Portal access (Spec 03 section 9): portal on/off, approval threshold, members
 * (roles, status, last login, Telegram linked y/n), give access to a contact or e-mail, deactivate.
 */
class PortalAccess extends Component
{
    #[Locked]
    public int $customerId;

    public bool $enabled = false;

    public string $threshold = '';

    /** @var array{email: string, name: string, contact_id: string, roles: list<string>, approval_limit: string} */
    public array $form = ['email' => '', 'name' => '', 'contact_id' => '', 'roles' => ['portal_admin'], 'approval_limit' => ''];

    public function mount(int $customerId): void
    {
        $this->customerId = $customerId;
        $this->authorize('managePortal', $this->customer);
        $this->enabled = (bool) $this->customer->portal_enabled;
        $this->threshold = $this->customer->portal_approval_threshold_minor !== null ? Money::of($this->customer->portal_approval_threshold_minor, $this->customer->currency)->toMajor() : '';
    }

    #[Computed]
    public function customer(): Customer
    {
        return Customer::query()->with('contacts')->findOrFail($this->customerId);
    }

    public function configure(ManagePortalAccess $access): void
    {
        $this->authorize('managePortal', $this->customer);

        try {
            $minor = blank($this->threshold) ? null : Money::fromMajor($this->threshold, $this->customer->currency)->amount;
        } catch (InvalidArgumentException) {
            $this->addError('threshold', __('validation.numeric', ['attribute' => __('portal.access.threshold')]));

            return;
        }

        $access->configure($this->customer, $this->enabled, $minor);
        unset($this->customer);
        session()->flash('status', __('portal.access.saved'));
    }

    public function pickContact(): void
    {
        $contact = $this->customer->contacts->firstWhere('id', (int) $this->form['contact_id']);

        if ($contact !== null) {
            $this->form['email'] = (string) $contact->email;
            $this->form['name'] = $contact->fullName();
        }
    }

    public function grant(ManagePortalAccess $access): void
    {
        $this->authorize('managePortal', $this->customer);
        $this->validate([
            'form.email' => ['required', 'email', 'max:255'],
            'form.name' => ['required', 'string', 'max:255'],
            'form.roles' => ['required', 'array', 'min:1'],
            'form.roles.*' => [Rule::enum(CustomerPortalRole::class)],
            'form.approval_limit' => ['nullable', 'string', 'max:30'],
        ], [], ['form.email' => __('portal.users.email'), 'form.name' => __('portal.users.name')]);

        try {
            $limit = blank($this->form['approval_limit']) ? null : Money::fromMajor($this->form['approval_limit'], $this->customer->currency)->amount;
            $access->grant($this->customer, $this->form['email'], $this->form['name'], $this->form['roles'], filled($this->form['contact_id']) ? (int) $this->form['contact_id'] : null, $limit, auth()->user());
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

    /** Prefill the grant form from a pending invitation (Spec 04 section 18.5); nothing is sent yet. */
    public function prepareInvite(int $contactId): void
    {
        $this->authorize('managePortal', $this->customer);
        $contact = $this->customer->contacts()->whereNotNull('portal_invite_requested_at')->findOrFail($contactId);
        $this->form['contact_id'] = (string) $contact->id;
        $this->form['roles'] = ['requester'];
        $this->pickContact();
    }

    public function dismissInvite(int $contactId): void
    {
        $this->authorize('managePortal', $this->customer);
        $contact = $this->customer->contacts()->whereNotNull('portal_invite_requested_at')->findOrFail($contactId);
        $contact->forceFill(['portal_invite_requested_at' => null, 'portal_invite_requested_by' => null])->saveQuietly();
        $this->customer->logActivity('portal_invite_dismissed', ['contact' => $contact->fullName()]);
        session()->flash('status', __('portal.access.invite_dismissed'));
    }

    public function deactivate(ManagePortalAccess $access, int $membershipId): void
    {
        $this->authorize('managePortal', $this->customer);
        $access->deactivate(PortalMembership::query()->where('customer_id', $this->customerId)->findOrFail($membershipId), auth()->user());
        session()->flash('status', __('portal.users.deactivated'));
    }

    public function render(): View
    {
        return view('livewire.customers.portal-access', [
            'members' => PortalMembership::query()->where('customer_id', $this->customerId)->with('user.telegramLink')->orderByDesc('is_active')->orderBy('id')->get(),
            'roles' => CustomerPortalRole::options(),
            'pendingInvites' => $this->customer->contacts()->whereNotNull('portal_invite_requested_at')->orderBy('portal_invite_requested_at')->get(),
        ]);
    }
}
