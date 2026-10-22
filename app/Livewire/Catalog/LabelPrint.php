<?php

namespace App\Livewire\Catalog;

use App\Models\Item;
use App\Models\ItemSerial;
use App\Models\LabelTemplate;
use App\Models\Setting;
use App\Services\Stock\SerialNormalizer;
use App\Support\Labels\LabelData;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Price-tag printing: find items by name, SKU, article, barcode or serial number, queue them with a
 * copy count, pick a label template and open the print sheet (exact label size, printed through the
 * Windows printer driver by the browser).
 */
#[Layout('layouts.app')]
class LabelPrint extends Component
{
    public const MAX_COPIES = 500;

    public const MAX_LABELS = 2000;

    public const MAX_RESULTS = 12;

    public string $search = '';

    /** @var list<array{item_id: int, serial_id: int|null, copies: int}> */
    public array $queue = [];

    public ?int $templateId = null;

    public function mount(): void
    {
        $this->authorize('viewAny', Item::class);
        $this->templateId = LabelTemplate::defaultTemplate()?->id;
    }

    /**
     * @return Collection<int, Item>
     */
    #[Computed]
    public function itemResults(): Collection
    {
        $term = trim($this->search);

        if (mb_strlen($term) < 2) {
            return collect();
        }

        $like = '%'.$term.'%';

        return Item::query()
            ->sellable()
            ->with('unit')
            ->where(fn ($query) => $query->where('name', 'like', $like)->orWhere('sku', 'like', $like)->orWhere('article', 'like', $like)->orWhere('barcode', 'like', $like))
            ->orderBy('name')
            ->limit(self::MAX_RESULTS)
            ->get();
    }

    /**
     * @return Collection<int, ItemSerial>
     */
    #[Computed]
    public function serialResults(): Collection
    {
        $term = trim($this->search);

        if (mb_strlen($term) < 3) {
            return collect();
        }

        return ItemSerial::query()
            ->with('item')
            ->where(fn ($query) => $query->where('serial_raw', 'like', '%'.$term.'%')->orWhere('serial_normalized', 'like', '%'.Str::upper($term).'%'))
            ->whereHas('item', fn ($query) => $query->sellable())
            ->orderBy('serial_raw')
            ->limit(self::MAX_RESULTS)
            ->get();
    }

    /**
     * @return Collection<int, LabelTemplate>
     */
    #[Computed]
    public function templates(): Collection
    {
        return LabelTemplate::query()->orderByDesc('is_default')->orderBy('name')->get();
    }

    #[Computed]
    public function template(): ?LabelTemplate
    {
        return $this->templates->firstWhere('id', $this->templateId);
    }

    /**
     * Queue rows with their records, for display and printing.
     *
     * @return list<array{index: int, item: Item, serial: ItemSerial|null, copies: int}>
     */
    #[Computed]
    public function rows(): array
    {
        $items = Item::query()->with(['unit', 'category'])->whereIn('id', array_column($this->queue, 'item_id'))->get()->keyBy('id');
        $serials = ItemSerial::query()->whereIn('id', array_filter(array_column($this->queue, 'serial_id')))->get()->keyBy('id');
        $rows = [];

        foreach ($this->queue as $index => $row) {
            if ($items->has($row['item_id'])) {
                $rows[] = ['index' => $index, 'item' => $items[$row['item_id']], 'serial' => $row['serial_id'] ? $serials->get($row['serial_id']) : null, 'copies' => $row['copies']];
            }
        }

        return $rows;
    }

    #[Computed]
    public function totalLabels(): int
    {
        return array_sum(array_column($this->queue, 'copies'));
    }

    public function add(int $itemId, ?int $serialId = null): void
    {
        $this->authorize('viewAny', Item::class);
        $item = Item::query()->sellable()->find($itemId);

        if ($item === null || ($serialId !== null && ! ItemSerial::query()->whereKey($serialId)->where('item_id', $itemId)->exists())) {
            return;
        }

        foreach ($this->queue as $index => $row) {
            if ($row['item_id'] === $itemId && $row['serial_id'] === $serialId) {
                $this->queue[$index]['copies'] = min(self::MAX_COPIES, $row['copies'] + 1);
                $this->afterQueueChange();

                return;
            }
        }

        $this->queue[] = ['item_id' => $itemId, 'serial_id' => $serialId, 'copies' => 1];
        $this->afterQueueChange();
    }

    /**
     * Enter in the search box (barcode scanners "type" the code and press Enter): an exact barcode,
     * SKU, article or serial match is queued directly, else the first result.
     */
    public function addFirst(): void
    {
        $term = trim($this->search);

        if ($term === '') {
            return;
        }

        $exact = Item::query()->sellable()->where(fn ($query) => $query->where('barcode', $term)->orWhere('sku', $term)->orWhere('article', $term))->first();

        if ($exact !== null) {
            $this->add($exact->id);
        } elseif (($serial = ItemSerial::query()->where('serial_normalized', app(SerialNormalizer::class)->normalize($term)['value'] ?? Str::upper($term))->first()) !== null) {
            $this->add($serial->item_id, $serial->id);
        } elseif (($first = $this->itemResults->first()) !== null) {
            $this->add($first->id);
        } elseif (($serialHit = $this->serialResults->first()) !== null) {
            $this->add($serialHit->item_id, $serialHit->id);
        } else {
            return;
        }

        $this->search = '';
    }

    public function remove(int $index): void
    {
        unset($this->queue[$index]);
        $this->queue = array_values($this->queue);
        $this->afterQueueChange();
    }

    public function clear(): void
    {
        $this->queue = [];
        $this->afterQueueChange();
    }

    public function updatedQueue(): void
    {
        foreach ($this->queue as $index => $row) {
            $this->queue[$index]['copies'] = max(1, min(self::MAX_COPIES, (int) ($row['copies'] ?? 1)));
        }

        $this->afterQueueChange();
    }

    public function print(): void
    {
        $this->authorize('viewAny', Item::class);

        if ($this->queue === [] || $this->template === null) {
            return;
        }

        if ($this->totalLabels > self::MAX_LABELS) {
            $this->addError('queue', __('labels.print.too_many', ['max' => self::MAX_LABELS]));

            return;
        }

        $token = Str::random(24);
        $jobs = array_slice(session('label_jobs', []), -4, null, true);
        $jobs[$token] = ['template_id' => $this->template->id, 'rows' => $this->queue];
        session(['label_jobs' => $jobs]);

        $this->redirectRoute('labels.sheet', ['job' => $token]);
    }

    /**
     * Preview values: the first queued item, else the sample.
     *
     * @return array{fields: array<string, string>, price: array<string, mixed>|null}
     */
    #[Computed]
    public function previewValues(): array
    {
        $first = $this->rows[0] ?? null;

        return $first === null ? LabelData::sample() : LabelData::forItem($first['item'], $first['serial']);
    }

    protected function afterQueueChange(): void
    {
        $this->resetErrorBag('queue');
        unset($this->rows, $this->totalLabels, $this->previewValues);
    }

    public function render(): View
    {
        return view('livewire.catalog.label-print', [
            'logo' => Setting::logoUrl(),
        ])->title(__('labels.print.title'));
    }
}
