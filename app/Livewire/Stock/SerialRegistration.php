<?php

namespace App\Livewire\Stock;

use App\Actions\Stock\RegisterSerials;
use App\Http\Controllers\TenderPrintController;
use App\Models\Item;
use App\Models\ItemSerial;
use App\Models\Warehouse;
use App\Services\Stock\Exceptions\StockActionException;
use App\Services\Stock\Import\SerialFileReader;
use App\Support\BusinessSettings;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Reusable "Add / import serials" dialog (user requirement 2026-10-18): pick a serialized item
 * (or arrive with it preselected and locked from the item page), pick the warehouse, then type
 * serials one by one (Enter adds; each can be removed; running count) or load a CSV/XLSX file
 * with a preview of valid / duplicate / invalid rows and a downloadable error report. Saves only
 * a fully valid batch, in one transaction (RegisterSerials). Every action re-authorizes.
 */
class SerialRegistration extends Component
{
    use WithFileUploads;

    #[Locked]
    public bool $itemLocked = false;

    public ?int $itemId = null;

    public string $itemSearch = '';

    public ?int $warehouseId = null;

    public bool $open = false;

    public string $entry = '';

    /** @var list<string> raw serials in entry order */
    public array $serials = [];

    /** @var list<array{raw: string, serial: string|null, result: string, message: string}> */
    public array $rows = [];

    #[Locked]
    public string $origin = 'manual';

    /** @var array<int, string> reader flags (numeric cell, formula...) of file rows; server-side guard */
    #[Locked]
    public array $flags = [];

    /** @var TemporaryUploadedFile|null */
    public $file = null;

    public function mount(?int $itemId = null, bool $itemLocked = false): void
    {
        $this->itemLocked = $itemLocked && $itemId !== null;
        $this->itemId = $itemId;
        $this->warehouseId = Warehouse::query()->where('is_active', true)->orderBy('id')->value('id');
    }

    #[Computed]
    public function item(): ?Item
    {
        return $this->itemId !== null ? Item::query()->where('is_serialized', true)->find($this->itemId) : null;
    }

    /**
     * @return Collection<int, Item>
     */
    #[Computed]
    public function itemOptions(): Collection
    {
        $term = trim($this->itemSearch);

        return Item::query()->where('is_serialized', true)
            ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w->where('sku', 'like', '%'.$term.'%')->orWhere('name', 'like', '%'.$term.'%')))
            ->orderBy('name')->limit(15)->get(['id', 'sku', 'name']);
    }

    /** The item page context fixes the item; a forged update is refused. */
    public function updatingItemId(): void
    {
        abort_if($this->itemLocked, 403);
    }

    public function openDialog(): void
    {
        $this->authorize('register', ItemSerial::class);
        $this->open = true;
    }

    public function closeDialog(): void
    {
        $this->reset('open', 'entry', 'serials', 'rows', 'file', 'origin', 'flags');

        if (! $this->itemLocked) {
            $this->reset('itemId', 'itemSearch');
        }
    }

    public function selectItem(int $itemId): void
    {
        $this->authorize('register', ItemSerial::class);

        if ($this->itemLocked) {
            return; // The item page context fixes the item.
        }

        $this->itemId = Item::query()->where('is_serialized', true)->findOrFail($itemId)->id;
        $this->itemSearch = '';
        $this->recheck();
    }

    public function addSerial(): void
    {
        $this->authorize('register', ItemSerial::class);
        $value = trim($this->entry);

        if ($value === '' || $this->item === null) {
            return;
        }

        if (count($this->serials) >= BusinessSettings::int('serials.import_max_rows')) {
            $this->addError('entry', __('stock.errors.import_too_many_rows', ['max' => BusinessSettings::int('serials.import_max_rows')]));

            return;
        }

        $this->origin = $this->origin === 'import' ? 'import' : 'manual';
        $this->serials[] = mb_substr($value, 0, 100);
        $this->entry = '';
        $this->recheck();
    }

    public function removeSerial(int $index): void
    {
        $this->authorize('register', ItemSerial::class);
        unset($this->serials[$index]);
        $this->serials = array_values($this->serials);
        $flags = [];

        foreach ($this->flags as $row => $flag) {
            if ($row !== $index) {
                $flags[$row > $index ? $row - 1 : $row] = $flag;
            }
        }

        $this->flags = $flags;
        $this->recheck();
    }

    public function updatedFile(SerialFileReader $reader): void
    {
        $this->authorize('register', ItemSerial::class);
        $this->validate([
            'file' => ['required', 'file', 'max:'.(BusinessSettings::int('serials.import_max_mb') * 1024), 'mimes:csv,txt,xlsx'],
        ], [], ['file' => __('stock.register.file')]);

        if ($this->item === null) {
            $this->addError('file', __('stock.register.choose_item_first'));

            return;
        }

        try {
            $read = $reader->read($this->file->getRealPath(), $this->file->getClientOriginalName());
        } catch (StockActionException $exception) {
            $this->addError('file', $exception->getMessage());

            return;
        }

        $flags = [];
        $this->serials = [];

        foreach ($read['rows'] as $row) {
            $this->serials[] = (string) ($row['serial'] ?? '');
            $flags[count($this->serials) - 1] = $row['flag'];
        }

        $this->origin = 'import';
        $this->file = null;
        $this->flags = array_filter($flags, fn (?string $flag): bool => $flag !== null);
        $this->recheck();
    }

    public function save(RegisterSerials $register): void
    {
        $this->authorize('register', ItemSerial::class);
        $this->validate([
            'itemId' => ['required', 'integer'],
            'warehouseId' => ['required', 'integer', Rule::exists('warehouses', 'id')->where('is_active', true)],
        ], [], ['itemId' => __('stock.fields.item'), 'warehouseId' => __('stock.fields.warehouse')]);

        $item = $this->item ?? abort(404);

        if ($this->flags !== []) {
            $this->recheck();
            session()->flash('error', __('stock.errors.batch_invalid'));

            return;
        }

        try {
            $receipt = $register->register($item, Warehouse::query()->findOrFail($this->warehouseId), $this->serials, auth()->user(), $this->origin);
        } catch (StockActionException $exception) {
            $this->recheck();
            session()->flash('error', $exception->getMessage());

            return;
        }

        session()->flash('status', __('stock.register.saved', ['count' => count($this->serials), 'number' => $receipt->number]));
        $this->closeDialog();
        $this->dispatch('serials-registered');
    }

    /**
     * Error report of the current batch (invalid and duplicate rows), CSV with BOM.
     */
    public function downloadErrors(): StreamedResponse
    {
        $this->authorize('register', ItemSerial::class);
        $rows = array_values(array_filter($this->rows, fn (array $row): bool => $row['result'] !== 'ok'));

        return response()->streamDownload(function () use ($rows): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['row', 'input', 'normalized', 'result', 'message'], ';');

            foreach ($rows as $index => $row) {
                fputcsv($out, [$index + 1, TenderPrintController::csvSafe((string) $row['raw']), TenderPrintController::csvSafe((string) $row['serial']), $row['result'], TenderPrintController::csvSafe((string) $row['message'])], ';');
            }

            fclose($out);
        }, 'serial-errors.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    protected function recheck(): void
    {
        $flags = $this->flags;

        if ($this->item === null || $this->serials === []) {
            $this->rows = [];

            return;
        }

        $rows = app(RegisterSerials::class)->check($this->item, $this->serials);

        foreach ($flags as $index => $flag) {
            if ($flag !== null && isset($rows[$index])) {
                $rows[$index] = [...$rows[$index], 'result' => $flag, 'message' => __('stock.results.'.$flag)];
            }
        }

        $this->rows = $rows;
    }

    public function render(): View
    {
        $counts = collect($this->rows)->groupBy(fn (array $row): string => $row['result'] === 'ok' ? 'valid' : (str_starts_with($row['result'], 'duplicate') || in_array($row['result'], ['already_registered', 'belongs_to_other_item'], true) ? 'duplicate' : 'invalid'))->map->count();

        return view('livewire.stock.serial-registration', [
            'warehouses' => Warehouse::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'counts' => ['valid' => $counts['valid'] ?? 0, 'duplicate' => $counts['duplicate'] ?? 0, 'invalid' => $counts['invalid'] ?? 0],
            'canRegister' => (bool) auth()->user()?->can('register', ItemSerial::class),
            'canSave' => $this->rows !== [] && collect($this->rows)->every(fn (array $row): bool => $row['result'] === 'ok'),
        ]);
    }
}
