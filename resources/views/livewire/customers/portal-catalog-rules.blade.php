<section class="card p-5" aria-labelledby="pcr-title" data-portal-catalog-rules>
    <h3 id="pcr-title" class="font-semibold text-ink">{{ __('requisitions.rules.title') }}</h3>
    <p class="mt-1 text-xs text-muted">{{ __('requisitions.rules.hint') }}</p>
    <ul class="mt-3 divide-y divide-line text-sm">
        @forelse ($rules as $rule)
            <li class="flex items-center justify-between gap-2 py-2" wire:key="pcr-{{ $rule->id }}">
                <span><x-badge :tone="$rule->mode === 'allow' ? 'dark' : 'danger-outline'">{{ __('requisitions.rules.modes.'.$rule->mode) }}</x-badge>
                    <span class="text-ink">{{ $rule->item ? $rule->item->name.' · '.$rule->item->sku : $rule->category?->name }}</span></span>
                @if ($canManage)<button type="button" wire:click="remove({{ $rule->id }})" class="btn btn-secondary btn-sm">{{ __('requisitions.rules.remove') }}</button>@endif
            </li>
        @empty
            <li class="py-2 text-muted">{{ __('requisitions.rules.none') }}</li>
        @endforelse
    </ul>
    @if ($canManage)
        <form wire:submit="add" class="mt-3 grid gap-2 sm:grid-cols-4" novalidate>
            <div><label for="pcr-mode" class="form-label">{{ __('requisitions.rules.mode') }}</label><select wire:model="mode" id="pcr-mode" class="form-input"><option value="deny">{{ __('requisitions.rules.modes.deny') }}</option><option value="allow">{{ __('requisitions.rules.modes.allow') }}</option></select></div>
            <div><label for="pcr-cat" class="form-label">{{ __('requisitions.rules.category') }}</label><select wire:model="categoryId" id="pcr-cat" class="form-input"><option value="">—</option>@foreach ($categories as $category)<option value="{{ $category->id }}">{{ $category->name }}</option>@endforeach</select>@error('categoryId') <p class="form-error">{{ $message }}</p> @enderror</div>
            <div><label for="pcr-item" class="form-label">{{ __('requisitions.rules.item') }}</label><input wire:model.live.debounce.300ms="itemSearch" id="pcr-item" type="search" class="form-input">
                @if ($this->itemOptions->isNotEmpty())<select wire:model="itemId" aria-label="{{ __('requisitions.rules.item') }}" class="form-input mt-1"><option value="">—</option>@foreach ($this->itemOptions as $option)<option value="{{ $option->id }}">{{ $option->name }} · {{ $option->sku }}</option>@endforeach</select>@endif</div>
            <div class="flex items-end"><button type="submit" class="btn btn-secondary btn-sm">{{ __('requisitions.rules.add') }}</button></div>
        </form>
    @endif
</section>
