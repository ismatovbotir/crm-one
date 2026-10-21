<div class="space-y-6">
    <p class="text-sm text-muted">{{ __('settings.appearance.intro') }}</p>

    <x-flash />

    <div class="card p-4">
        <p class="form-label">{{ __('settings.appearance.logo') }}</p>
        <p class="mb-3 text-xs text-muted">{{ __('settings.appearance.logo_help') }}</p>
        <div class="flex flex-wrap items-center gap-6">
            <div class="text-center">
                <img src="{{ $logoUrl }}" alt="RSG" width="80" height="80" class="size-20 rounded-lg bg-surface object-contain shadow-sm ring-1 ring-ink/10">
                <p class="mt-1 text-xs text-muted">{{ $hasCustomLogo ? __('settings.appearance.logo_current_custom') : __('settings.appearance.logo_current_default') }}</p>
            </div>

            @if ($logo && ! $errors->has('logo') && $logo->isPreviewable())
                <div class="text-center">
                    <img src="{{ $logo->temporaryUrl() }}" alt="{{ __('settings.appearance.logo_preview_alt') }}" width="80" height="80" class="size-20 rounded-lg bg-surface object-contain shadow-sm ring-1 ring-brand-600">
                    <p class="mt-1 text-xs text-muted">{{ __('settings.appearance.logo_new') }}</p>
                </div>
            @endif

            <form wire:submit="uploadLogo" class="flex flex-1 flex-col gap-2" novalidate>
                <label for="logo" class="sr-only">{{ __('settings.appearance.logo_file') }}</label>
                <input wire:model="logo" id="logo" type="file" accept=".png,.jpg,.jpeg,.webp,image/png,image/jpeg,image/webp" class="form-input">
                <div wire:loading wire:target="logo" class="text-xs text-muted">{{ __('common.uploading') }}</div>
                @error('logo') <p class="form-error">{{ $message }}</p> @enderror
                <div class="flex flex-wrap gap-2">
                    <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="logo,uploadLogo" @disabled(! $logo)>{{ __('settings.appearance.upload_logo') }}</button>
                    @if ($hasCustomLogo)
                        <button type="button" wire:click="resetLogo" wire:confirm="{{ __('settings.appearance.reset_logo_confirm') }}" class="btn btn-danger-outline">{{ __('settings.appearance.reset_logo') }}</button>
                    @endif
                </div>
            </form>
        </div>
    </div>

    <div class="card p-4">
        <p class="form-label">{{ __('settings.appearance.presets') }}</p>
        <p class="mb-3 text-xs text-muted">{{ __('settings.appearance.presets_help') }}</p>
        <div class="flex flex-wrap gap-2">
            @foreach ($presets as $preset)
                <button type="button" wire:key="preset-{{ $preset->value }}" wire:click="applyPreset('{{ $preset->value }}')" class="btn btn-secondary">
                    <span class="flex -space-x-1" aria-hidden="true">
                        @foreach (['primary', 'sidebar', 'surface', 'text'] as $swatch)
                            <span class="size-4 rounded-full border border-line-strong" style="background-color: {{ $preset->colors()[$swatch] }}"></span>
                        @endforeach
                    </span>
                    {{ $preset->label() }}
                </button>
            @endforeach
        </div>
    </div>

    <div class="grid gap-6 xl:grid-cols-2">
        <form wire:submit="save" class="card" novalidate>
            <div class="space-y-5 p-6">
                @foreach ($themeColors as $color)
                    <div wire:key="color-{{ $color->value }}">
                        <label for="hex-{{ $color->value }}" class="form-label">{{ $color->label() }}</label>
                        <div class="flex items-center gap-3">
                            <input
                                type="color"
                                wire:model.live="colors.{{ $color->value }}"
                                class="h-10 w-14 shrink-0 cursor-pointer rounded-md border border-line-strong bg-surface p-1"
                                aria-label="{{ __('settings.appearance.color_picker', ['color' => $color->label()]) }}"
                            >
                            <input
                                id="hex-{{ $color->value }}"
                                type="text"
                                wire:model.live.debounce.400ms="colors.{{ $color->value }}"
                                maxlength="7"
                                spellcheck="false"
                                autocomplete="off"
                                class="form-input font-mono uppercase"
                                placeholder="{{ $color->default() }}"
                            >
                        </div>
                        <p class="mt-1 text-xs text-muted">{{ $color->description() }} {{ __('settings.appearance.default_hex', ['hex' => strtoupper($color->default())]) }}</p>
                        @error('colors.'.$color->value) <p class="form-error">{{ $message }}</p> @enderror
                    </div>
                @endforeach
            </div>

            @error('contrast')
                <div class="mx-6 mb-4 rounded-md border border-brand-600 bg-brand-50 px-4 py-3 text-sm text-brand-700" role="alert">
                    <p class="font-semibold">{{ __('settings.appearance.not_saved_contrast') }}</p>
                    <ul class="mt-1 list-disc pl-5">
                        @foreach ($errors->get('contrast') as $message)
                            <li>{{ $message }}</li>
                        @endforeach
                    </ul>
                </div>
            @enderror

            <div class="flex flex-wrap justify-between gap-3 border-t border-line bg-neutral-50 px-6 py-4">
                <button
                    type="button"
                    wire:click="resetToDefaults"
                    wire:confirm="{{ __('settings.appearance.reset_colors_confirm') }}"
                    class="btn btn-danger-outline"
                >{{ __('settings.appearance.reset_colors') }}</button>
                <button type="submit" class="btn btn-primary" wire:loading.attr="disabled">{{ __('common.save') }}</button>
            </div>
        </form>

        <div class="space-y-6">
            <div class="card">
                <div class="border-b border-line px-6 py-4">
                    <h2 class="font-semibold text-ink">{{ __('settings.appearance.contrast') }}</h2>
                    <p class="text-xs text-muted">{{ __('settings.appearance.contrast_help') }}</p>
                </div>
                <ul class="divide-y divide-line">
                    @foreach ($this->contrastChecks as $check)
                        <li wire:key="contrast-{{ $loop->index }}" class="flex items-center gap-3 px-6 py-3 text-sm">
                            <span class="grid h-8 w-12 shrink-0 place-items-center rounded border border-line text-xs font-bold" style="color: {{ $check['foreground'] }}; background-color: {{ $check['background'] }}" aria-hidden="true">Aa</span>
                            <span class="flex-1 text-ink">{{ $check['label'] }}</span>
                            <span class="font-mono text-ink">{{ number_format($check['ratio'], 2) }}:1</span>
                            @if ($check['passes'])
                                <span class="rounded-full bg-neutral-200 px-2 py-0.5 text-xs font-semibold text-ink">{{ __('settings.appearance.pass') }}</span>
                            @else
                                <span class="rounded-full bg-brand-700 px-2 py-0.5 text-xs font-semibold text-white">{{ __('settings.appearance.fail') }}</span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </div>

            {{-- Live preview: theme variables are scoped to this panel only. --}}
            <div class="card overflow-hidden" style="{{ \App\Enums\ThemeColor::cssDeclarations($this->previewColors) }}" data-testid="theme-preview">
                <div class="border-b border-line bg-surface px-6 py-4">
                    <h2 class="font-semibold text-ink">{{ __('settings.appearance.preview') }}</h2>
                </div>
                <div class="flex min-h-64">
                    <div class="w-44 shrink-0 space-y-1 bg-sidebar p-3">
                        <x-brand sidebar class="mb-3 px-1" />
                        <p class="px-2 pt-1 text-[10px] font-semibold tracking-wider text-sidebar-muted uppercase">{{ __('nav.sales') }}</p>
                        <span class="flex items-center gap-2 rounded-md bg-brand-600 px-2 py-1.5 text-sm font-medium text-white"><x-icon name="dashboard" class="size-4" /> {{ __('nav.dashboard') }}</span>
                        <span class="flex items-center gap-2 rounded-md px-2 py-1.5 text-sm font-medium text-sidebar-text hover:bg-sidebar-line"><x-icon name="leads" class="size-4" /> {{ __('nav.leads') }}</span>
                        <span class="flex items-center gap-2 rounded-md bg-sidebar-line px-2 py-1.5 text-sm font-medium text-sidebar-text"><x-icon name="offers" class="size-4" /> {{ __('settings.appearance.preview_offers_hover') }}</span>
                    </div>
                    <div class="flex-1 space-y-4 bg-canvas p-4">
                        <div class="rounded-lg border border-line bg-surface p-4 shadow-sm">
                            <div class="flex items-center justify-between gap-2">
                                <h3 class="font-semibold text-ink">{{ __('settings.appearance.preview_offer') }}</h3>
                                <span class="rounded-full bg-brand-600 px-2 py-0.5 text-xs font-semibold text-white">{{ __('settings.appearance.preview_sent') }}</span>
                            </div>
                            <p class="mt-1 text-sm text-muted">{{ __('settings.appearance.preview_secondary_text') }}</p>
                            <p class="mt-2 text-sm"><span class="link">{{ __('settings.appearance.preview_link') }}</span></p>
                            <div class="mt-3 flex flex-wrap gap-2">
                                <span class="btn btn-primary">{{ __('settings.appearance.preview_primary') }}</span>
                                <span class="btn bg-brand-700 text-white">{{ __('settings.appearance.preview_hover') }}</span>
                                <span class="btn btn-secondary">{{ __('settings.appearance.preview_secondary') }}</span>
                            </div>
                            <input type="text" class="form-input mt-3" placeholder="{{ __('settings.appearance.preview_input') }}" tabindex="-1" readonly>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
