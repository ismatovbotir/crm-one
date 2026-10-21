<div class="space-y-6">
    <a href="{{ route('customers.show', $customerId) }}" wire:navigate class="link text-sm">&larr; {{ __('customers.back_to_customer') }}</a>

    @php
        $general = $this->canEditGeneral();
        $taxLocked = $this->isTaxIdLocked();
        $match = $this->taxIdMatch;
    @endphp

    @if ($errors->any())
        <div class="rounded-md border border-brand-600 bg-brand-50 px-4 py-3 text-sm text-brand-800" role="alert">
            <p class="flex items-center gap-2 font-semibold"><x-icon name="warning" class="size-4" /> {{ __('customers.fix_errors') }}</p>
        </div>
    @endif

    <form wire:submit="save" class="grid gap-6 lg:grid-cols-[1fr_20rem]" novalidate>
        <div class="space-y-6">
            <x-tabs :tabs="$tabs" :active="$tab" prefix="customer-edit" :label="__('customers.edit_title', ['name' => $this->customer->name])">
                <div class="space-y-6">
                    @include('livewire.customers.partials.form-'.$tab)
                </div>
            </x-tabs>

            <div class="flex justify-end gap-3">
                <a href="{{ route('customers.show', $customerId) }}" wire:navigate class="btn btn-secondary">{{ __('common.cancel') }}</a>
                <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="save">
                    {{ __('common.save') }}
                </button>
            </div>
        </div>

        <aside class="space-y-4" aria-labelledby="duplicates-heading">
            <div class="card">
                <h2 id="duplicates-heading" class="border-b border-line px-4 py-3 text-sm font-semibold text-ink">{{ __('customers.duplicates.title') }}</h2>
                <div class="p-4" aria-live="polite">
                    @if ($this->duplicates->isEmpty())
                        <p class="flex items-center gap-2 text-sm text-muted"><x-icon name="check" class="size-4" /> {{ __('customers.duplicates.none') }}</p>
                    @else
                        <p class="mb-3 flex items-center gap-2 text-sm font-medium text-brand-700"><x-icon name="warning" class="size-4" /> {{ __('customers.duplicates.found') }}</p>
                        <ul class="space-y-3">
                            @foreach ($this->duplicates as $duplicate)
                                <li wire:key="dup-{{ $duplicate['customer']->id }}" class="text-sm">
                                    <a href="{{ route('customers.show', $duplicate['customer']) }}" wire:navigate class="link">{{ $duplicate['customer']->name }}</a>
                                    @if ($duplicate['customer']->trashed())
                                        <x-badge tone="outline">{{ __('common.archived') }}</x-badge>
                                    @endif
                                    <span class="block text-xs text-muted">
                                        {{ collect($duplicate['reasons'])->map(fn ($reason) => __('customers.duplicates.reasons.'.$reason))->join(', ') }}
                                        · {{ $duplicate['customer']->owner?->name }}
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </div>
            @if ($this->customer->isLinkedToOneC())
                <div class="card p-4 text-sm text-muted">
                    {{ __('integration.onec_code') }}: <span class="font-mono text-ink">{{ $this->customer->external_code ?? '—' }}</span>
                </div>
            @endif
        </aside>
    </form>
</div>
