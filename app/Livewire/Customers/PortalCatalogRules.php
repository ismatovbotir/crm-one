<?php

namespace App\Livewire\Customers;

use App\Models\CatalogVisibilityRule;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Item;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Per-customer portal catalog rules (Spec 10 D10-3) on the customer's Portal tab: deny hides a
 * category or item for this customer; any allow switches the customer to allow-list mode. Managed
 * with `catalog.portal`; everyone who sees the tab can read the rules.
 */
class PortalCatalogRules extends Component
{
    #[Locked]
    public int $customerId;

    public string $mode = CatalogVisibilityRule::DENY;

    public string $categoryId = '';

    public string $itemSearch = '';

    public string $itemId = '';

    public function mount(int $customerId): void
    {
        $this->customerId = $customerId;
        $this->authorize('view', Customer::query()->withTrashed()->findOrFail($customerId));
    }

    /**
     * @return Collection<int, Item>
     */
    #[Computed]
    public function itemOptions(): Collection
    {
        $term = trim($this->itemSearch);

        return mb_strlen($term) < 2 ? collect() : Item::query()->where('is_active', true)
            ->where(fn ($q) => $q->where('name', 'like', '%'.$term.'%')->orWhere('sku', 'like', '%'.$term.'%'))->orderBy('name')->limit(15)->get(['id', 'name', 'sku']);
    }

    public function add(): void
    {
        $this->authorize('managePortalCatalog');
        $this->validate(['mode' => ['required', 'in:allow,deny'], 'categoryId' => ['nullable', 'integer', 'required_without:itemId'], 'itemId' => ['nullable', 'integer']]);
        CatalogVisibilityRule::query()->updateOrCreate(
            ['partner_id' => $this->customerId, 'category_id' => $this->itemId === '' ? (int) $this->categoryId : null, 'item_id' => $this->itemId !== '' ? (int) $this->itemId : null],
            ['mode' => $this->mode, 'created_by' => auth()->id()],
        );
        Customer::query()->withTrashed()->find($this->customerId)?->logActivity('portal_catalog_rule_saved', ['mode' => $this->mode, 'category' => $this->categoryId, 'item' => $this->itemId]);
        $this->reset('categoryId', 'itemId', 'itemSearch');
    }

    public function remove(int $ruleId): void
    {
        $this->authorize('managePortalCatalog');
        CatalogVisibilityRule::query()->where('partner_id', $this->customerId)->whereKey($ruleId)->delete();
    }

    public function render(): View
    {
        return view('livewire.customers.portal-catalog-rules', [
            'rules' => CatalogVisibilityRule::query()->where('partner_id', $this->customerId)->with(['category:id,name', 'item:id,name,sku'])->orderBy('mode')->get(),
            'categories' => Category::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'canManage' => auth()->user()->can('managePortalCatalog'),
        ]);
    }
}
