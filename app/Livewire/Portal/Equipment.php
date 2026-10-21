<?php

namespace App\Livewire\Portal;

use App\Enums\AssetSource;
use App\Enums\AssetStatus;
use App\Models\Item;
use App\Services\Catalog\Exceptions\ItemImageException;
use App\Services\Equipment\AssetRegister;
use App\Services\Equipment\Exceptions\EquipmentException;
use App\Support\PortalContext;
use App\Support\PortalScope;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

/**
 * "My equipment" in the customer portal (Spec 09 §9.1): the company's units with filters, status
 * badges, CSV export, and "Add equipment" for portal admins, buyers and approvers. Every query goes
 * through PortalScope (tenant isolation).
 */
#[Layout('layouts.portal')]
class Equipment extends Component
{
    use WithFileUploads, WithPagination;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $status = '';

    #[Url(except: '')]
    public string $location = '';

    #[Url(except: false)]
    public bool $expiring = false;

    public bool $adding = false;

    /** @var array{serial: string, item_id: string, model_name: string, location: string, purchased_on: string, notes: string} */
    public array $form = ['serial' => '', 'item_id' => '', 'model_name' => '', 'location' => '', 'purchased_on' => '', 'notes' => ''];

    public string $itemSearch = '';

    /** @var TemporaryUploadedFile|null */
    public $photo = null;

    public function updating(string $property): void
    {
        if (in_array($property, ['search', 'status', 'location', 'expiring'], true)) {
            $this->resetPage();
        }
    }

    /**
     * @return Collection<int, Item>
     */
    #[Computed]
    public function itemOptions(): Collection
    {
        $term = trim($this->itemSearch);

        if (mb_strlen($term) < 2) {
            return collect();
        }

        $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $term).'%';

        return Item::query()->where('is_active', true)->where(fn ($q) => $q->where('name', 'like', $like)->orWhere('sku', 'like', $like))->orderBy('name')->limit(20)->get(['id', 'name', 'sku']);
    }

    public function register(PortalContext $context, AssetRegister $register): void
    {
        abort_unless($context->canManageEquipment(), 403);
        $this->validate([
            'form.serial' => ['required', 'string', 'max:100'],
            'form.item_id' => ['nullable', 'integer'],
            'form.model_name' => ['nullable', 'string', 'max:255', 'required_without:form.item_id'],
            'form.location' => ['nullable', 'string', 'max:255'],
            'form.purchased_on' => ['nullable', 'date', 'before_or_equal:today'],
            'form.notes' => ['nullable', 'string', 'max:2000'],
            'photo' => ['nullable', 'file', 'max:5120', 'mimes:jpg,jpeg,png,webp'],
        ], [], ['form.serial' => __('service.fields.serial'), 'form.model_name' => __('service.fields.model')]);

        $key = 'portal-asset-register|'.$context->user()->id;

        if (RateLimiter::tooManyAttempts($key, (int) config('service.portal_registrations_per_user_hour'))) {
            $this->addError('form.serial', __('service.errors.rate_limited'));

            return;
        }

        RateLimiter::hit($key, 3600);

        try {
            $asset = $register->register($context->customer(), [
                'serial' => $this->form['serial'],
                'item_id' => $this->form['item_id'] !== '' ? (int) $this->form['item_id'] : null,
                'model_name' => $this->form['model_name'],
                'location' => $this->form['location'],
                'purchased_on' => $this->form['purchased_on'] !== '' ? $this->form['purchased_on'] : null,
                'notes' => $this->form['notes'],
            ], $context->user(), AssetSource::CustomerRegistered, $this->photo);
        } catch (EquipmentException $exception) {
            $this->addError('form.serial', $exception->getMessage());

            return;
        } catch (ItemImageException $exception) {
            $this->addError('photo', $exception->getMessage());

            return;
        }

        session()->flash('status', __('service.flash.registered'));
        $this->redirectRoute('portal.equipment.show', $asset, navigate: true);
    }

    public function render(PortalScope $scope, PortalContext $context): View
    {
        $term = trim($this->search);
        $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $term).'%';

        $assets = $scope->assets()->with('item:id,name')
            ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w->where('serial_normalized', 'like', '%'.mb_strtoupper(preg_replace('/\s+/', '', $term) ?? '').'%')->orWhere('model_name', 'like', $like)->orWhereHas('item', fn ($i) => $i->where('name', 'like', $like))))
            ->when(AssetStatus::tryFrom($this->status) !== null, fn ($q) => $q->where('status', $this->status))
            ->when(trim($this->location) !== '', fn ($q) => $q->where('location', 'like', '%'.str_replace(['%', '_'], ['\\%', '\\_'], trim($this->location)).'%'))
            ->when($this->expiring, fn ($q) => $q->whereBetween('warranty_until', [now()->toDateString(), now()->addDays((int) config('service.warranty_reminder_days'))->toDateString()]))
            ->orderBy('status')->orderBy('id')
            ->paginate(config('crm.per_page'));

        return view('livewire.portal.equipment', [
            'assets' => $assets,
            'statuses' => AssetStatus::options(),
            'canManage' => $context->canManageEquipment(),
        ])->title(__('service.portal.title'));
    }
}
