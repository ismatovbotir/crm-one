<?php

namespace App\Livewire\Catalog;

use App\Actions\Catalog\ManageItemImages;
use App\Models\Item;
use App\Models\ItemImage;
use App\Services\Catalog\Exceptions\ItemImageException;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * Item page gallery (Spec 01 section 15): main picture and up to 5 more; upload, replace the main
 * picture, make another one main, remove. Changes need `manageImages` (catalog.edit_local); the
 * upload is re-checked and re-encoded by ManageItemImages.
 */
class ItemGallery extends Component
{
    use WithFileUploads;

    #[Locked]
    public int $itemId;

    /** @var TemporaryUploadedFile|null */
    public $upload = null;

    public bool $asMain = false;

    public function mount(int $itemId): void
    {
        $this->itemId = $itemId;
        $this->authorize('view', $this->item);
    }

    #[Computed]
    public function item(): Item
    {
        return Item::withTrashed()->findOrFail($this->itemId);
    }

    /**
     * @return Collection<int, ItemImage>
     */
    #[Computed]
    public function images(): Collection
    {
        return $this->item->images()->get();
    }

    public function save(ManageItemImages $images): void
    {
        $this->authorize('manageImages', $this->item);
        $this->validate(['upload' => ['required', 'file', 'max:'.(ManageItemImages::MAX_BYTES / 1024), 'mimes:jpg,jpeg,png,webp']], [], ['upload' => __('catalog.images.file')]);

        try {
            $images->add($this->item, $this->upload, auth()->user(), $this->asMain);
        } catch (ItemImageException $exception) {
            $this->addError('upload', $exception->getMessage());

            return;
        }

        $this->reset('upload', 'asMain');
        unset($this->images);
        session()->flash('gallery-status', __('catalog.images.saved'));
    }

    public function makeMain(ManageItemImages $images, int $imageId): void
    {
        $this->authorize('manageImages', $this->item);
        $images->makeMain($this->item->images()->findOrFail($imageId), auth()->user());
        unset($this->images);
    }

    public function remove(ManageItemImages $images, int $imageId): void
    {
        $this->authorize('manageImages', $this->item);
        $images->remove($this->item->images()->findOrFail($imageId), auth()->user());
        unset($this->images);
        session()->flash('gallery-status', __('catalog.images.removed'));
    }

    public function render(): View
    {
        return view('livewire.catalog.item-gallery', ['max' => ManageItemImages::MAX_EXTRA]);
    }
}
