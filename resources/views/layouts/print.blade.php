{{--
    Print screen for any document rendered by App\Documents\DocumentRenderer::print().
    The toolbar is in the interface language and hidden on paper; $document is already
    rendered in $documentLanguage. A4, browser print / "Save as PDF" (no PDF library).
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    @include('partials.head')
    <style nonce="{{ Vite::cspNonce() }}">
        @page { size: A4; margin: 15mm 15mm 18mm; }

        .print-sheet {
            width: 210mm;
            min-height: 297mm;
            padding: 15mm;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        @media print {
            html, body { background: #fff !important; }
            .print-chrome { display: none !important; }
            .print-canvas { padding: 0 !important; }
            .print-sheet { width: auto; min-height: 0; padding: 0; margin: 0; box-shadow: none !important; border: 0 !important; }
            .print-avoid-break { break-inside: avoid; }
        }
    </style>
</head>
<body class="bg-canvas">
    <header class="print-chrome sticky top-0 z-10 border-b border-line bg-surface">
        <div class="mx-auto flex max-w-[210mm] flex-wrap items-center gap-3 px-4 py-3">
            @if ($backUrl)
                <a href="{{ $backUrl }}" class="link text-sm">&larr; {{ __('common.back') }}</a>
            @endif
            <div class="flex-1"></div>
            <x-print-language-picker :current="$documentLanguage" />
            <button type="button" class="btn btn-primary" data-print-button>
                <x-icon name="printer" class="size-4" /> {{ __('common.print.print_button') }}
            </button>
            {{-- CSP: no inline on*= handlers; this nonce'd script wires the button. --}}
            <script nonce="{{ Vite::cspNonce() }}">document.querySelector('[data-print-button]').addEventListener('click', () => window.print());</script>
        </div>
        <p class="mx-auto max-w-[210mm] px-4 pb-2 text-xs text-muted">{{ __('common.print.hint') }}</p>
    </header>

    <div class="print-canvas px-4 py-8">
        <main
            class="print-sheet mx-auto border border-line bg-surface text-ink shadow-md"
            lang="{{ $documentLanguage->value }}"
            data-document-language="{{ $documentLanguage->value }}"
        >
            {{ $document }}
        </main>
    </div>
</body>
</html>
