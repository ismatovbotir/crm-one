<?php

namespace App\Livewire\Orders;

use App\Actions\Agreements\GenerateAgreement;
use App\Actions\Orders\CancelOrder;
use App\Actions\Orders\ChangeSaleType;
use App\Actions\Orders\ConfirmOrder;
use App\Actions\Orders\LinkFrameworkAgreement;
use App\Actions\Orders\OverrideHold;
use App\Actions\Orders\StartPackaging;
use App\Actions\Orders\UpdateOrderDetails;
use App\Actions\Orders\WaiveAgreement;
use App\Actions\Stock\ManageDeliveryNote;
use App\Actions\Tenders\ManageTender;
use App\Enums\AgreementKind;
use App\Enums\AgreementStatus;
use App\Enums\HoldReason;
use App\Enums\OrderStatus;
use App\Enums\SaleType;
use App\Enums\TenderType;
use App\Livewire\Concerns\HasDocumentTabs;
use App\Models\ActivityLog;
use App\Models\Agreement;
use App\Models\Delivery;
use App\Models\Order;
use App\Models\StatusTransition;
use App\Models\Tender;
use App\Models\User;
use App\Services\Agreements\Exceptions\AgreementActionException;
use App\Services\Orders\CreditExposure;
use App\Services\Orders\Exceptions\OrderActionException;
use App\Services\Orders\OrderFlow;
use App\Services\Orders\StockAvailability;
use App\Services\Stock\Exceptions\StockActionException;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Order detail (Spec 06 10.2, section 25): header with status, sale type, payment status, holds
 * (with release) and the actions allowed by OrderPolicy; the flow stepper with the next action.
 * Standard document tabs (2026-10-20): Header (source, contacts, delivery, terms, dates), Items
 * (snapshot lines with codes, delivered, backorder, reservations) and Other (payments, deliveries,
 * agreement with framework link and waiver, history).
 */
#[Layout('layouts.app')]
class Show extends Component
{
    use HasDocumentTabs;

    #[Locked]
    public int $orderId;

    #[Url(except: 'items')]
    public string $tab = 'items';

    #[Url(except: false)]
    public bool $showCodes = false;

    /** confirm | cancel | override | waive | details | framework */
    public string $panel = '';

    public bool $acknowledgeShortages = false;

    public string $saleType = '';

    public string $reason = '';

    public string $holdReason = '';

    public string $frameworkId = '';

    /** @var array{contact_id: string, billing_contact_id: string, delivery_address: string, requested_delivery_on: string, promised_delivery_on: string} */
    public array $details = ['contact_id' => '', 'billing_contact_id' => '', 'delivery_address' => '', 'requested_delivery_on' => '', 'promised_delivery_on' => ''];

    public function mount(Order $order): void
    {
        $this->authorize('view', $order);

        $this->orderId = $order->id;
    }

    #[Computed]
    public function order(): Order
    {
        return Order::query()->with(['lines.item', 'lines.reservations', 'customer', 'contact', 'owner', 'offer'])->findOrFail($this->orderId);
    }

    #[Computed]
    public function agreement(): ?Agreement
    {
        return $this->order->currentAgreement();
    }

    #[Computed]
    public function framework(): ?Agreement
    {
        return $this->order->framework_agreement_id !== null ? Agreement::query()->find($this->order->framework_agreement_id) : null;
    }

    /**
     * @return Collection<int, Agreement>
     */
    #[Computed]
    public function frameworkOptions(): Collection
    {
        return Agreement::query()->where('customer_id', $this->order->customer_id)->where('kind', AgreementKind::Framework)->where('status', AgreementStatus::Active)->orderByDesc('id')->get();
    }

    /**
     * Confirm preview: availability and shortages per line, stale stock warning, credit check.
     *
     * @return array{shortages: array<int, array{name: string, quantity: string, available: string}>, available: array<int, string>, stale: int|null, credit: array{exposure: int, currency: string|null, limit: int|null, other_currencies: list<string>}, exceeds: bool}
     */
    #[Computed]
    public function confirmPreview(): array
    {
        $order = $this->order;
        $stock = app(StockAvailability::class);
        $available = [];

        foreach ($order->lines as $line) {
            $available[$line->id] = $stock->forItem($line->item_id, $line->warehouse_id, $order->lines->pluck('id')->all());
        }

        return [
            'shortages' => app(ConfirmOrder::class)->shortages($order),
            'available' => $available,
            'stale' => $stock->staleMinutes(),
            'credit' => app(CreditExposure::class)->for($order->customer, $order),
            'exceeds' => app(CreditExposure::class)->wouldExceed($order),
        ];
    }

    /**
     * Status changes and activity merged, newest first.
     *
     * @return SupportCollection<int, array{at: \DateTimeInterface, kind: string, model: object}>
     */
    #[Computed]
    public function timeline(): SupportCollection
    {
        $order = $this->order;
        $items = collect();

        foreach (StatusTransition::query()->with('user')->where('subject_type', 'order')->where('subject_id', $order->id)->get() as $transition) {
            $items->push(['at' => $transition->created_at, 'kind' => 'transition', 'model' => $transition]);
        }

        if (auth()->user()->can('viewActivity', $order)) {
            foreach (ActivityLog::query()->with('causer')->where('subject_type', 'order')->where('subject_id', $order->id)->whereNotIn('event', ['status_changed', 'created'])->get() as $entry) {
                $items->push(['at' => $entry->created_at, 'kind' => 'activity', 'model' => $entry]);
            }
        }

        return $items->sortByDesc(fn (array $item) => $item['at']->getTimestamp())->values();
    }

    /**
     * Users who released holds, by id (for the "Released by" badges).
     *
     * @return array<int, string>
     */
    #[Computed]
    public function holdUsers(): array
    {
        $ids = array_filter(array_column($this->order->hold_reasons ?? [], 'overridden_by'));

        return $ids === [] ? [] : User::query()->whereIn('id', $ids)->pluck('name', 'id')->all();
    }

    public function openPanel(string $panel, string $hold = ''): void
    {
        $this->resetValidation();
        $this->reset(['reason', 'acknowledgeShortages', 'holdReason', 'frameworkId']);
        $this->panel = in_array($panel, ['confirm', 'cancel', 'override', 'waive', 'details', 'framework', 'sale_type'], true) ? $panel : '';
        $this->saleType = $this->order->sale_type->value;
        $this->holdReason = HoldReason::tryFrom($hold)?->value ?? '';

        if ($panel === 'details') {
            $order = $this->order;
            $this->details = [
                'contact_id' => (string) ($order->contact_id ?? ''),
                'billing_contact_id' => (string) ($order->billing_contact_id ?? ''),
                'delivery_address' => (string) $order->delivery_address,
                'requested_delivery_on' => (string) $order->requested_delivery_on?->toDateString(),
                'promised_delivery_on' => (string) $order->promised_delivery_on?->toDateString(),
            ];
        }
    }

    public function closePanel(): void
    {
        $this->panel = '';
        $this->resetValidation();
    }

    public function confirm(ConfirmOrder $confirm): void
    {
        $this->authorize('confirm', $this->order);
        $this->run(fn () => $confirm->handle($this->order, auth()->user(), $this->acknowledgeShortages), 'orders.flash.confirmed');
    }

    public function startPackaging(StartPackaging $start): void
    {
        $this->authorize('startPackaging', $this->order);
        $this->run(fn () => $start->handle($this->order, auth()->user()), 'orders.flash.packaging_started');
    }

    public function changeSaleType(ChangeSaleType $change): void
    {
        $this->authorize('changeSaleType', $this->order);
        $this->validate(['saleType' => ['required', 'in:'.implode(',', array_column(SaleType::cases(), 'value'))]], [], ['saleType' => __('orders.fields.sale_type')]);
        $this->run(fn () => $change->handle($this->order, SaleType::from($this->saleType), auth()->user()), 'orders.flash.sale_type_changed');
    }

    /**
     * Flow stepper and next action (section 25).
     *
     * @return array{steps: list<array{key: string, state: string}>, next: string|null}
     */
    #[Computed]
    public function flow(): array
    {
        $flow = app(OrderFlow::class);

        return ['steps' => $flow->steps($this->order), 'next' => $flow->next($this->order)];
    }

    public function cancel(CancelOrder $cancel): void
    {
        $this->authorize('cancel', $this->order);
        $this->validate(['reason' => ['required', 'string', 'max:2000']], [], ['reason' => __('orders.fields.cancel_reason')]);
        $this->run(fn () => $cancel->handle($this->order, auth()->user(), trim($this->reason)), 'orders.flash.cancelled');
    }

    public function overrideHold(OverrideHold $override): void
    {
        $this->validate([
            'reason' => ['required', 'string', 'max:500'],
            'holdReason' => ['required', 'in:'.implode(',', array_column(HoldReason::cases(), 'value'))],
        ], [], ['reason' => __('orders.fields.override_reason'), 'holdReason' => __('orders.fields.hold')]);
        $hold = HoldReason::from($this->holdReason);
        $this->authorize('overrideHold', [$this->order, $hold]);
        $this->run(fn () => $override->handle($this->order, $hold, auth()->user(), trim($this->reason)), 'orders.flash.hold_released');
    }

    public function waive(WaiveAgreement $waive): void
    {
        $this->authorize('waiveAgreement', $this->order);
        $this->validate(['reason' => ['required', 'string', 'max:255']], [], ['reason' => __('orders.fields.waiver_reason')]);
        $this->run(fn () => $waive->handle($this->order, auth()->user(), trim($this->reason)), 'orders.flash.agreement_waived');
    }

    public function saveDetails(UpdateOrderDetails $update): void
    {
        $this->authorize('updateDetails', $this->order);
        $data = $this->validate([
            'details.contact_id' => ['nullable', 'integer'],
            'details.billing_contact_id' => ['nullable', 'integer'],
            'details.delivery_address' => ['nullable', 'string', 'max:1000'],
            'details.requested_delivery_on' => ['nullable', 'date'],
            'details.promised_delivery_on' => ['nullable', 'date'],
        ], [], [
            'details.contact_id' => __('orders.fields.contact'),
            'details.billing_contact_id' => __('orders.fields.billing_contact'),
            'details.delivery_address' => __('orders.fields.delivery_address'),
            'details.requested_delivery_on' => __('orders.fields.requested_delivery_on'),
            'details.promised_delivery_on' => __('orders.fields.promised_delivery_on'),
        ])['details'];

        $clean = array_map(fn (mixed $value): mixed => $value === '' ? null : $value, $data);
        $this->run(fn () => $update->handle($this->order, $clean), 'orders.flash.details_saved');
    }

    public function linkFramework(LinkFrameworkAgreement $link): void
    {
        $this->authorize('linkFramework', $this->order);
        $this->validate(['frameworkId' => ['required', 'integer']], [], ['frameworkId' => __('orders.fields.framework')]);
        $agreement = Agreement::query()->findOrFail((int) $this->frameworkId);
        $this->run(fn () => $link->handle($this->order, $agreement), 'orders.flash.framework_linked');
    }

    public function unlinkFramework(LinkFrameworkAgreement $link): void
    {
        $this->authorize('linkFramework', $this->order);
        $this->run(fn () => $link->handle($this->order, null), 'orders.flash.framework_unlinked');
    }

    public function generateAgreement(GenerateAgreement $generate): void
    {
        $this->authorize('create', [Agreement::class, $this->order]);

        try {
            $agreement = $generate->handle(AgreementKind::Order, $this->order->customer, $this->order, auth()->user());
        } catch (AgreementActionException $exception) {
            session()->flash('error', $exception->getMessage());

            return;
        }

        session()->flash('status', __('agreements.flash.generated', ['number' => $agreement->number]));
        $this->redirectRoute('agreements.show', $agreement, navigate: true);
    }

    public function createDelivery(ManageDeliveryNote $notes): void
    {
        $this->authorize('create', [Delivery::class, $this->order]);

        try {
            $delivery = $notes->create($this->order, auth()->user());
        } catch (StockActionException $exception) {
            session()->flash('error', $exception->getMessage());

            return;
        }

        session()->flash('status', __('stock.flash.delivery_created', ['number' => $delivery->number]));
        $this->redirectRoute('delivery-notes.show', $delivery, navigate: true);
    }

    /**
     * Transport request from this order (Spec 03 US-12, 6.3): a draft transport tender without an
     * owner, prefilled with destination, delivery promise, cargo description and value; logistics
     * takes it over.
     */
    public function requestTransport(ManageTender $tenders): void
    {
        $this->authorize('view', $this->order);
        $this->authorize('request', Tender::class);
        abort_if(in_array($this->order->status, [OrderStatus::New, OrderStatus::Cancelled, OrderStatus::Closed], true), 403);

        $tender = $tenders->create(TenderType::Transport, ['title' => __('tenders.request_title', ['order' => $this->order->number, 'customer' => (string) $this->order->customer?->name])], auth()->user(), asRequest: true, order: $this->order);

        session()->flash('status', __('tenders.flash.requested', ['number' => $tender->number]));
        $this->redirectRoute('tenders.show', $tender, navigate: true);
    }

    protected function run(callable $action, string $successKey): void
    {
        try {
            $action();
        } catch (OrderActionException|AgreementActionException $exception) {
            session()->flash('error', $exception->getMessage());
            $this->refresh();

            return;
        }

        session()->flash('status', __($successKey));
        $this->panel = '';
        $this->refresh();
    }

    protected function refresh(): void
    {
        unset($this->order, $this->agreement, $this->framework, $this->frameworkOptions, $this->confirmPreview, $this->timeline, $this->holdUsers, $this->flow);
    }

    protected function documentTabs(): array
    {
        return [
            'header' => __('common.document_tabs.header'),
            'items' => $this->documentTab(__('common.document_tabs.items'), $this->order->lines->count()),
            'other' => __('common.document_tabs.other'),
        ];
    }

    protected function documentTabAliases(): array
    {
        return ['lines' => 'items', 'payments' => 'other', 'deliveries' => 'other', 'agreement' => 'other', 'timeline' => 'other'];
    }

    public function render(): View
    {
        return view('livewire.orders.show', [
            'holdReasons' => HoldReason::cases(),
        ])->title($this->order->number);
    }
}
