<?php

namespace App\Livewire\Catalog;

use App\Models\Category;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Read-only category tree (1C item groups mirror any depth; CRM-created ones at most Category::MAX_DEPTH).
 */
#[Layout('layouts.app')]
class Categories extends Component
{
    /** Spec 10 D10-4: the category whose portal fields are being edited. */
    public ?int $editingId = null;

    /** @var array{visible: bool, sort: string, uz: string, ru: string, en: string} */
    public array $portal = ['visible' => true, 'sort' => '0', 'uz' => '', 'ru' => '', 'en' => ''];

    public function editPortal(int $categoryId): void
    {
        $this->authorize('managePortalCatalog');
        $category = Category::query()->findOrFail($categoryId);
        $names = (array) ($category->portal_names ?? []);
        $this->editingId = $category->id;
        $this->portal = ['visible' => (bool) $category->portal_visible, 'sort' => (string) $category->portal_sort, 'uz' => (string) ($names['uz'] ?? ''), 'ru' => (string) ($names['ru'] ?? ''), 'en' => (string) ($names['en'] ?? '')];
    }

    public function savePortal(): void
    {
        $this->authorize('managePortalCatalog');
        $this->validate(['portal.sort' => ['required', 'integer', 'min:-9999', 'max:9999'], 'portal.uz' => ['nullable', 'string', 'max:120'], 'portal.ru' => ['nullable', 'string', 'max:120'], 'portal.en' => ['nullable', 'string', 'max:120']]);
        $category = Category::query()->findOrFail($this->editingId);
        $category->forceFill([
            'portal_visible' => (bool) $this->portal['visible'],
            'portal_sort' => (int) $this->portal['sort'],
            'portal_names' => array_filter(['uz' => trim($this->portal['uz']), 'ru' => trim($this->portal['ru']), 'en' => trim($this->portal['en'])], fn (string $v): bool => $v !== '') ?: null,
        ])->save();
        $this->editingId = null;
        unset($this->tree);
        session()->flash('status', __('requisitions.flash.saved'));
    }

    public function mount(): void
    {
        $this->authorize('viewAny', Category::class);
    }

    /**
     * Flattened tree in display order: [category, depth].
     *
     * @return Collection<int, array{category: Category, depth: int}>
     */
    #[Computed]
    public function tree(): Collection
    {
        $all = Category::query()->withCount('items')->orderBy('sort_order')->orderBy('name')->get();
        $byParent = $all->groupBy(fn (Category $category): string => (string) $category->parent_id);
        $rows = collect();

        $walk = function (string $parentKey, int $depth) use (&$walk, $byParent, $rows): void {
            foreach ($byParent->get($parentKey, collect()) as $category) {
                $rows->push(['category' => $category, 'depth' => $depth]);

                if ($depth < Category::MAX_TRAVERSAL_DEPTH) {
                    $walk((string) $category->id, $depth + 1);
                }
            }
        };

        $walk('', 1);

        return $rows;
    }

    public function render(): View
    {
        return view('livewire.catalog.categories')->title(__('catalog.categories.title'));
    }
}
