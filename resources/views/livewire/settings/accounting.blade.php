<div class="space-y-6">
    <p class="text-sm text-muted">{{ __('accounting.settings.intro') }}</p>

    <x-flash />

    <form wire:submit="save" class="space-y-6" novalidate data-accounting-settings>
        <fieldset class="card p-5" @disabled(! $canEdit)>
            <legend class="sr-only">{{ __('accounting.settings.thresholds') }}</legend>
            <h3 class="font-semibold text-ink">{{ __('accounting.settings.thresholds') }}</h3>
            @foreach (\App\Livewire\Settings\Accounting::MONEY as $key)
                @php($field = str_replace('.', '__', $key))
                <div class="mt-4" wire:key="money-{{ $field }}">
                    <p class="form-label">{{ __('accounting.settings.keys.'.$field) }}</p>
                    <div class="grid gap-3 sm:grid-cols-4">
                        @foreach ($currencies as $currency)
                            <div>
                                <label for="m-{{ $field }}-{{ $currency }}" class="text-xs text-muted">{{ $currency }}</label>
                                <input wire:model="amounts.{{ $field }}.{{ $currency }}" id="m-{{ $field }}-{{ $currency }}" type="text" inputmode="decimal" class="form-input">
                                @error('amounts.'.$field.'.'.$currency) <p class="form-error">{{ $message }}</p> @enderror
                            </div>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </fieldset>

        <fieldset class="card grid gap-4 p-5 sm:grid-cols-3" @disabled(! $canEdit)>
            <legend class="sr-only">{{ __('accounting.settings.days') }}</legend>
            @foreach (\App\Livewire\Settings\Accounting::INTEGERS as $key)
                @php($field = str_replace('.', '__', $key))
                <div wire:key="num-{{ $field }}">
                    <label for="n-{{ $field }}" class="form-label">{{ __('accounting.settings.keys.'.$field) }}</label>
                    <input wire:model="numbers.{{ $field }}" id="n-{{ $field }}" type="number" min="0" max="365" class="form-input">
                    @error('numbers.'.$field) <p class="form-error">{{ $message }}</p> @enderror
                </div>
            @endforeach
        </fieldset>

        <fieldset class="card space-y-2 p-5" @disabled(! $canEdit)>
            <legend class="sr-only">{{ __('accounting.settings.rules') }}</legend>
            <h3 class="font-semibold text-ink">{{ __('accounting.settings.rules') }}</h3>
            @foreach (\App\Livewire\Settings\Accounting::BOOLEANS as $key)
                @php($field = str_replace('.', '__', $key))
                <label class="flex items-center gap-2 text-sm text-ink" wire:key="sw-{{ $field }}"><input type="checkbox" wire:model="switches.{{ $field }}" class="size-4 accent-brand-600"> {{ __('accounting.settings.keys.'.$field) }}</label>
            @endforeach
        </fieldset>

        <fieldset class="card p-5" @disabled(! $canEdit)>
            <legend class="sr-only">{{ __('accounting.settings.codes') }}</legend>
            <h3 class="font-semibold text-ink">{{ __('accounting.settings.codes') }}</h3>
            <p class="mt-1 text-xs text-muted">{{ __('accounting.settings.codes_help') }}</p>
            <div class="mt-3 grid gap-4 sm:grid-cols-3">
                @foreach (['accountant_code', 'class_code', 'package_code'] as $code)
                    <div wire:key="cm-{{ $code }}">
                        <label for="cm-{{ $code }}" class="form-label">{{ __('accounting.codes.'.$code) }}</label>
                        <select wire:model="codeModes.{{ $code }}" id="cm-{{ $code }}" class="form-input">
                            @foreach (['off', 'warn', 'block'] as $mode)
                                @continue($code === 'accountant_code' && $mode === 'block')
                                <option value="{{ $mode }}">{{ __('accounting.codes.mode_'.$mode) }}</option>
                            @endforeach
                        </select>
                        <label class="mt-2 flex items-center gap-2 text-xs text-ink"><input type="checkbox" wire:model="printColumns.{{ $code }}" class="size-4 accent-brand-600"> {{ __('accounting.settings.print_column') }}</label>
                    </div>
                @endforeach
            </div>
        </fieldset>

        @if ($canEdit)
            <div class="flex justify-end"><button type="submit" class="btn btn-primary">{{ __('common.save') }}</button></div>
        @endif
    </form>
</div>
