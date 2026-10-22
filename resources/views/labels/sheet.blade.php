<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    @include('partials.head', ['title' => __('labels.print.title')])
    <style nonce="{{ Vite::cspNonce() }}">
        @page { size: {{ $template->width_mm }}mm {{ $template->height_mm }}mm; margin: 0; }

        .label-page {
            position: relative;
            width: {{ $template->width_mm }}mm;
            height: {{ $template->height_mm }}mm;
            overflow: hidden;
            background: #fff;
            font-family: Arial, Helvetica, sans-serif;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        @media print {
            html, body { background: #fff !important; margin: 0; padding: 0; }
            .print-chrome { display: none !important; }
            .label-canvas { padding: 0 !important; display: block !important; }
            .label-page { margin: 0 !important; box-shadow: none !important; break-after: page; page-break-after: always; }
            .label-page:last-child { break-after: auto; page-break-after: auto; }
        }
    </style>
</head>
<body class="bg-canvas">
    <header class="print-chrome sticky top-0 z-10 border-b border-line bg-surface">
        <div class="mx-auto flex max-w-3xl flex-wrap items-center gap-3 px-4 py-3">
            <a href="{{ $backUrl }}" class="link text-sm">&larr; {{ __('common.back') }}</a>
            <span class="text-sm text-muted">{{ $template->name }} · {{ count($labels) }} {{ __('labels.print.labels') }} · {{ $template->width_mm }} × {{ $template->height_mm }} mm</span>
            <div class="flex-1"></div>
            <button type="button" class="btn btn-primary" data-print-button><x-icon name="printer" class="size-4" /> {{ __('common.print.print_button') }}</button>
            <script nonce="{{ Vite::cspNonce() }}">document.querySelector('[data-print-button]').addEventListener('click', () => window.print());</script>
        </div>
        <p class="mx-auto max-w-3xl px-4 pb-2 text-xs text-muted">{{ __('labels.print.sheet_hint', ['width' => $template->width_mm, 'height' => $template->height_mm]) }}</p>
    </header>

    <main class="label-canvas flex flex-col items-center gap-4 px-4 py-6">
        @foreach ($labels as $values)
            <div class="label-page shadow-md">
                @foreach ($template->elements as $element)
                    <x-label-element :element="$element" :values="$values" :logo="$logo" />
                @endforeach
            </div>
        @endforeach
    </main>
</body>
</html>
