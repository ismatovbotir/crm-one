@props(['current', 'param' => 'lang'])

{{--
    Document language segmented control (UZ | RU | EN) for print screens. Independent of the
    interface language: each option reloads the current URL with ?{{ $param }}=xx.
    Usage: <x-print-language-picker :current="$documentLanguage" />
--}}
<div {{ $attributes->merge(['class' => 'inline-flex items-center gap-2']) }} data-print-language-picker>
    <span id="print-language-label" class="text-sm font-medium text-ink">{{ __('common.print.document_language') }}</span>
    <div class="inline-flex overflow-hidden rounded-md border border-line-strong" role="group" aria-labelledby="print-language-label">
        @foreach (\App\Enums\Locale::available() as $locale)
            <a
                href="{{ request()->fullUrlWithQuery([$param => $locale->value]) }}"
                hreflang="{{ $locale->value }}"
                title="{{ $locale->label() }}"
                @if ($locale === $current) aria-current="true" @endif
                @class([
                    'px-3 py-1.5 text-xs font-semibold transition-colors',
                    'border-l border-line-strong' => ! $loop->first,
                    'bg-brand-600 text-white' => $locale === $current,
                    'bg-surface text-ink hover:bg-neutral-100' => $locale !== $current,
                ])
            >{{ $locale->shortCode() }}</a>
        @endforeach
    </div>
</div>
