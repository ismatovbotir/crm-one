<?php

namespace App\Livewire\Stock;

use App\Actions\Stock\ManageDeliveryNote;
use App\Actions\Stock\Packaging;
use App\Actions\Stock\PostDeliveryNote;
use App\Livewire\Concerns\HasDocumentTabs;
use App\Models\ActivityLog;
use App\Models\Delivery;
use App\Models\DeliveryLine;
use App\Services\Stock\Exceptions\StockActionException;
use App\Support\Quantity;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Packing list and delivery note (Spec 06 21.10, section 25): while packing, plain lines with
 * editable quantities and serialized lines with the serial grid and counter; "Complete packaging"
 * (disabled with the list of incomplete lines) locks the list; "Reopen packaging" (with a reason)
 * unlocks it; "Post delivery" only from a complete list. Cancel the draft; print.
 *
 * Standard document tabs (2026-10-20): Header (order, customer, warehouse, dates, packing),
 * Items (lines, quantities, serials) and Other (related documents, change log); summary, actions,
 * pick-up handover and the reopen panel stay above.
 */
#[Layout('layouts.app')]
class DeliveryShow extends Component
{
    use HasDocumentTabs;

    #[Url(except: 'items')]
    public string $tab = 'items';

    #[Locked]
    public int $deliveryId;

    /** @var array<int, string> */
    public array $quantities = [];

    public string $reopenReason = '';

    public bool $reopening = false;

    /** Self pick-up handover (Spec 06 section 31). */
    public string $pickupPerson = '';

    public string $pickupDocument = '';

    public function mount(Delivery $delivery): void
    {
        $this->authorize('view', $delivery);
        $this->deliveryId = $delivery->id;
        $this->fillQuantities();
        $this->pickupPerson = (string) $delivery->pickup_person;
        $this->pickupDocument = (string) $delivery->pickup_document;
    }

    #[Computed]
    public function delivery(): Delivery
    {
        return Delivery::query()->with(['order.customer', 'warehouse', 'lines.item', 'lines.orderLine'])->findOrFail($this->deliveryId);
    }

    /**
     * Serialized lines whose serial count does not match yet (post stays disabled).
     *
     * @return list<string>
     */
    #[Computed]
    public function incomplete(): array
    {
        return app(Packaging::class)->incomplete($this->delivery);
    }

    public function saveQuantity(ManageDeliveryNote $notes, int $lineId): void
    {
        $this->authorize('update', $this->delivery);
        $line = $this->delivery->lines->firstWhere('id', $lineId) ?? abort(404);
        $this->run(fn () => $notes->updateLine($line, (string) ($this->quantities[$lineId] ?? '0')), 'stock.flash.line_saved');
    }

    public function savePickup(): void
    {
        $this->authorize('post', $this->delivery);
        $this->validate([
            'pickupPerson' => ['required', 'string', 'max:160'],
            'pickupDocument' => ['required', 'string', 'max:60'],
        ], [], ['pickupPerson' => __('stock.pickup.person'), 'pickupDocument' => __('stock.pickup.document')]);
        $this->delivery->forceFill(['pickup_person' => trim($this->pickupPerson), 'pickup_document' => trim($this->pickupDocument)])->save();
        unset($this->delivery);
        session()->flash('status', __('stock.pickup.saved'));
    }

    public function post(PostDeliveryNote $post): void
    {
        $this->authorize('post', $this->delivery);
        $this->run(fn () => $post->handle($this->delivery, auth()->user()), 'stock.flash.delivery_posted');
    }

    public function completePackaging(Packaging $packaging): void
    {
        $this->authorize('completePackaging', $this->delivery);
        $this->run(fn () => $packaging->complete($this->delivery, auth()->user()), 'stock.flash.packaging_completed');
    }

    public function reopenPackaging(Packaging $packaging): void
    {
        $this->authorize('reopenPackaging', $this->delivery);
        $this->validate(['reopenReason' => ['required', 'string', 'min:5', 'max:500']], [], ['reopenReason' => __('stock.packaging.reopen_reason')]);
        $this->run(fn () => $packaging->reopen($this->delivery, auth()->user(), $this->reopenReason), 'stock.flash.packaging_reopened');
        $this->reset('reopenReason', 'reopening');
    }

    public function cancel(ManageDeliveryNote $notes): void
    {
        $this->authorize('update', $this->delivery);
        $this->run(fn () => $notes->cancel($this->delivery, auth()->user()), 'stock.flash.delivery_cancelled');
    }

    #[On('serials-updated')]
    public function serialsUpdated(): void
    {
        unset($this->delivery, $this->incomplete);
    }

    protected function run(callable $action, string $successKey): void
    {
        try {
            $action();
        } catch (StockActionException $exception) {
            session()->flash('error', $exception->getMessage());
            unset($this->delivery, $this->incomplete);

            return;
        }

        session()->flash('status', __($successKey));
        unset($this->delivery, $this->incomplete);
        $this->fillQuantities();
    }

    protected function fillQuantities(): void
    {
        $this->quantities = $this->delivery->lines->mapWithKeys(fn (DeliveryLine $line): array => [$line->id => Quantity::trimmed((string) $line->quantity)])->all();
    }

    /**
     * Change log of the delivery note (Other tab; loaded only there).
     *
     * @return Collection<int, ActivityLog>
     */
    #[Computed]
    public function activities(): Collection
    {
        return ActivityLog::query()->with('causer')->where('subject_type', 'delivery')->where('subject_id', $this->deliveryId)->latest('created_at')->limit(100)->get();
    }

    protected function documentTabs(): array
    {
        return [
            'header' => __('common.document_tabs.header'),
            'items' => $this->documentTab(__('common.document_tabs.items'), $this->delivery->lines->count()),
            'other' => __('common.document_tabs.other'),
        ];
    }

    public function render(): View
    {
        return view('livewire.stock.delivery-show', ['serialLineType' => DeliveryLine::LINE_TYPE])->title($this->delivery->number);
    }
}
