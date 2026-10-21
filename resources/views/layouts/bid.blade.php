<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    @include('partials.head')
    <meta name="robots" content="noindex, nofollow">
    <meta name="referrer" content="no-referrer">
</head>
<body class="min-h-full bg-canvas">
    <a href="#main-content" class="skip-link">{{ __('common.skip_to_content') }}</a>
    {{-- Public bid-link pages (Spec 08 8.2): no sidebar, no internal navigation. --}}
    <main id="main-content" tabindex="-1" class="mx-auto max-w-5xl px-4 py-8 sm:px-6">
        {{ $slot }}
    </main>

    @livewireScripts
</body>
</html>
