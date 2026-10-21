<div class="space-y-6" data-portal-catalog>
    <x-flash />
    <p class="text-sm text-muted">{{ __('requisitions.catalog.intro') }}</p>

    @if ($draft)
        <div class="card flex flex-wrap items-center justify-between gap-3 p-4 text-sm" role="status" data-draft>
            <span class="text-ink">{{ __('requisitions.catalog.draft', ['number' => $draft->number, 'count' => $draft->lines_count]) }}</span>
            <a href="{{ route('portal.requisitions.show', $draft) }}" wire:navigate class="btn btn-primary btn-sm">{{ __('requisitions.catalog.open_draft') }}</a>
        </div>
    @endif

    <div class="grid gap-6 lg:grid-cols-[16rem_1fr]">
        <nav class="card p-4 text-sm" aria-label="{{ __('requisitions.catalog.categories') }}">
            <h2 class="mb-2 font-semibold text-ink">{{ __('requisitions.catalog.categories') }}</h2>
            <ul class="space-y-1">
                <li><button type="button" wire:click="$set('categoryId', null)" @class(['link' => $categoryId !== null, 'font-semibold text-ink' => $categoryId === null]) aria-current="{{ $categoryId === null ? 'true' : 'false' }}">{{ __('requisitions.catalog.all') }}</button></li>
                @foreach ($this->tree as $row)
                    <li wire:key="cat-{{ $row['category']->id }}" style="padding-left: {{ ($row['depth'] - 1) * 0.75 }}rem">
                        <button type="button" wire:click="$set('categoryId', {{ $row['category']->id }})" @class(['text-left', 'link' => $categoryId !== $row['category']->id, 'font-semibold text-ink' => $categoryId === $row['category']->id]) aria-current="{{ $categoryId === $row['category']->id ? 'true' : 'false' }}">{{ $row['category']->portalName() }}</button>
                    </li>
                @endforeach
            </ul>
        </nav>

        <div class="space-y-4">
            <div class="card p-4">
                <label for="pc-search" class="form-label">{{ __('requisitions.catalog.search') }}</label>
                <input wire:model.live.debounce.300ms="search" id="pc-search" type="search" class="form-input" placeholder="{{ __('requisitions.catalog.search_placeholder') }}">
            </div>

            @if ($currentCategory && $canRequest)
                <form wire:submit="addCategoryNeed" class="card grid gap-3 p-4 sm:grid-cols-4" data-form="category-need" novalidate>
                    <p class="text-sm text-ink sm:col-span-4">{{ __('requisitions.catalog.category_need', ['category' => $currentCategory->portalName()]) }}</p>
                    <div class="sm:col-span-2"><label for="cn-need" class="form-label">{{ __('requisitions.fields.description') }}</label><input wire:model="categoryNeed" id="cn-need" type="text" class="form-input" placeholder="{{ __('requisitions.catalog.need_placeholder') }}">@error('categoryNeed') <p class="form-error" role="alert">{{ $message }}</p> @enderror</div>
                    <div><label for="cn-qty" class="form-label">{{ __('requisitions.fields.quantity') }}</label><input wire:model="categoryQuantity" id="cn-qty" type="text" inputmode="decimal" class="form-input"></div>
                    <div><label for="cn-unit" class="form-label">{{ __('requisitions.fields.unit') }}</label><input wire:model="categoryUnit" id="cn-unit" type="text" class="form-input"></div>
                    <div class="flex justify-end sm:col-span-4"><button type="submit" class="btn btn-secondary btn-sm">{{ __('requisitions.catalog.add_need') }}</button></div>
                </form>
            @endif

            <ul class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                @forelse ($items as $item)
                    @php($avail = $availability[$item->id] ?? ['status' => 'on_request', 'band' => null])
                    <li class="card flex flex-col p-4" wire:key="pi-{{ $item->id }}" data-catalog-item="{{ $item->sku }}">
                        @if (isset($images[$item->id]))
                            <img src="{{ $images[$item->id]->url('thumb') }}" alt="{{ $item->name }}" class="mb-3 h-32 w-full rounded-md object-contain" loading="lazy">
                        @endif
                        <h3 class="font-semibold text-ink">{{ $item->name }}</h3>
                        <p class="font-mono text-xs text-muted">{{ $item->sku }}@if ($item->article) · {{ $item->article }}@endif</p>
                        @if ($item->description)<p class="mt-1 line-clamp-3 text-sm text-muted">{{ \Illuminate\Support\Str::limit(strip_tags($item->description), 200) }}</p>@endif
                        <p class="mt-2 text-sm text-ink">{{ __('requisitions.catalog.unit') }}: {{ $item->unit?->name ?? '—' }}</p>
                        <p class="mt-1 text-sm" data-availability="{{ $avail['status'] }}">
                            <x-badge :tone="$avail['status'] === 'in_stock' ? 'dark' : 'outline'" :icon="$avail['status'] === 'in_stock' ? 'check' : 'clock'">{{ __('requisitions.availability.'.$avail['status']) }}@if ($avail['band']) · {{ $avail['band'] }}@endif</x-badge>
                        </p>
                        @if ($canRequest)
                            <form wire:submit="addItem({{ $item->id }})" class="mt-auto flex items-end gap-2 pt-3">
                                <div class="w-24"><label for="pq-{{ $item->id }}" class="form-label">{{ __('requisitions.fields.quantity') }}</label><input wire:model="quantities.{{ $item->id }}" id="pq-{{ $item->id }}" type="text" inputmode="decimal" class="form-input" placeholder="1"></div>
                                <button type="submit" class="btn btn-primary btn-sm" data-action="add-to-request">{{ __('requisitions.catalog.add') }}</button>
                            </form>
                        @endif
                    </li>
                @empty
                    <li class="sm:col-span-2 xl:col-span-3"><x-empty-state :title="__('requisitions.catalog.empty')" icon="items" /></li>
                @endforelse
            </ul>
            @if ($items->hasPages())<div>{{ $items->links() }}</div>@endif
        </div>
    </div>
</div>
