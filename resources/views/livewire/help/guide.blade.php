<div class="space-y-6">
    <p class="text-sm text-muted">{{ __('guide.intro') }}</p>

    <div x-data="tabList" @keydown="onKey" role="tablist" aria-label="{{ __('guide.sections_label') }}" class="flex gap-1 overflow-x-auto border-b border-line">
        @foreach (\App\Livewire\Help\Guide::SECTIONS as $key => $unused)
            <button type="button" role="tab" id="tab-{{ $key }}" aria-controls="panel-guide" aria-selected="{{ $current === $key ? 'true' : 'false' }}" wire:key="tab-{{ $key }}"
                    wire:click="$set('section', '{{ $key }}')" data-guide-tab="{{ $key }}"
                    @class(['-mb-px border-b-2 px-4 py-2.5 text-sm font-semibold whitespace-nowrap', 'border-brand-600 text-ink' => $current === $key, 'border-transparent text-muted hover:text-ink' => $current !== $key])>
                {{ __('guide.sections.'.$key.'.title') }}@if ($key === $own) <span class="text-xs font-normal text-muted">· {{ __('guide.yours') }}</span>@endif
            </button>
        @endforeach
    </div>

    <section id="panel-guide" role="tabpanel" aria-labelledby="tab-{{ $current }}" class="card p-6" data-guide-section="{{ $current }}">
        <h2 class="text-lg font-semibold text-ink">{{ __('guide.sections.'.$current.'.title') }}</h2>
        <p class="mt-1 text-sm text-muted">{{ __('guide.sections.'.$current.'.intro') }}</p>

        <ol class="mt-5 space-y-4">
            @foreach ($steps as $step)
                <li class="flex gap-3" wire:key="step-{{ $current }}-{{ $step['key'] }}">
                    <span class="inline-flex size-6 shrink-0 items-center justify-center rounded-full bg-sidebar text-xs font-semibold text-sidebar-text tabular-nums" aria-hidden="true">{{ $loop->iteration }}</span>
                    <div class="min-w-0">
                        <p class="font-medium text-ink">{{ __('guide.sections.'.$current.'.steps.'.$step['key'].'.title') }}</p>
                        <p class="text-sm text-muted">{{ __('guide.sections.'.$current.'.steps.'.$step['key'].'.text') }}</p>
                        @if ($step['href'])
                            <a href="{{ $step['href'] }}" wire:navigate class="link mt-1 inline-block text-sm">{{ __('guide.open') }}</a>
                        @endif
                    </div>
                </li>
            @endforeach
        </ol>
    </section>
</div>
