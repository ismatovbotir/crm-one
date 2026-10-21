<div class="card" data-portal-documents="{{ $kind }}">
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-line text-sm">
            <thead class="bg-sidebar text-left text-xs font-semibold tracking-wide text-sidebar-text uppercase">
                <tr><th scope="col" class="px-4 py-3">{{ __('portal.documents.number') }}</th><th scope="col" class="px-4 py-3">{{ __('common.status') }}</th><th scope="col" class="px-4 py-3">{{ __('portal.documents.date') }}</th><th scope="col" class="px-4 py-3 text-right">{{ __('portal.documents.amount') }}</th><th></th></tr>
            </thead>
            <tbody class="divide-y divide-line bg-surface">
                @forelse ($rows as $row)
                    <tr wire:key="pd-{{ $row->id }}">
                        <td class="px-4 py-3 font-mono text-ink">{{ $row->number }}</td>
                        @if ($kind === 'agreements')
                            <td class="px-4 py-3"><x-agreements.status-badge :status="$row->status" /></td>
                            <td class="px-4 py-3 text-ink">{{ $row->signed_on?->translatedFormat(\App\Enums\Locale::current()->dateFormat()) ?? '—' }}</td>
                            <td class="px-4 py-3 text-right text-ink">{{ $row->value_minor !== null ? \App\Support\Money::of($row->value_minor, (string) $row->currency)->format() : '—' }}</td>
                            <td class="px-4 py-3 text-right"><a href="{{ route('portal.agreements.print', $row) }}" target="_blank" rel="noopener" class="link">{{ __('portal.print') }}</a></td>
                        @else
                            <td class="px-4 py-3 text-ink">{{ $row->type->label() }}</td>
                            <td class="px-4 py-3 text-ink">{{ $row->issued_on?->translatedFormat(\App\Enums\Locale::current()->dateFormat()) }}</td>
                            <td class="px-4 py-3 text-right whitespace-nowrap text-ink">{{ $row->money($row->gross_minor)?->format() }}</td>
                            <td class="px-4 py-3 text-right"><a href="{{ route('portal.invoices.print', $row) }}" target="_blank" rel="noopener" class="link">{{ __('portal.print') }}</a></td>
                        @endif
                    </tr>
                @empty
                    <tr><td colspan="5" class="p-0"><x-empty-state :title="__('portal.documents.empty')" icon="agreements" /></td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if ($rows->hasPages())<div class="border-t border-line p-4">{{ $rows->links() }}</div>@endif
</div>
