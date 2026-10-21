<?php

namespace App\Livewire\Settings;

use App\Models\ReferenceItem;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Settings > Reference lists (Spec 03 section 24.7): industry, currency, contact position and
 * supplier category, names in uz/ru/en; add, rename, reorder, deactivate; delete only unused
 * entries. User-added positions and migrated legacy industries are marked for review. Gate
 * `manageReferenceLists` (admins and catalog managers). Every change is audited.
 */
#[Layout('layouts.app')]
class ReferenceLists extends Component
{
    #[Url(except: 'industry')]
    public string $list = 'industry';

    /** @var array<int, array{uz: string, ru: string, en: string, sort_order: string}> */
    public array $rows = [];

    /** @var array{code: string, uz: string, ru: string, en: string, sort_order: string} */
    public array $new = ['code' => '', 'uz' => '', 'ru' => '', 'en' => '', 'sort_order' => '100'];

    public function mount(): void
    {
        $this->authorize('manageReferenceLists');

        if (! in_array($this->list, ReferenceItem::LISTS, true)) {
            $this->list = 'industry';
        }

        $this->loadRows();
    }

    public function updatedList(): void
    {
        $this->list = in_array($this->list, ReferenceItem::LISTS, true) ? $this->list : 'industry';
        $this->loadRows();
        $this->resetErrorBag();
    }

    /**
     * @return Collection<int, ReferenceItem>
     */
    #[Computed]
    public function items(): Collection
    {
        return ReferenceItem::query()->ofList($this->list)->get();
    }

    protected function loadRows(): void
    {
        unset($this->items);
        $this->rows = [];

        foreach ($this->items as $item) {
            $this->rows[$item->id] = ['uz' => (string) ($item->names['uz'] ?? ''), 'ru' => (string) ($item->names['ru'] ?? ''), 'en' => (string) ($item->names['en'] ?? ''), 'sort_order' => (string) $item->sort_order];
        }
    }

    public function add(): void
    {
        $this->authorize('manageReferenceLists');
        $this->new['code'] = $this->list === 'currency' ? strtoupper(trim($this->new['code'])) : Str::slug(trim($this->new['code'] !== '' ? $this->new['code'] : $this->new['en']), '_');
        $this->validate([
            'new.code' => ['required', 'string', 'max:60', $this->list === 'currency' ? 'regex:/^[A-Z]{3}$/' : 'regex:/^[a-z0-9_]+$/', Rule::unique('reference_items', 'code')->where('list', $this->list)],
            'new.uz' => ['required', 'string', 'max:120'],
            'new.ru' => ['required', 'string', 'max:120'],
            'new.en' => ['required', 'string', 'max:120'],
            'new.sort_order' => ['required', 'integer', 'min:0', 'max:100000'],
        ], [], ['new.code' => __('reference.fields.code'), 'new.uz' => __('reference.fields.name_uz'), 'new.ru' => __('reference.fields.name_ru'), 'new.en' => __('reference.fields.name_en')]);

        ReferenceItem::query()->create([
            'list' => $this->list, 'code' => $this->new['code'], 'names' => ['uz' => trim($this->new['uz']), 'ru' => trim($this->new['ru']), 'en' => trim($this->new['en'])],
            'is_active' => true, 'sort_order' => (int) $this->new['sort_order'], 'source' => 'admin', 'created_by' => auth()->id(),
        ]);

        $this->reset('new');
        $this->loadRows();
        session()->flash('status', __('reference.saved'));
    }

    public function saveRow(int $id): void
    {
        $this->authorize('manageReferenceLists');
        $this->validate([
            'rows.'.$id.'.uz' => ['required', 'string', 'max:120'],
            'rows.'.$id.'.ru' => ['required', 'string', 'max:120'],
            'rows.'.$id.'.en' => ['required', 'string', 'max:120'],
            'rows.'.$id.'.sort_order' => ['required', 'integer', 'min:0', 'max:100000'],
        ], [], ['rows.'.$id.'.uz' => __('reference.fields.name_uz'), 'rows.'.$id.'.ru' => __('reference.fields.name_ru'), 'rows.'.$id.'.en' => __('reference.fields.name_en')]);

        $item = ReferenceItem::query()->where('list', $this->list)->findOrFail($id);
        $row = $this->rows[$id];
        $item->update(['names' => ['uz' => trim($row['uz']), 'ru' => trim($row['ru']), 'en' => trim($row['en'])], 'sort_order' => (int) $row['sort_order'], 'source' => in_array($item->source, ['user', 'legacy'], true) ? 'admin' : $item->source]);
        $this->loadRows();
        session()->flash('status', __('reference.saved'));
    }

    public function toggle(int $id): void
    {
        $this->authorize('manageReferenceLists');
        $item = ReferenceItem::query()->where('list', $this->list)->findOrFail($id);
        $item->update(['is_active' => ! $item->is_active]);
        $this->loadRows();
    }

    public function delete(int $id): void
    {
        $this->authorize('manageReferenceLists');
        $item = ReferenceItem::query()->where('list', $this->list)->findOrFail($id);

        if ($item->isUsed()) {
            $this->addError('row-'.$id, __('reference.used'));

            return;
        }

        $item->logActivity('reference_item_deleted', ['list' => $item->list, 'code' => $item->code]);
        $item->delete();
        $this->loadRows();
        session()->flash('status', __('reference.deleted'));
    }

    public function render(): View
    {
        return view('livewire.settings.reference-lists', ['lists' => ReferenceItem::LISTS])->title(__('reference.title'));
    }
}
