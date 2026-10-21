<div class="space-y-6">
    <x-flash />
    @php($canPortal = auth()->user()->can('managePortalCatalog'))
    @if ($editingId)
        <form wire:submit="savePortal" class="card grid gap-3 p-5 sm:grid-cols-5" data-form="category-portal" novalidate>
            <h2 class="font-semibold text-ink sm:col-span-5">{{ __('requisitions.categories.edit_title') }}</h2>
            <label class="flex items-center gap-2 text-sm text-ink sm:col-span-5"><input wire:model="portal.visible" type="checkbox" class="rounded border-line"> {{ __('requisitions.categories.visible') }}</label>
            @foreach (['uz', 'ru', 'en'] as $lang)
                <div><label for="cp-{{ $lang }}" class="form-label">{{ __('requisitions.categories.name_in', ['lang' => strtoupper($lang)]) }}</label><input wire:model="portal.{{ $lang }}" id="cp-{{ $lang }}" type="text" class="form-input"></div>
            @endforeach
            <div><label for="cp-sort" class="form-label">{{ __('requisitions.categories.sort') }}</label><input wire:model="portal.sort" id="cp-sort" type="number" class="form-input">@error('portal.sort') <p class="form-error">{{ $message }}</p> @enderror</div>
            <div class="flex justify-end gap-2 sm:col-span-5"><button type="button" wire:click="$set('editingId', null)" class="btn btn-secondary btn-sm">{{ __('common.cancel') }}</button><button type="submit" class="btn btn-primary btn-sm">{{ __('common.save') }}</button></div>
        </form>
    @endif
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <p class="text-sm text-muted">{{ __('catalog.categories.intro') }}</p>
        <x-onec-feed-status :last-received-at="null" />
    </div>

    <div class="card">
        @if ($this->tree->isEmpty())
            <x-empty-state :title="__('catalog.categories.empty')" :description="__('integration.empty.catalog')" icon="catalog" />
        @else
            <ul class="divide-y divide-line" role="tree" aria-label="{{ __('catalog.categories.title') }}">
                @foreach ($this->tree as $row)
                    <li wire:key="category-{{ $row['category']->id }}" role="treeitem" aria-level="{{ $row['depth'] }}"
                        class="flex items-center justify-between gap-3 py-3 pr-6" style="padding-left: {{ 1.5 + ($row['depth'] - 1) * 1.5 }}rem">
                        <span class="flex min-w-0 items-center gap-2">
                            @if ($row['depth'] > 1)
                                <span class="text-line-strong" aria-hidden="true">&#8627;</span>
                            @endif
                            <a href="{{ route('items.index', ['category' => $row['category']->id]) }}" wire:navigate class="truncate font-medium text-ink hover:text-brand-700 hover:underline">{{ $row['category']->name }}</a>
                            @unless ($row['category']->is_active)
                                <x-badge tone="outline">{{ __('common.inactive') }}</x-badge>
                            @endunless
                        </span>
                        <span class="flex shrink-0 items-center gap-2 text-xs text-muted">
                            @unless ($row['category']->portal_visible)<x-badge tone="outline" data-portal-hidden>{{ __('requisitions.categories.hidden') }}</x-badge>@endunless
                            {{ trans_choice('catalog.categories.items_count', $row['category']->items_count, ['count' => $row['category']->items_count]) }}
                            @if ($canPortal)<button type="button" wire:click="editPortal({{ $row['category']->id }})" class="link" data-action="edit-portal">{{ __('requisitions.categories.portal') }}</button>@endif
                        </span>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</div>
