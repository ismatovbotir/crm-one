<?php

namespace App\Livewire\Stock;

use App\Actions\Stock\AddReceiptSerials;
use App\Actions\Stock\ClaimSerials;
use App\Actions\Stock\ManageSerials;
use App\Enums\StockDocumentStatus;
use App\Models\DeliveryLine;
use App\Models\DocumentLineSerial;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
use App\Models\SerialImportBatch;
use App\Services\Stock\Exceptions\StockActionException;
use App\Services\Stock\Import\SerialFileReader;
use App\Services\Stock\Import\SerialImporter;
use App\Services\Stock\SerialNormalizer;
use App\Support\Quantity;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * Serial registration for one document line (Spec 06 21.7): scan/type one by one (Enter adds),
 * bulk paste with per-row results, or CSV/XLSX import with preview and commit; grid with remove
 * (drafts only) and a counter "entered / expected". Receipts accept new serials, delivery notes
 * only available serials of the item in the note's warehouse (atomic claim).
 */
class SerialEntry extends Component
{
    use WithFileUploads;

    #[Locked]
    public string $lineType;

    #[Locked]
    public int $lineId;

    public string $serial = '';

    public string $paste = '';

    /** @var TemporaryUploadedFile|null */
    public $file = null;

    #[Locked]
    public ?int $batchId = null;

    /** @var list<array{raw: string, serial: string|null, result: string, message: string}> */
    public array $results = [];

    public bool $errorsOnly = false;

    /** @var array<int, string> damage notes of receipt serial rows, by row id */
    public array $damageNotes = [];

    public function mount(string $lineType, int $lineId): void
    {
        abort_unless(in_array($lineType, [GoodsReceiptLine::LINE_TYPE, DeliveryLine::LINE_TYPE], true), 404);
        $this->lineType = $lineType;
        $this->lineId = $lineId;
        $this->authorize('view', $this->document());
    }

    public function line(): GoodsReceiptLine|DeliveryLine
    {
        return $this->lineType === GoodsReceiptLine::LINE_TYPE
            ? GoodsReceiptLine::query()->with(['item', 'receipt'])->findOrFail($this->lineId)
            : DeliveryLine::query()->with(['item', 'delivery', 'orderLine'])->findOrFail($this->lineId);
    }

    protected function document(): mixed
    {
        $line = $this->line();

        return $line instanceof GoodsReceiptLine ? $line->receipt : $line->delivery;
    }

    public function canEdit(): bool
    {
        $document = $this->document();

        $open = $document instanceof GoodsReceipt ? $document->status->isEditable() : $document->status === StockDocumentStatus::Draft;

        return $open
            && auth()->user()->can($this->lineType === GoodsReceiptLine::LINE_TYPE ? 'update' : 'registerSerials', $document);
    }

    /**
     * @return Collection<int, DocumentLineSerial>
     */
    #[Computed]
    public function rows(): Collection
    {
        return $this->line()->serialRows()->get();
    }

    #[Computed]
    public function batch(): ?SerialImportBatch
    {
        return $this->batchId !== null ? SerialImportBatch::query()->find($this->batchId) : null;
    }

    public function addOne(): void
    {
        $this->authorizeEdit();
        $this->run([$this->serial]);

        if (($this->results[0]['result'] ?? null) === 'ok') {
            $this->serial = '';
        }
    }

    public function addPaste(): void
    {
        $this->authorizeEdit();
        [$inputs, $damaged] = $this->lineType === GoodsReceiptLine::LINE_TYPE ? $this->pasteWithDamage($this->paste) : [app(SerialNormalizer::class)->split($this->paste), []];
        $this->run($inputs, $damaged);

        if (! collect($this->results)->contains(fn (array $row): bool => ! in_array($row['result'], ['ok', 'already_in_document'], true))) {
            $this->paste = '';
        }
    }

    public function previewFile(SerialImporter $importer): void
    {
        $this->authorizeEdit();
        $this->validate(['file' => ['required', 'file', 'max:'.(5 * 1024)]], [], ['file' => __('stock.entry.file')]);

        try {
            $this->batchId = $importer->preview($this->line(), $this->file, auth()->user())->id;
        } catch (StockActionException $exception) {
            $this->addError('file', $exception->getMessage());

            return;
        }

        $this->file = null;
        unset($this->batch);
    }

    public function commitImport(SerialImporter $importer, string $mode): void
    {
        $this->authorizeEdit();
        $batch = $this->batch;

        if ($batch === null) {
            return;
        }

        try {
            $this->results = $importer->commit($batch, $this->line(), $mode === 'valid_only' ? 'valid_only' : 'all_or_nothing', auth()->user());
        } catch (StockActionException $exception) {
            session()->flash('error', $exception->getMessage());

            return;
        }

        $this->batchId = null;
        $this->refreshAll();
        session()->flash('status', __('stock.flash.imported'));
    }

    public function discardImport(): void
    {
        $this->batchId = null;
        unset($this->batch);
    }

    public function removeRow(ManageSerials $serials, int $rowId): void
    {
        $this->authorizeEdit();
        $row = DocumentLineSerial::query()->where('line_type', $this->lineType)->where('line_id', $this->lineId)->findOrFail($rowId);

        try {
            $serials->removeRow($row, auth()->user());
        } catch (StockActionException $exception) {
            session()->flash('error', $exception->getMessage());
        }

        $this->refreshAll();
    }

    /** Spec 06 26.4: flag or clear damage on one serial of a receipt line. */
    public function toggleDamaged(ManageSerials $serials, int $rowId): void
    {
        $this->authorizeEdit();
        $row = DocumentLineSerial::query()->where('line_type', GoodsReceiptLine::LINE_TYPE)->where('line_id', $this->lineId)->findOrFail($rowId);

        try {
            $serials->markDamaged($row, ! $row->is_damaged, $this->damageNotes[$rowId] ?? $row->damage_note, auth()->user());
        } catch (StockActionException $exception) {
            session()->flash('error', $exception->getMessage());
        }

        $this->refreshAll();
    }

    public function saveDamageNote(ManageSerials $serials, int $rowId): void
    {
        $this->authorizeEdit();
        $row = DocumentLineSerial::query()->where('line_type', GoodsReceiptLine::LINE_TYPE)->where('line_id', $this->lineId)->where('is_damaged', true)->findOrFail($rowId);

        try {
            $serials->markDamaged($row, true, $this->damageNotes[$rowId] ?? null, auth()->user());
        } catch (StockActionException $exception) {
            session()->flash('error', $exception->getMessage());
        }

        $this->refreshAll();
    }

    /**
     * Pasted lines "serial" or "serial<TAB|;|,>damaged[<TAB|;|,>note]" (the damaged word in any
     * language, or yes/да/ha); anything else is split into serials as before.
     *
     * @return array{0: list<string>, 1: array<string, string|null>}
     */
    protected function pasteWithDamage(string $text): array
    {
        $normalizer = app(SerialNormalizer::class);
        $inputs = [];
        $damaged = [];

        foreach (preg_split('/\r\n|\r|\n/u', $text) ?: [] as $lineText) {
            $parts = array_map('trim', preg_split('/[\t;,]/u', $lineText) ?: []);

            if (count($parts) >= 2 && $parts[0] !== '' && $parts[1] !== '' && SerialFileReader::isDamagedMark($parts[1]) && mb_strlen($parts[1]) > 1) {
                $inputs[] = $parts[0];
                $serial = $normalizer->normalize($parts[0])['value'] ?? null;

                if ($serial !== null) {
                    $note = trim(implode(', ', array_slice($parts, 2)));
                    $damaged[(string) $serial] = $note !== '' ? $note : null;
                }

                continue;
            }

            array_push($inputs, ...$normalizer->split($lineText));
        }

        return [$inputs, $damaged];
    }

    /**
     * @param  list<string>  $inputs
     * @param  array<string, string|null>  $damaged
     */
    protected function run(array $inputs, array $damaged = []): void
    {
        if ($inputs === [] || collect($inputs)->every(fn (string $input): bool => trim($input) === '')) {
            return;
        }

        try {
            $line = $this->line();
            $this->results = $line instanceof GoodsReceiptLine
                ? app(AddReceiptSerials::class)->handle($line, $inputs, auth()->user(), null, false, $damaged)
                : app(ClaimSerials::class)->handle($line, $inputs, auth()->user());
        } catch (StockActionException $exception) {
            $this->results = [['raw' => implode(', ', array_slice($inputs, 0, 3)), 'serial' => null, 'result' => 'error', 'message' => $exception->getMessage()]];
        }

        $this->refreshAll();
    }

    protected function authorizeEdit(): void
    {
        abort_unless($this->canEdit(), 403);
    }

    protected function refreshAll(): void
    {
        unset($this->rows, $this->batch);
        $this->damageNotes = [];
        $this->dispatch('serials-updated');
    }

    public function render(): View
    {
        $line = $this->line();
        // Receipts count against the expected quantity (1C) when there is one (Spec 06 26.4).
        $expected = $line instanceof GoodsReceiptLine ? (string) ($line->expected_qty ?? $line->serialRows()->count()) : (string) $line->orderLine->openQuantity();

        return view('livewire.stock.serial-entry', [
            'line' => $line,
            'expected' => Quantity::trimmed($expected),
            'editable' => $this->canEdit(),
        ]);
    }
}
