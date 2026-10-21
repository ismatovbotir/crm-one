<div class="space-y-6">
    <p class="text-sm text-muted">{{ __('setup.intro') }}</p>

    <x-flash />

    <div @class(['card flex items-center gap-3 p-4', 'border-ink' => $ready, 'border-brand-600' => ! $ready]) role="status" data-setup-ready="{{ $ready ? 'yes' : 'no' }}">
        <x-icon :name="$ready ? 'check' : 'warning'" class="size-5 {{ $ready ? 'text-ink' : 'text-brand-700' }}" />
        <p class="font-semibold text-ink">{{ $ready ? __('setup.ready') : __('setup.not_ready') }}</p>
    </div>

    <ol class="card divide-y divide-line" data-setup-steps>
        @foreach ($steps as $step)
            <li class="flex flex-wrap items-start justify-between gap-3 p-4" wire:key="step-{{ $step['key'] }}" data-step="{{ $step['key'] }}" data-done="{{ $step['done'] ? 'yes' : 'no' }}">
                <div class="flex min-w-0 gap-3">
                    <span class="mt-0.5 inline-flex size-6 shrink-0 items-center justify-center rounded-full {{ $step['done'] ? 'bg-ink text-white' : 'border-2 border-brand-600 text-brand-700' }}">
                        <x-icon :name="$step['done'] ? 'check' : ($step['required'] ? 'warning' : 'clock')" class="size-3.5" />
                    </span>
                    <div class="min-w-0">
                        <p class="font-medium text-ink">{{ __('setup.steps.'.$step['key'].'.title') }}
                            <span class="text-xs font-normal text-muted">· {{ $step['done'] ? __('setup.done') : ($step['required'] ? __('setup.required') : __('setup.optional')) }}</span>
                        </p>
                        <p class="text-sm text-muted">{{ __('setup.steps.'.$step['key'].'.hint') }}</p>
                        @if ($step['detail']) <p class="mt-1 font-mono text-xs text-ink">{{ $step['detail'] }}</p> @endif
                    </div>
                </div>
                <div class="flex shrink-0 gap-2">
                    @if ($step['key'] === 'two_factor')
                        <button type="button" wire:click="toggleTwoFactor" wire:confirm="{{ $enforced ? __('setup.confirm_two_factor_off') : __('setup.confirm_two_factor_on') }}" class="btn btn-secondary btn-sm" data-action="toggle-2fa">{{ $enforced ? __('setup.two_factor_disable') : __('setup.two_factor_enable') }}</button>
                    @endif
                    @if ($step['route'] && \Illuminate\Support\Facades\Route::has($step['route']))
                        <a href="{{ route($step['route']) }}" wire:navigate class="btn btn-secondary btn-sm">{{ __('setup.open') }}</a>
                    @endif
                </div>
            </li>
        @endforeach
    </ol>
</div>
