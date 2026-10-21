<?php

namespace App\Livewire\Portal;

use App\Actions\Service\ManageServiceTicket;
use App\Enums\ServiceUrgency;
use App\Models\CustomerAsset;
use App\Models\ServiceTicket;
use App\Services\Catalog\Exceptions\ItemImageException;
use App\Services\Equipment\Exceptions\EquipmentException;
use App\Support\PortalContext;
use App\Support\PortalScope;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * One unit in the portal (Spec 09 §9.1): details, warranty, full history, its repair requests with
 * public notes, "Report a problem" (rate limited, up to 3 photos), "Mark as in storage" / "Back in
 * use", cancel an untouched request. Foreign uuids are 404 (PortalScope).
 */
#[Layout('layouts.portal')]
class EquipmentShow extends Component
{
    use WithFileUploads;

    #[Locked]
    public int $assetId;

    public bool $reporting = false;

    /** @var array{problem: string, urgency: string, contact_name: string, contact_phone: string, location: string} */
    public array $report = ['problem' => '', 'urgency' => 'normal', 'contact_name' => '', 'contact_phone' => '', 'location' => ''];

    /** @var list<TemporaryUploadedFile> */
    public array $photos = [];

    public function mount(string $asset, PortalScope $scope): void
    {
        $this->assetId = (int) $scope->assets()->where('uuid', $asset)->valueOrFail('id');
    }

    #[Computed]
    public function asset(): CustomerAsset
    {
        return app(PortalScope::class)->assets()->with(['item', 'itemSerial', 'order', 'delivery', 'events.user', 'tickets.notes', 'tickets.photos'])->findOrFail($this->assetId);
    }

    public function reportProblem(PortalContext $context, ManageServiceTicket $tickets): void
    {
        abort_unless($context->canManageEquipment(), 403);
        $this->validate([
            'report.problem' => ['required', 'string', 'min:5', 'max:5000'],
            'report.urgency' => ['required', 'in:'.implode(',', array_keys(ServiceUrgency::options()))],
            'report.contact_name' => ['nullable', 'string', 'max:255'],
            'report.contact_phone' => ['nullable', 'string', 'max:32'],
            'report.location' => ['nullable', 'string', 'max:255'],
            'photos' => ['array', 'max:'.(int) config('service.max_photos')],
            'photos.*' => ['file', 'max:5120', 'mimes:jpg,jpeg,png,webp'],
        ], [], ['report.problem' => __('service.fields.problem')]);

        foreach (['portal-service-report-user|'.$context->user()->id => (int) config('service.portal_reports_per_user_hour'), 'portal-service-report-org|'.$context->customerId() => (int) config('service.portal_reports_per_organization_hour')] as $key => $limit) {
            if (RateLimiter::tooManyAttempts($key, $limit)) {
                $this->addError('report.problem', __('service.errors.rate_limited'));

                return;
            }
        }

        RateLimiter::hit('portal-service-report-user|'.$context->user()->id, 3600);
        RateLimiter::hit('portal-service-report-org|'.$context->customerId(), 3600);

        try {
            $ticket = $tickets->open($this->asset, $this->report, $context->user(), 'portal', $this->photos);
        } catch (EquipmentException $exception) {
            $this->addError('report.problem', $exception->getMessage());

            return;
        } catch (ItemImageException $exception) {
            $this->addError('photos', $exception->getMessage());

            return;
        }

        $this->reset('report', 'photos', 'reporting');
        unset($this->asset);
        session()->flash('status', __('service.flash.reported', ['number' => $ticket->number]));
    }

    public function cancelTicket(int $ticketId, PortalContext $context, ManageServiceTicket $tickets): void
    {
        abort_unless($context->canManageEquipment(), 403);
        $ticket = ServiceTicket::query()->where('customer_asset_id', $this->assetId)->findOrFail($ticketId);

        try {
            $tickets->cancelByCustomer($ticket, $context->user());
            session()->flash('status', __('service.flash.cancelled'));
        } catch (EquipmentException $exception) {
            session()->flash('status', $exception->getMessage());
        }

        unset($this->asset);
    }

    public function render(PortalContext $context): View
    {
        return view('livewire.portal.equipment-show', [
            'asset' => $this->asset,
            'canManage' => $context->canManageEquipment(),
            'urgencies' => ServiceUrgency::options(),
        ])->title($this->asset->displayName().' · '.$this->asset->serial_raw);
    }
}
