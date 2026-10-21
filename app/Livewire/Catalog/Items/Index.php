<?php

namespace App\Livewire\Catalog\Items;

use App\Enums\ItemType;
use App\Enums\OneCFeed;
use App\Models\Category;
use App\Models\IntegrationFeed;
use App\Models\Item;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Items list: read-only mirror of the 1C catalog (Spec 02 section 12). Shows the single sale price
 * (rev. 4) and available stock; filters by category (incl. sub-categories), type, status.
 */
#[Layout('layouts.app')]
class Index extends Component
{
    use WithPagination;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $category = '';

    #[Url(except: '')]
    public string $type = '';

    /** active (default) | inactive | all */
    #[Url(except: 'active')]
    public string $status = 'active';

    /** '' = any, 'yes' = serial-tracked only, 'no' = not serial-tracked (Spec 02 BR-I.13b). */
    #[Url(except: '')]
    public string $serialized = '';

    /** Show the optional IKPU class code / package code columns. */
    #[Url(except: false)]
    public bool $showCodes = false;

    public function mount(): void
    {
        $this->authorize('viewAny', Item::class);
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'category', 'type', 'status', 'serialized'], true)) {
            $this->resetPage();
        }
    }

    public function resetFilters(): void
    {
        $this->reset(['search', 'category', 'type', 'status', 'serialized']);
        $this->resetPage();
    }

    /**
     * @return LengthAwarePaginator<int, Item>
     */
    #[Computed]
    public function items(): LengthAwarePaginator
    {
        $search = trim($this->search);
        $type = ItemType::tryFrom($this->type);
        $categoryIds = ctype_digit($this->category) ? Category::withDescendantIds((int) $this->category) : null;

        return Item::query()
            ->with(['category', 'unit', 'stocks', 'mainImage'])
            ->when($search !== '', function ($query) use ($search): void {
                $term = '%'.$search.'%';
                $query->where(fn ($q) => $q->where('sku', 'like', $term)->orWhere('article', 'like', $term)->orWhere('name', 'like', $term)
                    ->orWhere('accountant_code', 'like', $term)->orWhere('class_code', 'like', $term)->orWhere('package_code', 'like', $term));
            })
            ->when($categoryIds !== null, fn ($query) => $query->whereIn('category_id', $categoryIds))
            ->when($type !== null, fn ($query) => $query->where('type', $type))
            ->when($this->status === 'active', fn ($query) => $query->sellable())
            ->when($this->serialized === 'yes', fn ($query) => $query->where('is_serialized', true))
            ->when($this->serialized === 'no', fn ($query) => $query->where('is_serialized', false))
            ->when($this->status === 'inactive', fn ($query) => $query->where(fn ($q) => $q->where('is_active', false)->orWhereNotNull('external_deleted_at')))
            ->orderBy('name')
            ->paginate(config('crm.per_page'));
    }

    /**
     * 1C codes on this page that more than one item shares (informational warning, Spec 02 BR-I.13).
     *
     * @return list<string>
     */
    #[Computed]
    public function duplicateSkus(): array
    {
        return Item::duplicateSkus($this->items->getCollection()->pluck('sku')->all());
    }

    /**
     * Freshness of the 1C feeds (BR-I.21): last received and stale flags.
     *
     * @return array<string, array{feed: OneCFeed, last_received_at: CarbonImmutable|null, last_full_snapshot_at: CarbonImmutable|null, stale_minutes: int, is_stale: bool}>
     */
    #[Computed]
    public function feedStatuses(): array
    {
        return IntegrationFeed::statuses();
    }

    /**
     * "Last received from 1C" for the items feed; before the first API batch, the latest sync
     * stamp on items (dev import seeder).
     */
    #[Computed]
    public function lastReceivedAt(): ?CarbonInterface
    {
        $fromFeed = $this->feedStatuses[OneCFeed::Items->value]['last_received_at'];
        $value = $fromFeed ?? Item::withTrashed()->max('synced_at');

        return $value === null ? null : Carbon::parse($value);
    }

    public function render(): View
    {
        return view('livewire.catalog.items.index', [
            'categories' => Category::query()->orderBy('name')->get(['id', 'name', 'parent_id']),
            'types' => ItemType::options(),
            'baseCurrency' => config('crm.base_currency'),
        ])->title(__('catalog.items.title'));
    }
}
