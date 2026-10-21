<div class="card space-y-4 p-6" data-portal-warranty>
    <p class="text-sm text-muted">{{ __('portal.warranty.intro') }}</p>
    <form wire:submit="lookup" class="flex flex-wrap items-end gap-2">
        <div class="min-w-64 flex-1"><label for="pw-serial" class="form-label">{{ __('portal.warranty.serial') }}</label><input wire:model="serial" id="pw-serial" type="text" class="form-input font-mono">@error('serial') <p class="form-error">{{ $message }}</p> @enderror</div>
        <button type="submit" class="btn btn-primary">{{ __('portal.warranty.check') }}</button>
    </form>
    @if ($searched)
        @if ($result)
            <dl class="grid gap-3 text-sm sm:grid-cols-3" data-warranty-result>
                <div><dt class="text-xs text-muted uppercase">{{ __('portal.warranty.item') }}</dt><dd class="text-ink">{{ $result->item?->name }}</dd></div>
                <div><dt class="text-xs text-muted uppercase">{{ __('portal.warranty.sold') }}</dt><dd class="text-ink">{{ $result->sold_at?->translatedFormat(\App\Enums\Locale::current()->dateFormat()) ?? '—' }}</dd></div>
                <div><dt class="text-xs text-muted uppercase">{{ __('portal.warranty.until') }}</dt><dd class="font-semibold text-ink">{{ $result->warranty_until?->translatedFormat(\App\Enums\Locale::current()->dateFormat()) ?? __('portal.warranty.none') }}@if ($result->warranty_until) · {{ $result->warranty_until->isPast() ? __('portal.warranty.expired') : __('portal.warranty.active') }}@endif</dd></div>
            </dl>
        @else
            <p class="text-sm text-ink" data-warranty-not-found>{{ __('portal.warranty.not_found') }}</p>
        @endif
    @endif
</div>
