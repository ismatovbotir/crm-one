<?php

namespace App\Livewire\Service;

use App\Actions\Service\ManageServiceTicket;
use App\Enums\AssetStatus;
use App\Enums\ServiceUrgency;
use App\Models\CustomerAsset;
use App\Services\Equipment\AssetRegister;
use App\Services\Equipment\Exceptions\EquipmentException;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Staff page of one customer unit (Spec 09 §9.2): details, sold-serial link, history, tickets;
 * the service team verifies registrations, writes off / reactivates, toggles storage and opens a
 * ticket on behalf of the customer (e.g. phone call).
 */
#[Layout('layouts.app')]
class AssetShow extends Component
{
    #[Locked]
    public int $assetId;

    public string $comment = '';

    /** @var array{problem: string, urgency: string, contact_name: string, contact_phone: string} */
    public array $report = ['problem' => '', 'urgency' => 'normal', 'contact_name' => '', 'contact_phone' => ''];

    public function mount(CustomerAsset $asset): void
    {
        $this->authorize('view', $asset);
        $this->assetId = $asset->id;
    }

    #[Computed]
    public function asset(): CustomerAsset
    {
        return CustomerAsset::query()->with(['organization', 'item', 'itemSerial', 'order', 'delivery', 'events.user', 'tickets'])->findOrFail($this->assetId);
    }

    public function verify(AssetRegister $register): void
    {
        $this->authorize('manage', $this->asset);
        $register->verify($this->asset, auth()->user());
        unset($this->asset);
        session()->flash('status', __('service.flash.verified'));
    }

    public function writeOff(AssetRegister $register): void
    {
        $this->changeStatus($register, AssetStatus::OutOfService);
    }

    public function reactivate(AssetRegister $register): void
    {
        $this->changeStatus($register, AssetStatus::InUse);
    }

    public function openTicket(ManageServiceTicket $tickets): void
    {
        $this->authorize('manage', $this->asset);
        $this->validate(['report.problem' => ['required', 'string', 'min:5', 'max:5000'], 'report.urgency' => ['required', 'in:'.implode(',', array_keys(ServiceUrgency::options()))]], [], ['report.problem' => __('service.fields.problem')]);

        try {
            $ticket = $tickets->open($this->asset, $this->report, auth()->user(), 'staff');
        } catch (EquipmentException $exception) {
            $this->addError('report.problem', $exception->getMessage());

            return;
        }

        $this->redirectRoute('service.tickets.show', $ticket, navigate: true);
    }

    protected function changeStatus(AssetRegister $register, AssetStatus $to): void
    {
        $this->authorize('manage', $this->asset);

        try {
            $register->changeStatus($this->asset, $to, auth()->user(), $this->comment);
            session()->flash('status', __('service.flash.saved'));
        } catch (EquipmentException $exception) {
            $this->addError('comment', $exception->getMessage());
        }

        $this->reset('comment');
        unset($this->asset);
    }

    public function render(): View
    {
        return view('livewire.service.asset-show', [
            'asset' => $this->asset,
            'canManage' => auth()->user()->can('manage', $this->asset),
            'urgencies' => ServiceUrgency::options(),
        ])->title($this->asset->serial_raw);
    }
}
