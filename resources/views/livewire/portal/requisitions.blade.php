<div class="space-y-6" data-portal-requisitions>
    <x-flash />
    <p class="text-sm text-muted">{{ $canPurchase ? __('requisitions.portal.intro_pm') : __('requisitions.portal.intro') }}</p>
    <div class="card flex flex-wrap items-end justify-between gap-3 p-4">
        <div>
            <label for="pr-status" class="form-label">{{ __('common.status') }}</label>
            <select wire:model.live="status" id="pr-status" class="form-input">
                <option value="">{{ __('requisitions.all') }}</option>
                @foreach ($statuses as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
            </select>
        </div>
        <div class="flex flex-wrap gap-2">
            <a href="{{ route('portal.catalog') }}" wire:navigate class="btn btn-secondary btn-sm">{{ __('requisitions.portal.browse') }}</a>
            @if ($canRequest)<button type="button" wire:click="create" class="btn btn-primary btn-sm" data-action="new-request">{{ __('requisitions.portal.new') }}</button>@endif
            @if ($canPurchase)<button type="button" wire:click="merge" wire:confirm="{{ __('requisitions.portal.confirm_merge') }}" class="btn btn-secondary btn-sm" data-action="merge">{{ __('requisitions.portal.merge') }}</button>@endif
        </div>
        @error('selected') <p class="form-error w-full" role="alert">{{ $message }}</p> @enderror
    </div>

    <div class="card overflow-x-auto">
        <table class="min-w-full divide-y divide-line text-sm">
            <thead class="bg-sidebar text-left text-xs font-semibold tracking-wide text-sidebar-text uppercase">
                <tr>
                    @if ($canPurchase)<th scope="col" class="px-4 py-3"><span class="sr-only">{{ __('requisitions.portal.select') }}</span></th>@endif
                    <th scope="col" class="px-4 py-3">{{ __('requisitions.fields.number') }}</th>
                    <th scope="col" class="px-4 py-3">{{ __('requisitions.fields.title') }}</th>
                    <th scope="col" class="px-4 py-3">{{ __('requisitions.fields.requester') }}</th>
                    <th scope="col" class="px-4 py-3">{{ __('common.status') }}</th>
                    <th scope="col" class="px-4 py-3 text-right">{{ __('requisitions.fields.lines') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-line bg-surface">
                @forelse ($requisitions as $requisition)
                    <tr wire:key="prq-{{ $requisition->id }}" data-requisition="{{ $requisition->number }}">
                        @if ($canPurchase)
                            <td class="px-4 py-3">@if (in_array($requisition->status, [\App\Enums\RequisitionStatus::Submitted, \App\Enums\RequisitionStatus::Approved], true))<input wire:model="selected" value="{{ $requisition->id }}" type="checkbox" class="rounded border-line" aria-label="{{ __('requisitions.portal.select') }} {{ $requisition->number }}">@endif</td>
                        @endif
                        <td class="px-4 py-3"><a href="{{ route('portal.requisitions.show', $requisition) }}" wire:navigate class="link font-mono">{{ $requisition->number }}</a></td>
                        <td class="px-4 py-3 text-ink">{{ $requisition->title ?? '—' }}</td>
                        <td class="px-4 py-3 text-ink">{{ $requisition->requester?->name }}</td>
                        <td class="px-4 py-3"><x-requisitions.status-badge :status="$requisition->status" :portal="true" /></td>
                        <td class="px-4 py-3 text-right tabular-nums">{{ $requisition->lines_count }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="p-0"><x-empty-state :title="__('requisitions.portal.empty')" icon="orders" /></td></tr>
                @endforelse
            </tbody>
        </table>
        @if ($requisitions->hasPages())<div class="border-t border-line p-4">{{ $requisitions->links() }}</div>@endif
    </div>
</div>
