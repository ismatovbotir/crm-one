<div class="space-y-6" data-portal-offer>
    @php($offer = $this->offer)
    <a href="{{ route('portal.offers.index') }}" wire:navigate class="link text-sm">&larr; {{ __('portal.offers.title') }}</a>

    <x-flash />

    <header class="card flex flex-col gap-4 p-6 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <p class="font-mono text-sm text-muted">{{ $offer->displayNumber() }}</p>
            <div class="mt-2"><x-offers.status-badge :status="$offer->status" /></div>
            <p class="mt-2 text-sm text-ink">{{ __('portal.offers.valid_until', ['date' => $offer->valid_until?->translatedFormat(\App\Enums\Locale::current()->dateFormat())]) }}</p>
        </div>
        <div class="flex flex-wrap gap-2">
            <a href="{{ route('portal.offers.print', $offer) }}" target="_blank" rel="noopener" class="btn btn-secondary"><x-icon name="printer" class="size-4" /> {{ __('portal.print') }}</a>
            @if ($interactive)
                <button type="button" wire:click="saveChoices" class="btn btn-secondary" data-action="save-choices">{{ __('portal.offers.save_choices') }}</button>
                <button type="button" wire:click="$set('confirming', true)" class="btn btn-primary" data-action="accept">{{ __('portal.offers.accept') }}</button>
            @endif
        </div>
    </header>

    @if ($confirming && $interactive)
        <section class="card border-2 border-brand-600 p-5" role="dialog" aria-labelledby="accept-title">
            <h2 id="accept-title" class="font-semibold text-ink">{{ __('portal.offers.confirm_title') }}</h2>
            <p class="mt-2 text-sm text-ink">{{ __('portal.offers.confirm_text', ['amount' => $offer->money($offer->selected_gross_minor ?? $offer->fixed_gross_minor)?->format()]) }}</p>
            <div class="mt-3 flex justify-end gap-2">
                <button type="button" wire:click="$set('confirming', false)" class="btn btn-secondary">{{ __('common.cancel') }}</button>
                <button type="button" wire:click="accept" class="btn btn-primary" data-action="confirm-accept">{{ __('portal.offers.accept') }}</button>
            </div>
        </section>
    @endif

    <section class="card p-6">
        <x-offers.customer-view :offer="$offer" :blocks="$this->blocks" :selected="$offer->selections->pluck('offer_line_id')->map(fn ($id) => (int) $id)->all()" :totals="$totals" :interactive="$interactive" />
    </section>
</div>
