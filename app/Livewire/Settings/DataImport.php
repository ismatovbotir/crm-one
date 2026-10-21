<?php

namespace App\Livewire\Settings;

use App\Models\Setting;
use App\Services\Import\CustomerImporter;
use App\Services\Import\Exceptions\ImportException;
use App\Services\Import\ItemImporter;
use App\Services\Import\TabularFileReader;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * Go-live data import (roadmap step 7, admin): customers or items from CSV/XLSX; the file is
 * checked first and a preview shows valid, duplicate and invalid rows with reasons; only valid rows
 * are written, in one transaction. The preview is kept server-side for 30 minutes.
 */
#[Layout('layouts.app')]
class DataImport extends Component
{
    use WithFileUploads;

    public string $kind = 'customers';

    /** @var TemporaryUploadedFile|null */
    public $file = null;

    #[Locked]
    public ?string $previewKey = null;

    public function mount(): void
    {
        $this->authorize('manage', Setting::class);
    }

    public function preview(TabularFileReader $reader): void
    {
        $this->authorize('manage', Setting::class);
        $this->validate([
            'kind' => ['required', 'in:customers,items'],
            'file' => ['required', 'file', 'max:'.(TabularFileReader::MAX_BYTES / 1024), 'mimes:csv,txt,xlsx'],
        ], [], ['file' => __('import.file')]);

        try {
            $rows = $reader->read((string) $this->file->getRealPath(), $this->file->getClientOriginalName());
        } catch (ImportException $exception) {
            $this->addError('file', $exception->getMessage());

            return;
        }

        $results = $this->importer()->preview($rows);
        $this->previewKey = 'import:'.auth()->id().':'.Str::uuid();
        Cache::put($this->previewKey, ['kind' => $this->kind, 'results' => $results], now()->addMinutes(30));
        $this->reset('file');
    }

    public function commit(): void
    {
        $this->authorize('manage', Setting::class);
        $preview = $this->previewKey !== null ? Cache::get($this->previewKey) : null;

        if (! is_array($preview) || ! str_starts_with((string) $this->previewKey, 'import:'.auth()->id().':')) {
            $this->addError('file', __('import.errors.expired'));

            return;
        }

        $this->kind = $preview['kind'];
        $outcome = $this->importer()->commit($preview['results'], auth()->user());
        Cache::forget($this->previewKey);
        $this->previewKey = null;
        session()->flash('status', is_array($outcome)
            ? __('import.done_items', $outcome)
            : __('import.done_customers', ['count' => $outcome]));
    }

    public function discard(): void
    {
        if ($this->previewKey !== null) {
            Cache::forget($this->previewKey);
        }

        $this->previewKey = null;
    }

    protected function importer(): CustomerImporter|ItemImporter
    {
        return $this->kind === 'items' ? app(ItemImporter::class) : app(CustomerImporter::class);
    }

    public function render(): View
    {
        $preview = $this->previewKey !== null ? Cache::get($this->previewKey) : null;
        $results = is_array($preview) ? $preview['results'] : [];

        return view('livewire.settings.data-import', [
            'results' => array_slice($results, 0, 300),
            'counts' => ['ok' => count(array_filter($results, fn ($r) => $r['status'] === 'ok')), 'duplicate' => count(array_filter($results, fn ($r) => $r['status'] === 'duplicate')), 'error' => count(array_filter($results, fn ($r) => $r['status'] === 'error'))],
            'total' => count($results),
            'columns' => $this->kind === 'items' ? ItemImporter::COLUMNS : CustomerImporter::COLUMNS,
        ])->title(__('import.title'));
    }
}
