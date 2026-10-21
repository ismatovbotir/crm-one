<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    @include('partials.head')
</head>
<body class="h-full">
    <a href="#main-content" class="skip-link">{{ __('common.skip_to_content') }}</a>
    <div class="grid min-h-full lg:grid-cols-2">
        <aside class="relative hidden flex-col justify-between overflow-hidden bg-sidebar p-12 text-sidebar-text lg:flex">
            <div class="absolute inset-y-0 left-0 w-1.5 bg-brand-600" aria-hidden="true"></div>
            <p class="text-sm font-semibold tracking-wider text-sidebar-muted uppercase">{{ config('crm.name') }}</p>
            <div class="max-w-md">
                <p class="text-3xl leading-tight font-semibold">{{ __('auth.tagline') }}</p>
                <p class="mt-4 text-sidebar-muted">{{ __('auth.tagline_sub') }}</p>
            </div>
            <p class="text-xs text-sidebar-muted">&copy; {{ now()->year }} {{ config('crm.name') }}</p>
        </aside>

        <main id="main-content" tabindex="-1" class="relative flex items-center justify-center bg-surface px-6 py-12">
            <div class="absolute top-4 right-4 sm:top-6 sm:right-6">
                <x-locale-switcher />
            </div>

            <div class="w-full max-w-sm">
                <div class="mb-8 flex justify-center">
                    <x-brand :size="88" :wordmark="false" />
                </div>
                {{ $slot }}
            </div>
        </main>
    </div>

    @livewireScripts
</body>
</html>
