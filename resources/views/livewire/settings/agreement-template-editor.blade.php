<div class="space-y-6">
    @php($template = $this->template)
    <a href="{{ route('settings.agreement-templates') }}" wire:navigate class="link text-sm">&larr; {{ __('agreements.templates.back') }}</a>

    <x-flash />

    <header class="card flex flex-wrap items-start justify-between gap-4 p-5">
        <div>
            <p class="font-mono text-sm text-muted">{{ $template->code }} · {{ $template->kind->label() }}</p>
            <h2 class="text-xl font-semibold text-ink">{{ $template->name }}</h2>
            @unless ($isActive) <x-badge tone="outline">{{ __('agreements.templates.inactive') }}</x-badge> @endunless
        </div>
        <button type="button" wire:click="toggleActive" class="btn btn-secondary btn-sm">{{ $isActive ? __('agreements.templates.deactivate') : __('agreements.templates.activate') }}</button>
    </header>

    <div x-data="tabList" @keydown="onKey" role="tablist" class="flex gap-1 border-b border-line">
        @foreach ($locales as $option)
            <button type="button" role="tab" aria-selected="{{ $locale === $option->value ? 'true' : 'false' }}" wire:click="switchLocale('{{ $option->value }}')" wire:key="loc-{{ $option->value }}"
                    @class(['-mb-px border-b-2 px-4 py-2.5 text-sm font-semibold', 'border-brand-600 text-ink' => $locale === $option->value, 'border-transparent text-muted hover:text-ink' => $locale !== $option->value])>{{ $option->label() }}</button>
        @endforeach
    </div>

    <div class="grid gap-6 lg:grid-cols-[2fr_1fr]">
        <section class="card space-y-4 p-5">
            <p class="text-sm text-ink">
                @if ($this->published) {{ __('agreements.templates.published_version', ['version' => $this->published->version, 'date' => $this->published->published_at?->translatedFormat(\App\Enums\Locale::current()->dateTimeFormat())]) }} @else {{ __('agreements.templates.not_published') }} @endif
                @if ($this->draft) · <span class="font-semibold">{{ __('agreements.templates.draft_version', ['version' => $this->draft->version]) }}</span> @endif
            </p>
            <div>
                <label for="tpl-body" class="form-label">{{ __('agreements.templates.body') }}</label>
                <textarea wire:model.live.debounce.800ms="body" id="tpl-body" rows="22" class="form-input font-mono text-xs" spellcheck="false" data-template-body></textarea>
                @error('body') <p class="form-error">{{ $message }}</p> @enderror
                <p class="mt-1 text-xs text-muted">{{ __('agreements.templates.body_help') }}</p>
            </div>

            <fieldset>
                <legend class="form-label">{{ __('agreements.templates.clauses') }}</legend>
                <div class="space-y-2">
                    @foreach ($clauses as $index => $clause)
                        <div class="grid gap-2 sm:grid-cols-[1fr_2fr_auto_auto] sm:items-center" wire:key="clause-{{ $index }}">
                            <input wire:model="clauses.{{ $index }}.code" type="text" class="form-input font-mono" placeholder="{{ __('agreements.templates.clause_code') }}" aria-label="{{ __('agreements.templates.clause_code') }}">
                            <input wire:model="clauses.{{ $index }}.label" type="text" class="form-input" placeholder="{{ __('agreements.templates.clause_label') }}" aria-label="{{ __('agreements.templates.clause_label') }}">
                            <label class="flex items-center gap-1 text-xs text-ink"><input type="checkbox" wire:model="clauses.{{ $index }}.default" class="size-4 accent-brand-600"> {{ __('agreements.templates.clause_default') }}</label>
                            <button type="button" wire:click="removeClause({{ $index }})" class="btn btn-secondary btn-sm" aria-label="{{ __('agreements.templates.remove_clause') }}"><x-icon name="close" class="size-4" /></button>
                            @error('clauses.'.$index.'.code') <p class="form-error sm:col-span-4">{{ $message }}</p> @enderror
                            @error('clauses.'.$index.'.label') <p class="form-error sm:col-span-4">{{ $message }}</p> @enderror
                        </div>
                    @endforeach
                </div>
                <button type="button" wire:click="addClause" class="btn btn-secondary btn-sm mt-2"><x-icon name="plus" class="size-4" /> {{ __('agreements.templates.add_clause') }}</button>
            </fieldset>

            @if ($this->lint !== [])
                <ul class="space-y-1 rounded-md border border-brand-600 bg-brand-50 px-3 py-2 text-sm text-brand-800" data-lint>
                    @foreach ($this->lint as $problem)
                        <li wire:key="lint-{{ $loop->index }}">{{ __('agreements.validation.template_'.$problem['key'], $problem['params']) }}</li>
                    @endforeach
                </ul>
            @endif

            <div class="flex flex-wrap justify-end gap-2">
                @if ($this->draft)
                    <button type="button" wire:click="discardDraft" wire:confirm="{{ __('agreements.templates.discard_confirm') }}" class="btn btn-danger-outline">{{ __('agreements.templates.discard') }}</button>
                @endif
                <button type="button" wire:click="saveDraft" class="btn btn-secondary" wire:loading.attr="disabled">{{ __('agreements.templates.save_draft') }}</button>
                <button type="button" wire:click="publish" class="btn btn-primary" wire:loading.attr="disabled" data-action="publish">{{ __('agreements.templates.publish') }}</button>
            </div>
        </section>

        <aside class="card p-5">
            <h3 class="font-semibold text-ink">{{ __('agreements.templates.variables') }}</h3>
            <p class="mt-1 text-xs text-muted">{{ __('agreements.templates.variables_help') }}</p>
            <ul class="mt-3 space-y-1 text-xs">
                @foreach ($variableKeys as $key)
                    <li wire:key="var-{{ $key }}"><code class="font-mono text-ink">{{ "\u{7B}\u{7B} ".$key." \u{7D}\u{7D}" }}</code> <span class="text-muted">{{ __('agreements.variables.'.str_replace('.', '_', $key)) }}</span></li>
                @endforeach
            </ul>
        </aside>
    </div>
</div>
