<?php

namespace App\Livewire\Orders;

use App\Actions\Orders\CreateManualOrder;
use App\Enums\DeliveryMethod;
use App\Enums\SaleType;
use App\Livewire\Concerns\HasDocumentTabs;
use App\Models\Customer;
use App\Models\Item;
use App\Models\Order;
use App\Models\Warehouse;
use App\Services\Offers\DiscountRules;
use App\Services\Orders\Exceptions\OrderActionException;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Manual order without an offer (Spec 06 section 31): partner (customer role only), sale type,
 * delivery or self pick-up with address, one warehouse, note, framework agreement choice and
 * catalogue lines with quantity and a discount within the user's limit. Prices, VAT and the
 * snapshot come from CreateManualOrder exactly like offer lines.
 *
 * Standard document tabs (2026-10-20): Header and Items in one form and one component state, so
 * switching never loses input; a tab with validation errors is marked, and a failed save opens
 * the first tab with errors.
 */
#[Layout('layouts.app')]
class Create extends Component
{
    use HasDocumentTabs;

    /** Fields shown on the Header tab (everything else belongs to Items). */
    private const HEADER_FIELDS = ['customerId', 'saleType', 'deliveryMethod', 'deliveryAddress', 'warehouseId', 'note'];

    #[Url(except: 'header')]
    public string $tab = 'header';

    #[Url(as: 'customer', except: '')]
    public string $customerId = '';

    public string $customerSearch = '';

    public string $saleType = '';

    public string $deliveryMethod = 'delivery';

    public string $deliveryAddress = '';

    public string $warehouseId = '';

    public string $note = '';

    public bool $ownAgreement = false;

    public string $itemSearch = '';

    /** @var list<array{item_id: int, name: string, sku: string, quantity: string, discount: string}> */
    public array $lines = [];

    public function mount(): void
    {
        $this->authorize('create', Order::class);
        $this->warehouseId = (string) (Warehouse::query()->where('is_active', true)->orderBy('id')->value('id') ?? '');

        if (ctype_digit($this->customerId)) {
            $this->selectCustomer((int) $this->customerId);
        }
    }

    /**
     * Partners with the customer role the user may sell to.
     *
     * @return Collection<int, Customer>
     */
    #[Computed]
    public function customerOptions(): Collection
    {
        $term = trim($this->customerSearch);

        return Customer::query()->visibleTo(auth()->user())->notDeletedInOneC()
            ->when($term !== '', fn ($query) => $query->where(fn ($q) => $q->where('name', 'like', '%'.$term.'%')->orWhere('tax_id', 'like', '%'.$term.'%')))
            ->orderBy('name')->limit(15)->get(['id', 'name', 'tax_id', 'owner_id']);
    }

    /**
     * @return Collection<int, Item>
     */
    #[Computed]
    public function itemOptions(): Collection
    {
        $term = trim($this->itemSearch);

        return $term === '' ? new Collection : Item::query()->where('is_active', true)
            ->where(fn ($q) => $q->where('sku', 'like', '%'.$term.'%')->orWhere('name', 'like', '%'.$term.'%'))
            ->orderBy('name')->limit(10)->get(['id', 'sku', 'name']);
    }

    /**
     * @return Collection<int, Warehouse>
     */
    #[Computed]
    public function warehouses(): Collection
    {
        return Warehouse::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']);
    }

    #[Computed]
    public function customer(): ?Customer
    {
        return ctype_digit($this->customerId) ? Customer::query()->visibleTo(auth()->user())->find((int) $this->customerId) : null;
    }

    public function selectCustomer(int $id): void
    {
        // Prefill (?customer=) and picks are limited to customers the user may view; others are ignored.
        $customer = Customer::query()->visibleTo(auth()->user())->notDeletedInOneC()->find($id);

        if ($customer === null) {
            $this->customerId = '';

            return;
        }

        $this->customerId = (string) $customer->id;
        $this->customerSearch = $customer->name;
        $this->saleType = CreateManualOrder::defaultSaleType($customer)->value;
        $this->deliveryAddress = $this->deliveryAddress !== '' ? $this->deliveryAddress : (string) $customer->address;
        unset($this->customer);
    }

    public function clearCustomer(): void
    {
        $this->reset('customerId', 'customerSearch', 'saleType');
        unset($this->customer);
    }

    public function addItem(int $itemId): void
    {
        $item = Item::query()->find($itemId);

        if ($item === null || collect($this->lines)->contains('item_id', $item->id)) {
            return;
        }

        $this->lines[] = ['item_id' => $item->id, 'name' => $item->name, 'sku' => $item->sku, 'quantity' => '1', 'discount' => '0'];
        $this->itemSearch = '';
    }

    public function removeLine(int $index): void
    {
        unset($this->lines[$index]);
        $this->lines = array_values($this->lines);
    }

    public function save(CreateManualOrder $create): void
    {
        $this->authorize('create', Order::class);

        try {
            $this->validateOrder();
        } catch (ValidationException $exception) {
            $this->tab = array_intersect(array_keys($exception->errors()), self::HEADER_FIELDS) !== [] ? 'header' : 'items';

            throw $exception;
        }

        $this->createOrder($create);
    }

    protected function validateOrder(): void
    {
        $this->validate([
            'customerId' => ['required', 'integer', 'exists:partners,id'],
            'saleType' => ['required', 'in:'.implode(',', array_column(SaleType::cases(), 'value'))],
            'deliveryMethod' => ['required', 'in:'.implode(',', array_column(DeliveryMethod::cases(), 'value'))],
            'deliveryAddress' => [$this->deliveryMethod === 'delivery' ? 'required' : 'nullable', 'string', 'max:2000'],
            'warehouseId' => ['required', 'integer', 'exists:warehouses,id'],
            'note' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.quantity' => ['required', 'string', 'max:20'],
            'lines.*.discount' => ['nullable', 'regex:/^\d{1,3}([.,]\d{1,2})?$/'],
        ], [], [
            'customerId' => __('orders.fields.customer'),
            'saleType' => __('orders.fields.sale_type'),
            'deliveryMethod' => __('orders.fields.delivery_method'),
            'deliveryAddress' => __('orders.fields.delivery_address'),
            'warehouseId' => __('orders.fields.warehouse'),
            'lines' => __('orders.create.lines'),
        ]);
    }

    protected function createOrder(CreateManualOrder $create): void
    {
        try {
            $order = $create->handle([
                'customer_id' => (int) $this->customerId,
                'sale_type' => $this->saleType,
                'delivery_method' => $this->deliveryMethod,
                'delivery_address' => $this->deliveryAddress,
                'warehouse_id' => (int) $this->warehouseId,
                'note' => $this->note,
                'own_agreement' => $this->ownAgreement,
                'lines' => array_map(fn (array $line): array => [
                    'item_id' => (int) $line['item_id'],
                    'quantity' => (string) $line['quantity'],
                    'discount_bp' => (int) bcmul(str_replace(',', '.', trim((string) ($line['discount'] ?: '0'))), '100', 0),
                ], $this->lines),
            ], auth()->user());
        } catch (OrderActionException $exception) {
            session()->flash('error', $exception->getMessage());

            return;
        }

        session()->flash('status', __('orders.create.created', ['number' => $order->number]));
        $this->redirectRoute('orders.show', $order, navigate: true);
    }

    protected function defaultDocumentTab(): string
    {
        return 'header';
    }

    protected function documentTabs(): array
    {
        $errors = array_keys($this->getErrorBag()->toArray());
        $headerErrors = array_intersect($errors, self::HEADER_FIELDS) !== [];
        $itemErrors = array_diff($errors, self::HEADER_FIELDS) !== [];
        $marker = __('common.document_tabs.has_errors');

        return [
            'header' => $this->documentTab(__('common.document_tabs.header'), null, $headerErrors ? $marker : null),
            'items' => $this->documentTab(__('common.document_tabs.items'), count($this->lines), $itemErrors ? $marker : null),
        ];
    }

    public function render(): View
    {
        return view('livewire.orders.create', [
            'saleTypes' => SaleType::options(),
            'deliveryMethods' => DeliveryMethod::options(),
            'discountLimit' => app(DiscountRules::class)->limitFor(auth()->user()),
        ])->title(__('orders.create.title'));
    }
}
