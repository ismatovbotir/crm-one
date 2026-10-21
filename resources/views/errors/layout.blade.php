@php
    // Error pages must render even when the database or session is unavailable (500/503), so
    // every lookup falls back quietly. No scripts: only the compiled stylesheet and fonts.
    $logo = rescue(fn () => \App\Models\Setting::logoUrl(), asset(\App\Models\Setting::DEFAULT_LOGO), false);
    $home = rescue(fn () => \Illuminate\Support\Facades\Auth::guard('portal')->check() ? route('portal.dashboard') : url('/'), url('/'), false);
    $previous = rescue(fn () => url()->previous(), null, false);
    $previous = $previous !== null && $previous !== url()->current() ? $previous : null;
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <link rel="icon" href="{{ $logo }}">
    <title>{{ $code }} · {{ __('errors.'.$code.'.title') }} · {{ config('crm.name') }}</title>
    @fonts
    @vite(['resources/css/app.css'])
</head>
<body class="h-full">
    <div class="grid min-h-full lg:grid-cols-2">
        <aside class="relative hidden flex-col justify-between overflow-hidden bg-sidebar p-12 text-sidebar-text lg:flex">
            <div class="absolute inset-y-0 left-0 w-1.5 bg-brand-600" aria-hidden="true"></div>
            <p class="text-sm font-semibold tracking-wider text-sidebar-muted uppercase">{{ config('crm.name') }}</p>
            <p class="text-8xl leading-none font-semibold tracking-tight text-sidebar-text/90" aria-hidden="true">{{ $code }}</p>
            <p class="text-xs text-sidebar-muted">&copy; {{ now()->year }} {{ config('crm.name') }}</p>
        </aside>

        <main class="flex items-center justify-center bg-surface px-6 py-12">
            <div class="w-full max-w-md text-center lg:text-left" data-error-page="{{ $code }}">
                <div class="mb-8 flex justify-center lg:justify-start">
                    <img src="{{ $logo }}" alt="{{ config('crm.name') }}" width="72" height="72" class="size-18 shrink-0 rounded-lg bg-surface object-contain shadow-sm ring-1 ring-ink/10">
                </div>
                <p class="font-mono text-sm font-semibold text-brand-700">{{ __('errors.code', ['code' => $code]) }}</p>
                <h1 class="mt-2 text-2xl font-semibold tracking-tight text-ink">{{ __('errors.'.$code.'.title') }}</h1>
                <p class="mt-3 text-sm text-muted">{{ __('errors.'.$code.'.message') }}</p>
                @isset($extra)
                    <p class="mt-2 text-sm text-muted">{{ $extra }}</p>
                @endisset
                <div class="mt-8 flex flex-wrap justify-center gap-3 lg:justify-start">
                    <a href="{{ $home }}" class="btn btn-primary">{{ __('errors.home') }}</a>
                    @if ($previous)
                        <a href="{{ $previous }}" class="btn btn-secondary">{{ __('errors.back') }}</a>
                    @endif
                </div>
            </div>
        </main>
    </div>
</body>
</html>
