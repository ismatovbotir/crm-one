<?php

namespace App\Livewire\Tenders;

use App\Actions\Tenders\ManageTender;
use App\Enums\Incoterm;
use App\Enums\TenderType;
use App\Models\Item;
use App\Models\Tender;
use App\Models\TenderItem;
use App\Models\TenderLot;
use App\Services\Tenders\Exceptions\TenderActionException;
use App\Support\Money;
use App\Support\Quantity;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Lots of a tender (wizard step 2): purchase items (catalog or free text) or the transport RFQ in
 * the skill's sections Route, Dates, Mode & equipment, Cargo, Handling, Responsibility,
 * Documents, SLA. Read-only once the tender left the draft.
 */
class LotEditor extends Component
{
    /**
     * Transport spec form: section => field => input type.
     *
     * @var array<string, array<string, string>>
     */
    public const SECTIONS = [
        'route' => [
            'origin_country' => 'country', 'origin_city' => 'text', 'origin_address' => 'textarea', 'origin_postcode' => 'text',
            'destination_country' => 'country', 'destination_city' => 'text', 'destination_address' => 'textarea', 'destination_postcode' => 'text',
            'border_policy' => 'select:border_policy', 'border_crossing' => 'text', 'clearance_place' => 'text', 'avoid_countries' => 'list',
            'sanctions_reviewed' => 'bool',
        ],
        'dates' => [
            'readiness_date' => 'date', 'pickup_from' => 'date', 'pickup_to' => 'date', 'delivery_from' => 'date', 'delivery_to' => 'date',
            'max_transit_days' => 'int',
        ],
        'mode' => [
            'mode' => 'select:mode', 'load_type' => 'select:load_type', 'vehicle_type' => 'text', 'units_count' => 'int',
            'transshipment_allowed' => 'tri', 'partial_allowed' => 'tri',
        ],
        'cargo' => [
            'cargo_description' => 'textarea', 'hs_codes' => 'list', 'gross_kg' => 'decimal', 'net_kg' => 'decimal', 'volume_m3' => 'decimal',
            'packages_count' => 'int', 'package_type' => 'text', 'pallets_count' => 'int', 'pallet_type' => 'text', 'dimensions' => 'text',
            'stackable' => 'tri', 'cargo_value' => 'money:cargo_currency', 'cargo_currency' => 'currency',
            'is_dangerous' => 'tri', 'un_number' => 'text', 'adr_class' => 'text', 'packing_group' => 'text',
            'is_temperature_controlled' => 'tri', 'temp_min_c' => 'decimal', 'temp_max_c' => 'decimal',
            'is_oversize' => 'tri', 'oversize_notes' => 'text', 'special_handling' => 'textarea',
        ],
        'handling' => [
            'loading_method' => 'select:handling_method', 'loading_equipment' => 'text', 'loaded_by' => 'select:handled_by',
            'unloading_method' => 'select:handling_method', 'unloading_equipment' => 'text', 'unloaded_by' => 'select:handled_by',
            'free_loading_hours' => 'int', 'free_customs_hours' => 'int', 'free_unloading_hours' => 'int',
            'container_owner' => 'select:container_owner', 'container_free_days' => 'int', 'container_return_location' => 'text',
        ],
        'responsibility' => [
            'incoterm' => 'incoterm', 'incoterm_place' => 'text', 'export_clearance_by' => 'select:clearance_by', 'import_clearance_by' => 'select:clearance_by',
            'importer_of_record' => 'text', 'charges_payer' => 'payer', 'insurance_by' => 'select:insurance_by', 'insured_value' => 'money:cargo_currency',
            'insurance_cover' => 'text',
        ],
        'documents' => [
            'required_documents' => 'multi:documents', 'driver_vehicle_documents' => 'multi:driver_documents', 'tracking_requirement' => 'textarea',
        ],
        'sla' => [
            'penalties' => 'textarea', 'late_penalty_percent' => 'percent', 'penalty_cap_percent' => 'percent', 'fuel_adjustment' => 'textarea',
            'force_majeure' => 'textarea', 'site_contacts' => 'textarea',
        ],
    ];

    #[Locked]
    public int $tenderId;

    public ?int $lotId = null;

    public string $lotTitle = '';

    public string $lotDescription = '';

    public string $newLotTitle = '';

    /** @var array<string, mixed> */
    public array $spec = [];

    public string $itemSearch = '';

    /** @var array{name: string, unit_code: string, unit_precision: string, quantity: string, specification: string, alternatives_allowed: bool, required_by: string, hs_code: string, target_price: string} */
    public array $item = ['name' => '', 'unit_code' => '', 'unit_precision' => '0', 'quantity' => '1', 'specification' => '', 'alternatives_allowed' => false, 'required_by' => '', 'hs_code' => '', 'target_price' => ''];

    public ?int $catalogItemId = null;

    public ?int $editingItemId = null;

    public function mount(int $tenderId): void
    {
        $this->tenderId = $tenderId;
        $this->authorize('view', $this->tender);
        $this->selectLot($this->tender->lots->first()?->id);
    }

    #[Computed]
    public function tender(): Tender
    {
        return Tender::query()->with(['lots.items', 'lots.transportSpec'])->findOrFail($this->tenderId);
    }

    #[Computed]
    public function lot(): ?TenderLot
    {
        return $this->tender->lots->firstWhere('id', $this->lotId);
    }

    /**
     * @return Collection<int, Item>
     */
    #[Computed]
    public function itemOptions(): Collection
    {
        $term = trim($this->itemSearch);

        return $term === '' ? new Collection : Item::query()->with('unit')->where(fn ($q) => $q->where('sku', 'like', '%'.$term.'%')->orWhere('name', 'like', '%'.$term.'%'))->orderBy('name')->limit(10)->get();
    }

    public function selectLot(?int $lotId): void
    {
        $this->lotId = $lotId;
        unset($this->lot);
        $lot = $this->lot;
        $this->lotTitle = (string) $lot?->title;
        $this->lotDescription = (string) $lot?->description;
        $this->fillSpec();
        $this->resetItemForm();
    }

    public function addLot(ManageTender $manage): void
    {
        $this->authorize('update', $this->tender);
        $this->validate(['newLotTitle' => ['required', 'string', 'max:255']], [], ['newLotTitle' => __('tenders.lots.title')]);
        $lot = $this->run(fn () => $manage->addLot($this->tender, trim($this->newLotTitle)));
        $this->newLotTitle = '';

        if ($lot instanceof TenderLot) {
            unset($this->tender);
            $this->selectLot($lot->id);
        }
    }

    public function saveLot(ManageTender $manage): void
    {
        $this->authorize('update', $this->tender);
        $this->validate(['lotTitle' => ['required', 'string', 'max:255'], 'lotDescription' => ['nullable', 'string', 'max:5000']]);
        $this->run(fn () => $manage->updateLot($this->lot, trim($this->lotTitle), trim($this->lotDescription) ?: null), 'tenders.flash.saved');
    }

    public function removeLot(ManageTender $manage): void
    {
        $this->authorize('update', $this->tender);
        $this->run(fn () => $manage->removeLot($this->lot));
        unset($this->tender);
        $this->selectLot($this->tender->lots->first()?->id);
    }

    public function saveSpec(ManageTender $manage): void
    {
        $this->authorize('update', $this->tender);
        $data = [];
        $currency = (string) ($this->spec['cargo_currency'] ?: 'USD');

        foreach (self::SECTIONS as $fields) {
            foreach ($fields as $field => $type) {
                $value = $this->spec[$field] ?? null;
                [$kind] = explode(':', $type);

                try {
                    match ($kind) {
                        'money' => $data[$field === 'cargo_value' ? 'cargo_value_minor' : 'insured_value_minor'] = blank($value) ? null : Money::fromMajor((string) $value, $currency)->amount,
                        'percent' => $data[$field === 'late_penalty_percent' ? 'late_penalty_bp_per_day' : 'penalty_cap_bp'] = blank($value) ? null : (int) bcmul(Quantity::normalize((string) $value), '100', 0),
                        'tri' => $data[$field] = $value === '' || $value === null ? null : $value === '1',
                        'bool' => $data[$field] = (bool) $value,
                        'list' => $data[$field] = array_values(array_filter(array_map(fn (string $v): string => strtoupper(trim($v)), preg_split('/[,;\s]+/', (string) $value) ?: []))),
                        'multi' => $data[$field] = array_values(array_intersect((array) $value, config('tenders.vocab.'.explode(':', $type)[1]))),
                        'payer' => $data[$field] = array_filter(array_intersect_key((array) $value, array_flip(config('tenders.vocab.payer_charges'))), fn ($v): bool => in_array($v, config('tenders.vocab.payer'), true)),
                        'int' => $data[$field] = blank($value) ? null : max(0, (int) $value),
                        'decimal' => $data[$field] = blank($value) ? null : $this->decimal((string) $value, $field),
                        'country' => $data[$field] = blank($value) ? null : strtoupper(substr(trim((string) $value), 0, 2)),
                        default => $data[$field] = blank($value) ? null : trim((string) $value),
                    };
                } catch (InvalidArgumentException) {
                    $this->addError('spec.'.$field, __('tenders.errors.amount_invalid', ['value' => (string) $value]));

                    return;
                }
            }
        }

        if ($this->getErrorBag()->isNotEmpty()) {
            return;
        }

        $data['units_count'] = max(1, (int) ($data['units_count'] ?? 1));
        $this->run(fn () => $manage->updateTransportSpec($this->lot, $data), 'tenders.flash.saved');
    }

    public function pickCatalogItem(int $itemId): void
    {
        $item = Item::query()->with('unit')->findOrFail($itemId);
        $this->catalogItemId = $item->id;
        $this->item['name'] = $item->name;
        $this->item['unit_code'] = (string) $item->unit?->code;
        $this->item['unit_precision'] = (string) ($item->unit?->precision ?? 0);
        $this->itemSearch = '';
    }

    public function editItem(int $id): void
    {
        $line = TenderItem::query()->where('lot_id', $this->lotId)->findOrFail($id);
        $this->editingItemId = $line->id;
        $this->catalogItemId = $line->item_id;
        $this->item = [
            'name' => $line->name, 'unit_code' => (string) $line->unit_code, 'unit_precision' => (string) $line->unit_precision,
            'quantity' => Quantity::trimmed((string) $line->quantity), 'specification' => (string) $line->specification,
            'alternatives_allowed' => $line->alternatives_allowed, 'required_by' => (string) $line->required_by?->toDateString(),
            'hs_code' => (string) $line->hs_code, 'target_price' => $line->target_price_minor !== null ? Money::of($line->target_price_minor, $this->tender->evaluation_currency)->toMajor() : '',
        ];
    }

    public function saveItem(ManageTender $manage): void
    {
        $this->authorize('update', $this->tender);
        $this->validate([
            'item.name' => ['required_without:catalogItemId', 'nullable', 'string', 'max:255'],
            'item.unit_code' => ['nullable', 'string', 'max:20'],
            'item.unit_precision' => ['nullable', 'integer', 'between:0,3'],
            'item.quantity' => ['required', 'string', 'max:20'],
            'item.specification' => ['nullable', 'string', 'max:5000'],
            'item.required_by' => ['nullable', 'date'],
            'item.hs_code' => ['nullable', 'regex:/^\d{6,10}$/'],
            'item.target_price' => ['nullable', 'string', 'max:30'],
        ], [], ['item.name' => __('tenders.items.name'), 'item.quantity' => __('tenders.items.quantity'), 'item.hs_code' => __('tenders.items.hs_code')]);

        try {
            $target = blank($this->item['target_price']) ? null : Money::fromMajor($this->item['target_price'], $this->tender->evaluation_currency)->amount;
        } catch (InvalidArgumentException) {
            $this->addError('item.target_price', __('tenders.errors.amount_invalid', ['value' => $this->item['target_price']]));

            return;
        }

        $line = $this->editingItemId !== null ? TenderItem::query()->where('lot_id', $this->lotId)->findOrFail($this->editingItemId) : null;
        $result = $this->run(fn () => $manage->saveItem($this->lot, $line, [
            'item_id' => $this->catalogItemId,
            'name' => $this->item['name'],
            'unit_code' => $this->item['unit_code'] ?: null,
            'unit_precision' => (int) $this->item['unit_precision'],
            'quantity' => $this->item['quantity'],
            'specification' => trim($this->item['specification']) ?: null,
            'alternatives_allowed' => (bool) $this->item['alternatives_allowed'],
            'required_by' => $this->item['required_by'] ?: null,
            'hs_code' => $this->item['hs_code'] ?: null,
            'target_price_minor' => $target,
        ]), 'tenders.flash.saved');

        if ($result !== null) {
            $this->resetItemForm();
        }
    }

    public function removeItem(ManageTender $manage, int $id): void
    {
        $this->authorize('update', $this->tender);
        $this->run(fn () => $manage->removeItem(TenderItem::query()->where('lot_id', $this->lotId)->findOrFail($id)));
    }

    public function resetItemForm(): void
    {
        $this->reset('item', 'catalogItemId', 'editingItemId', 'itemSearch');
    }

    protected function decimal(string $value, string $field): string
    {
        $normalized = Quantity::normalize($value);

        if (! is_numeric($normalized)) {
            throw new InvalidArgumentException($field);
        }

        return $normalized;
    }

    protected function run(callable $action, ?string $successKey = null): mixed
    {
        try {
            $result = $action();
        } catch (TenderActionException $exception) {
            session()->flash('error', $exception->getMessage());
            $this->redirectRoute('tenders.show', ['tender' => $this->tenderId, 'tab' => 'lots'], navigate: true);

            return null;
        }

        if ($successKey !== null) {
            session()->flash('status', __($successKey));
        }

        unset($this->tender, $this->lot);
        $this->fillSpec();
        $this->dispatch('tender-updated');

        return $result ?? true;
    }

    protected function fillSpec(): void
    {
        $spec = $this->lot?->transportSpec;
        $this->spec = [];

        if ($spec === null) {
            return;
        }

        $currency = (string) ($spec->cargo_currency ?: 'USD');

        foreach (self::SECTIONS as $fields) {
            foreach ($fields as $field => $type) {
                [$kind] = explode(':', $type);
                $raw = match ($field) {
                    'cargo_value' => $spec->cargo_value_minor,
                    'insured_value' => $spec->insured_value_minor,
                    'late_penalty_percent' => $spec->late_penalty_bp_per_day,
                    'penalty_cap_percent' => $spec->penalty_cap_bp,
                    default => $spec->getAttribute($field),
                };

                $this->spec[$field] = match ($kind) {
                    'money' => $raw === null ? '' : Money::of((int) $raw, $currency)->toMajor(),
                    'percent' => $raw === null ? '' : bcdiv((string) $raw, '100', 2),
                    'tri' => $raw === null ? '' : ($raw ? '1' : '0'),
                    'bool' => (bool) $raw,
                    'list' => implode(', ', (array) $raw),
                    'multi', 'payer' => (array) $raw,
                    'date' => $raw?->toDateString() ?? '',
                    default => $raw === null ? '' : (string) $raw,
                };
            }
        }
    }

    public function render(): View
    {
        return view('livewire.tenders.lot-editor', [
            'editable' => auth()->user()->can('update', $this->tender),
            'isTransport' => $this->tender->type === TenderType::Transport,
            'sections' => self::SECTIONS,
            'incoterms' => Incoterm::options(),
        ]);
    }
}
