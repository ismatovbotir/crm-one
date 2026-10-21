<section class="card space-y-3 p-5" data-poa data-poa-required="{{ $this->order->poa_required ? '1' : '0' }}">
    @php
        $order = $this->order;
        $user = auth()->user();
        $dateTimeFormat = \App\Enums\Locale::current()->dateTimeFormat();
        $needsReason = $user->can('untickPoa', $order) || $user->can('changePoaRequirement', $order) || $user->can('overridePoa', $order);
    @endphp
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h3 class="font-semibold text-ink">{{ __('orders.poa.title') }}</h3>
            @if (! $order->poa_required)
                <p class="text-sm text-muted">{{ __('orders.poa.not_required') }}</p>
            @elseif ($order->poa_received_at)
                <p class="flex items-center gap-1 text-sm text-ink" data-poa-received><x-icon name="check" class="size-4" /> {{ __('orders.poa.received', ['user' => $order->poaReceiver?->name ?? '—', 'date' => $order->poa_received_at->translatedFormat($dateTimeFormat)]) }}</p>
                @if ($order->poa_note) <p class="text-xs text-muted">{{ $order->poa_note }}</p> @endif
            @else
                <p class="text-sm text-ink" data-poa-missing>{{ __('orders.poa.missing') }}</p>
            @endif
            @if ($order->poa_waived_at)
                <p class="text-xs text-muted" data-poa-overridden>{{ __('orders.poa.overridden', ['user' => $order->poaWaiver?->name ?? '—', 'reason' => $order->poa_waiver_reason]) }}</p>
            @endif
        </div>
        @can('tickPoa', $order)
            <div class="flex flex-col gap-2 sm:flex-row sm:items-end">
                <div>
                    <label for="poa-note" class="text-xs text-muted">{{ __('orders.poa.note') }}</label>
                    <input wire:model="note" id="poa-note" type="text" maxlength="255" class="form-input" placeholder="{{ __('orders.poa.note_placeholder') }}">
                </div>
                <label class="flex items-center gap-2 text-sm font-semibold text-ink">
                    <input type="checkbox" wire:click="tick" class="size-4 accent-brand-600" data-action="poa-tick">
                    {{ __('orders.poa.checkbox') }}
                </label>
            </div>
        @endcan
    </div>

    @if ($needsReason)
        <div class="flex flex-col gap-2 border-t border-line pt-3 sm:flex-row sm:items-end">
            <div class="grow">
                <label for="poa-reason" class="text-xs text-muted">{{ __('orders.poa.reason') }}</label>
                <input wire:model="reason" id="poa-reason" type="text" maxlength="500" class="form-input">
                @error('reason') <p class="form-error">{{ $message }}</p> @enderror
            </div>
            @can('untickPoa', $order)
                <button type="button" wire:click="untick" class="btn btn-secondary btn-sm" data-action="poa-untick">{{ __('orders.poa.untick') }}</button>
            @endcan
            @can('overridePoa', $order)
                <button type="button" wire:click="override" class="btn btn-secondary btn-sm" data-action="poa-override">{{ __('orders.poa.override') }}</button>
            @endcan
            @can('changePoaRequirement', $order)
                <button type="button" wire:click="toggleRequired" class="btn btn-secondary btn-sm" data-action="poa-requirement">{{ $order->poa_required ? __('orders.poa.make_optional') : __('orders.poa.make_required') }}</button>
            @endcan
        </div>
    @endif
</section>
