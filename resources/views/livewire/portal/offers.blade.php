<div class="card" data-portal-offers>
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-line text-sm">
            <thead class="bg-sidebar text-left text-xs font-semibold tracking-wide text-sidebar-text uppercase">
                <tr><th scope="col" class="px-4 py-3">{{ __('portal.offers.number') }}</th><th scope="col" class="px-4 py-3">{{ __('common.status') }}</th><th scope="col" class="px-4 py-3">{{ __('portal.offers.valid') }}</th></tr>
            </thead>
            <tbody class="divide-y divide-line bg-surface">
                @forelse ($offers as $offer)
                    <tr wire:key="pof-{{ $offer->id }}">
                        <td class="px-4 py-3"><a href="{{ route('portal.offers.show', $offer) }}" wire:navigate class="link font-mono">{{ $offer->displayNumber() }}</a></td>
                        <td class="px-4 py-3"><x-offers.status-badge :status="$offer->status" /></td>
                        <td class="px-4 py-3 text-ink">{{ $offer->valid_until?->translatedFormat(\App\Enums\Locale::current()->dateFormat()) ?? '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="3" class="p-0"><x-empty-state :title="__('portal.offers.empty')" icon="offers" /></td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if ($offers->hasPages())<div class="border-t border-line p-4">{{ $offers->links() }}</div>@endif
</div>
