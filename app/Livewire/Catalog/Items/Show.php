<?php

namespace App\Livewire\Catalog\Items;

use App\Authorization\PermissionMap;
use App\Enums\SerialStatus;
use App\Models\Item;
use App\Models\ItemAlternative;
use App\Models\ItemImage;
use App\Models\ItemSerial;
use App\Models\Lead;
use App\Models\Offer;
use App\Models\Order;
use App\Support\ItemCodes;
use App\Support\Quantity;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Item detail (read-only 1C data) with CRM-local fields, the single sale and purchase price, the
 * stock cost price (weighted average of the stock rows), stock per warehouse with cost, and the
 * change log (rev. 4).
 */
#[Layout('layouts.app')]
class Show extends Component
{
    #[Locked]
    public int $itemId;

    /** Selected tab (Spec 01 section 17): kept in the URL so links and reloads open it. */
    #[Url(except: 'overview')]
    public string $tab = 'overview';

    public string $min_order_qty = '';

    public string $order_step = '';

    /** Shared accounting codes and serial flag (Spec 02 BR-I.13a/b). */
    public string $accountant_code = '';

    public string $class_code = '';

    public string $package_code = '';

    public bool $is_serialized = false;

    public string $warranty_months = '';

    /** @var array{hs_code: string, gross_weight_kg: string, net_weight_kg: string, volume_m3: string, country_of_origin: string} */
    public array $cargo = ['hs_code' => '', 'gross_weight_kg' => '', 'net_weight_kg' => '', 'volume_m3' => '', 'country_of_origin' => ''];

    public function mount(Item $item): void
    {
        $this->authorize('view', $item);

        $this->itemId = $item->id;
        $this->min_order_qty = $item->min_order_qty === null ? '' : rtrim(rtrim((string) $item->min_order_qty, '0'), '.');
        $this->order_step = $item->order_step === null ? '' : rtrim(rtrim((string) $item->order_step, '0'), '.');
        $this->accountant_code = (string) $item->accountant_code;
        $this->class_code = (string) $item->class_code;
        $this->package_code = (string) $item->package_code;
        $this->is_serialized = (bool) $item->is_serialized;
        $this->warranty_months = (string) ($item->warranty_months ?? '');
        $this->cargo = [
            'hs_code' => (string) $item->hs_code,
            'gross_weight_kg' => $item->gross_weight_kg === null ? '' : rtrim(rtrim((string) $item->gross_weight_kg, '0'), '.'),
            'net_weight_kg' => $item->net_weight_kg === null ? '' : rtrim(rtrim((string) $item->net_weight_kg, '0'), '.'),
            'volume_m3' => $item->volume_m3 === null ? '' : rtrim(rtrim((string) $item->volume_m3, '0'), '.'),
            'country_of_origin' => (string) $item->country_of_origin,
        ];

        if (! array_key_exists($this->tab, $this->tabs())) {
            $this->tab = 'overview';
        }
    }

    #[Computed]
    public function item(): Item
    {
        return Item::withTrashed()->with(['unit', 'category.parent', 'stocks.warehouse'])->findOrFail($this->itemId);
    }

    /** Spec 10: show or hide the item in the price-free portal catalog. */
    public function togglePortal(): void
    {
        $this->authorize('managePortalCatalog');
        $item = $this->item;
        $item->forceFill(['portal_visible' => ! $item->portal_visible])->save();
        unset($this->item);
        session()->flash('status', __('requisitions.flash.saved'));
    }

    public function saveLocalFields(): void
    {
        $item = $this->item;
        $this->authorize('updateLocalFields', $item);

        $this->min_order_qty = Quantity::normalize($this->min_order_qty);
        $this->order_step = Quantity::normalize($this->order_step);
        $precision = $item->unit->precision;

        $precisionRule = function (string $attribute, mixed $value, \Closure $fail) use ($precision): void {
            if (! Quantity::fitsPrecision((string) $value, $precision)) {
                $fail(__('catalog.items.errors.precision', ['precision' => $precision]));
            }
        };

        $validated = $this->validate([
            'min_order_qty' => ['nullable', 'string', 'max:20', $precisionRule],
            'order_step' => ['nullable', 'string', 'max:20', $precisionRule, function (string $attribute, mixed $value, \Closure $fail): void {
                if (bccomp((string) $value, '0', Quantity::SCALE) <= 0) {
                    $fail(__('catalog.items.errors.step_positive'));
                }
            }],
        ], [], [
            'min_order_qty' => __('catalog.items.fields.min_order_qty'),
            'order_step' => __('catalog.items.fields.order_step'),
        ]);

        $item->update([
            'min_order_qty' => filled($validated['min_order_qty']) ? Quantity::toStorage($validated['min_order_qty']) : null,
            'order_step' => filled($validated['order_step']) ? Quantity::toStorage($validated['order_step']) : null,
        ]);

        unset($this->item);
        session()->flash('status', __('catalog.items.local_saved'));
    }

    /**
     * Accounting codes (finance/admin) and the serial flag (finance/admin until Spec 06's stock
     * manager). Each part is authorised separately; a value 1C sends later overwrites only when
     * non-empty (BR-I.13a/b).
     */
    public function saveAccountingFields(): void
    {
        $item = $this->item;
        $canCodes = auth()->user()->can('updateAccountingCodes', $item);
        $canSerial = auth()->user()->can('updateSerialTracking', $item);
        $canWarranty = auth()->user()->can('updateWarranty', $item);

        if (! $canCodes && ! $canSerial && ! $canWarranty) {
            $this->authorize('updateAccountingCodes', $item);
        }

        $this->accountant_code = trim($this->accountant_code);
        $this->class_code = trim($this->class_code);
        $this->package_code = trim($this->package_code);

        $codeRule = fn (callable $rule) => function (string $attribute, mixed $value, \Closure $fail) use ($rule): void {
            $error = $rule(ItemCodes::clean((string) $value));

            if ($error !== null) {
                $fail(__('catalog.items.errors.code_'.$error));
            }
        };

        $this->validate([
            'accountant_code' => ['nullable', 'string', $codeRule(ItemCodes::accountantCodeError(...))],
            'class_code' => ['nullable', 'string', $codeRule(ItemCodes::classCodeError(...))],
            'package_code' => ['nullable', 'string', $codeRule(ItemCodes::packageCodeError(...))],
            'is_serialized' => ['boolean'],
            'warranty_months' => ['nullable', 'integer', 'min:0', 'max:240'],
        ], [], [
            'warranty_months' => __('catalog.items.fields.warranty_months'),
            'accountant_code' => __('catalog.items.fields.accountant_code'),
            'class_code' => __('catalog.items.fields.class_code'),
            'package_code' => __('catalog.items.fields.package_code'),
        ]);

        $changes = [];

        if ($canCodes) {
            $changes['accountant_code'] = ItemCodes::clean($this->accountant_code);
            $changes['class_code'] = ItemCodes::clean($this->class_code);
            $changes['package_code'] = ItemCodes::clean($this->package_code);
        }

        if ($canSerial) {
            // Spec 06 BR-SN.11: switching on needs opening balances (reconciliation shows the gap);
            // switching off keeps existing serials read-only and stops requiring new ones.
            $changes['is_serialized'] = $this->is_serialized;
        }

        if ($canWarranty) {
            $changes['warranty_months'] = $this->warranty_months === '' ? null : (int) $this->warranty_months;
        }

        $item->update($changes);

        unset($this->item);
        session()->flash('status', __('catalog.items.codes_saved'));
    }

    /**
     * Cargo master data (Spec 08 10.1): HS code (TN VED, never the IKPU class code), weights and
     * volume per base unit, country of origin. CRM-local; 1C values would win if 1C sends them.
     */
    public function saveCargoFields(): void
    {
        $this->authorize('updateCargo', $this->item);
        $this->cargo = array_map(fn ($value): string => str_replace(',', '.', trim((string) $value)), $this->cargo);
        $this->validate([
            'cargo.hs_code' => ['nullable', 'regex:/^\d{6,10}$/'],
            'cargo.gross_weight_kg' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'cargo.net_weight_kg' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'cargo.volume_m3' => ['nullable', 'numeric', 'min:0', 'max:999999'],
            'cargo.country_of_origin' => ['nullable', 'alpha', 'size:2'],
        ], [], [
            'cargo.hs_code' => __('catalog.items.fields.hs_code'),
            'cargo.gross_weight_kg' => __('catalog.items.fields.gross_weight_kg'),
            'cargo.net_weight_kg' => __('catalog.items.fields.net_weight_kg'),
            'cargo.volume_m3' => __('catalog.items.fields.volume_m3'),
            'cargo.country_of_origin' => __('catalog.items.fields.country_of_origin'),
        ]);

        $this->item->update(array_map(fn (string $value): ?string => $value === '' ? null : $value, [
            ...$this->cargo,
            'country_of_origin' => strtoupper($this->cargo['country_of_origin']),
        ]));

        unset($this->item);
        session()->flash('status', __('catalog.items.cargo_saved'));
    }

    /** Re-render the serial counts after the "Add / import serials" dialog saved. */
    #[On('serials-registered')]
    public function serialsRegistered(): void
    {
        unset($this->item);
    }

    /**
     * Visible tabs with cheap counts in the labels (Spec 01 section 17): tabs the user cannot use
     * are hidden. Counts use single aggregate queries; the lists themselves load only when a tab
     * is opened.
     *
     * @return array<string, string>
     */
    public function tabs(): array
    {
        $user = auth()->user();
        $item = $this->item;
        $label = fn (string $key, ?int $count = null): string => __('catalog.items.tabs.'.$key).($count !== null ? ' ('.$count.')' : '');
        $tabs = ['overview' => $label('overview'), 'prices' => $label('prices'), 'codes' => $label('codes')];

        if (PermissionMap::scopeFor($user, 'serials.view') !== null || $user->can('updateSerialTracking', $item) || $user->can('register', ItemSerial::class)) {
            $tabs['serials'] = $label('serials', $item->is_serialized ? ItemSerial::query()->where('item_id', $item->id)->where('status', '!=', SerialStatus::WrittenOff->value)->count() : null);
        }

        $tabs['analogues'] = $label('analogues', ItemAlternative::query()->where('item_id', $item->id)->orWhere(fn ($q) => $q->where('alternative_item_id', $item->id)->where('is_mutual', true))->count());
        $tabs['pictures'] = $label('pictures', ItemImage::query()->where('item_id', $item->id)->count());

        if ($user->can('viewAny', Offer::class) || $user->can('viewAny', Order::class) || $user->can('viewAny', Lead::class)) {
            $tabs['usage'] = $label('usage');
        }

        $tabs['activity'] = __('activity.title');

        return $tabs;
    }

    public function classCodeLengthWarning(): bool
    {
        return ItemCodes::classCodeHasUnusualLength(ItemCodes::clean($this->class_code));
    }

    public function render(): View
    {
        return view('livewire.catalog.items.show', [
            'activities' => $this->tab === 'activity' ? $this->item->activities()->with('causer')->limit(100)->get() : collect(),
        ])->title($this->item->name);
    }
}
