<?php

namespace App\Livewire\Customers;

use App\Enums\AssetSource;
use App\Enums\ServiceTicketStatus;
use App\Models\Customer;
use App\Models\CustomerAsset;
use App\Models\Partner;
use App\Services\Equipment\AssetRegister;
use App\Services\Equipment\Exceptions\EquipmentException;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Equipment tab of the customer card (Spec 09 §9.2): the customer's units with status and open
 * repair, and staff registration of a unit (verified at once).
 */
class Equipment extends Component
{
    #[Locked]
    public int $customerId;

    /** @var array{serial: string, model_name: string, location: string} */
    public array $form = ['serial' => '', 'model_name' => '', 'location' => ''];

    public function mount(int $customerId): void
    {
        $this->customerId = $customerId;
        $this->authorize('view', $this->customer);
    }

    #[Computed]
    public function customer(): Customer
    {
        return Customer::query()->withTrashed()->findOrFail($this->customerId);
    }

    public function register(AssetRegister $register): void
    {
        $this->authorize('register', [CustomerAsset::class, $this->customer]);
        $this->validate(['form.serial' => ['required', 'string', 'max:100'], 'form.model_name' => ['required', 'string', 'max:255'], 'form.location' => ['nullable', 'string', 'max:255']], [], ['form.serial' => __('service.fields.serial'), 'form.model_name' => __('service.fields.model')]);

        try {
            $register->register(Partner::query()->findOrFail($this->customerId), $this->form, auth()->user(), AssetSource::StaffRegistered);
        } catch (EquipmentException $exception) {
            $this->addError('form.serial', $exception->getMessage());

            return;
        }

        $this->reset('form');
        session()->flash('status', __('service.flash.registered'));
    }

    public function render(): View
    {
        return view('livewire.customers.equipment', [
            'assets' => CustomerAsset::query()->where('partner_id', $this->customerId)->with(['item:id,name', 'tickets' => fn ($q) => $q->whereIn('status', ServiceTicketStatus::openValues())])->orderBy('status')->orderByDesc('id')->limit(500)->get(),
            'canRegister' => auth()->user()->can('register', [CustomerAsset::class, $this->customer]),
            'canOpenAsset' => auth()->user()->can('viewAny', CustomerAsset::class),
        ]);
    }
}
