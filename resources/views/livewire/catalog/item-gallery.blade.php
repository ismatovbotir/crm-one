<section class="card p-6" aria-labelledby="gallery-title" data-item-gallery>
    <div class="flex flex-wrap items-center justify-between gap-2">
        <h2 id="gallery-title" class="font-semibold text-ink">{{ __('catalog.images.title') }}</h2>
        <p class="text-xs text-muted">{{ __('catalog.images.hint', ['max' => $max]) }}</p>
    </div>

    @if (session('gallery-status'))
        <p class="mt-3 rounded-md border border-line-strong bg-neutral-50 px-3 py-2 text-sm text-ink" role="status">{{ session('gallery-status') }}</p>
    @endif

    <ul class="mt-4 flex flex-wrap gap-4" aria-label="{{ __('catalog.images.title') }}">
        @forelse ($this->images as $image)
            <li class="space-y-2" wire:key="img-{{ $image->id }}" data-image="{{ $image->uuid }}">
                <a href="{{ $image->url('full') }}" target="_blank" rel="noopener" class="block">
                    <x-item-thumb :url="$image->url('medium')" :alt="$this->item->name" :size="$image->isMain() ? 160 : 112" />
                </a>
                <p class="text-xs text-muted">@if ($image->isMain()) <x-badge tone="dark" icon="star">{{ __('catalog.images.main') }}</x-badge> @else {{ $image->width }}×{{ $image->height }} @endif</p>
                @can('manageImages', $this->item)
                    <div class="flex gap-1">
                        @unless ($image->isMain())
                            <button type="button" wire:click="makeMain({{ $image->id }})" class="btn btn-secondary btn-sm" data-action="make-main">{{ __('catalog.images.make_main') }}</button>
                        @endunless
                        <button type="button" wire:click="remove({{ $image->id }})" wire:confirm="{{ __('catalog.images.confirm_remove') }}" class="btn btn-danger-outline btn-sm" data-action="remove-image" aria-label="{{ __('catalog.images.remove') }}"><x-icon name="close" class="size-4" /></button>
                    </div>
                @endcan
            </li>
        @empty
            <li class="flex items-center gap-3 text-sm text-muted"><x-item-thumb :alt="$this->item->name" :size="96" /> {{ __('catalog.images.none') }}</li>
        @endforelse
    </ul>

    @can('manageImages', $this->item)
        <form wire:submit="save" class="mt-5 flex flex-wrap items-end gap-3 border-t border-line pt-5" novalidate>
            <div class="min-w-64 flex-1">
                <label for="item-image-upload" class="form-label">{{ __('catalog.images.file') }}</label>
                <input wire:model="upload" id="item-image-upload" type="file" accept="image/jpeg,image/png,image/webp" class="form-input">
                <p class="mt-1 text-xs text-muted">{{ __('catalog.images.rules') }}</p>
                @error('upload') <p class="form-error">{{ $message }}</p> @enderror
            </div>
            @if ($this->images->isNotEmpty())
                <label class="flex items-center gap-2 text-sm text-ink"><input wire:model="asMain" type="checkbox" class="size-4 accent-brand-600"> {{ __('catalog.images.replace_main') }}</label>
            @endif
            <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="upload,save" data-action="upload-image">{{ __('catalog.images.upload') }}</button>
        </form>
    @endcan
</section>
