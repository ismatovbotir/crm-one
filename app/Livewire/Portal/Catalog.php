<?php

namespace App\Livewire\Portal;

use App\Actions\Requisitions\ManageRequisition;
use App\Enums\RequisitionStatus;
use App\Models\Category;
use App\Models\Item;
use App\Models\ItemImage;
use App\Models\Requisition;
use App\Services\Requisitions\Exceptions\RequisitionException;
use App\Services\Requisitions\PortalCatalog;
use App\Support\PortalContext;
use App\Support\PortalScope;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Price-free portal catalog (Spec 10 §2): category tree, search, item cards with picture, short
 * description, unit and availability words, "Add to my request" (draft) and "Request by category".
 * Never loads or renders price, cost or stock quantities (PortalCatalog selects safe columns only).
 */
#[Layout('layouts.portal')]
class Catalog extends Component
{
    use WithPagination;

    #[Url(except: '')]
    public string $search = '';

    #[Url(as: 'category', except: null)]
    public ?int $categoryId = null;

    /** @var array<int, string> item id => quantity typed in the card */
    public array $quantities = [];

    public string $categoryNeed = '';

    public string $categoryQuantity = '1';

    public string $categoryUnit = '';

    public function updating(string $property): void
    {
        if (in_array($property, ['search', 'categoryId'], true)) {
            $this->resetPage();
        }
    }

    /**
     * @return Collection<int, array{category: Category, depth: int}>
     */
    #[Computed]
    public function tree(): Collection
    {
        $visible = app(PortalCatalog::class)->visibleCategoryIds(app(PortalContext::class)->customer());
        $all = Category::query()->whereIn('id', $visible)->orderBy('portal_sort')->orderBy('name')->get(['id', 'parent_id', 'name', 'portal_names', 'portal_sort']);
        $byParent = $all->groupBy(fn (Category $c): string => $c->parent_id !== null && $all->contains('id', $c->parent_id) ? (string) $c->parent_id : '');
        $rows = collect();
        $walk = function (string $key, int $depth) use (&$walk, $byParent, $rows): void {
            foreach ($byParent->get($key, collect()) as $category) {
                $rows->push(['category' => $category, 'depth' => $depth]);

                if ($depth < 6) {
                    $walk((string) $category->id, $depth + 1);
                }
            }
        };
        $walk('', 1);

        return $rows;
    }

    /** The user's open draft request (created on the first "Add"). */
    protected function draft(PortalScope $scope, PortalContext $context, ManageRequisition $manage): Requisition
    {
        return $scope->requisitions()->where('requester_id', $context->user()->id)->where('status', RequisitionStatus::Draft->value)->latest('id')->first()
            ?? $manage->create($context->customer(), $context->user());
    }

    public function addItem(int $itemId, PortalScope $scope, PortalContext $context, ManageRequisition $manage): void
    {
        abort_unless($context->canRequest(), 403);
        $item = app(PortalCatalog::class)->items($context->customer())->find($itemId);
        abort_if($item === null, 404);

        try {
            $manage->addItemLine($this->draft($scope, $context, $manage), Item::query()->findOrFail($item->id), (string) ($this->quantities[$itemId] ?? '1'), null, $context->user());
            session()->flash('status', __('requisitions.flash.added', ['name' => $item->name]));
        } catch (RequisitionException $exception) {
            session()->flash('status', $exception->getMessage());
        }

        unset($this->quantities[$itemId]);
    }

    public function addCategoryNeed(PortalScope $scope, PortalContext $context, ManageRequisition $manage): void
    {
        abort_unless($context->canRequest(), 403);
        $this->validate([
            'categoryId' => ['required', 'integer'],
            'categoryNeed' => ['required', 'string', 'min:3', 'max:1000'],
            'categoryQuantity' => ['required', 'string', 'max:20'],
            'categoryUnit' => ['nullable', 'string', 'max:30'],
        ], [], ['categoryNeed' => __('requisitions.fields.description'), 'categoryQuantity' => __('requisitions.fields.quantity')]);

        try {
            $manage->addCategoryLine($this->draft($scope, $context, $manage), Category::query()->findOrFail($this->categoryId), $this->categoryNeed, $this->categoryQuantity, $this->categoryUnit, null, $context->user());
            $this->reset('categoryNeed', 'categoryUnit');
            $this->categoryQuantity = '1';
            session()->flash('status', __('requisitions.flash.need_added'));
        } catch (RequisitionException $exception) {
            $this->addError('categoryNeed', $exception->getMessage());
        }
    }

    public function render(PortalContext $context, PortalScope $scope): View
    {
        $catalog = app(PortalCatalog::class);
        $customer = $context->customer();
        $term = trim($this->search);
        $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $term).'%';
        $categoryIds = $this->categoryId !== null ? array_values(array_intersect(Category::withDescendantIds($this->categoryId), $catalog->visibleCategoryIds($customer))) : null;

        $items = $catalog->items($customer)->with(['unit:id,code,name'])
            ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('sku', 'like', $like)->orWhere('article', 'like', $like)))
            ->when($categoryIds !== null, fn ($q) => $q->whereIn('category_id', $categoryIds))
            ->orderBy('name')->paginate(config('crm.per_page'));

        $images = ItemImage::query()->whereIn('item_id', $items->pluck('id'))->where('position', 0)->get()->keyBy('item_id');
        $draft = $scope->requisitions()->where('requester_id', $context->user()->id)->where('status', RequisitionStatus::Draft->value)->latest('id')->withCount('lines')->first();

        return view('livewire.portal.catalog', [
            'items' => $items,
            'images' => $images,
            'availability' => $catalog->availability($items->pluck('id')->all()),
            'canRequest' => $context->canRequest(),
            'draft' => $draft,
            'currentCategory' => $this->categoryId !== null && in_array($this->categoryId, $catalog->visibleCategoryIds($customer), true) ? Category::query()->find($this->categoryId) : null,
        ])->title(__('requisitions.catalog.title'));
    }
}
