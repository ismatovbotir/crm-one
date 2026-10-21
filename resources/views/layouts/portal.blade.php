<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    @include('partials.head')
</head>
<body class="min-h-full bg-canvas">
    <a href="#main-content" class="skip-link">{{ __('common.skip_to_content') }}</a>
    {{-- Customer portal shell (Spec 03 4.1): top bar with config('navigation.portal_sections'), no staff sidebar. --}}
    @php
        $context = app(\App\Support\PortalContext::class);
        $portalNav = collect(config('navigation.portal_sections', []))->filter(fn (array $entry) => (! ($entry['finance'] ?? false) || $context->canSeeFinance()) && (! ($entry['admin'] ?? false) || $context->canManageUsers()));
    @endphp
    <header class="border-b border-line bg-surface">
        <div class="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-4 px-4 py-3 sm:px-6">
            <div class="flex items-center gap-3">
                <x-brand :size="36" :wordmark="false" />
                <div>
                    <p class="text-sm font-semibold text-ink">{{ config('crm.name') }}</p>
                    @if ($context->active())<p class="text-xs text-muted" data-portal-company>{{ $context->customer()->name }}</p>@endif
                </div>
            </div>
            <div class="flex items-center gap-3">
                <x-locale-switcher />
                @if ($context->active())
                    <a href="{{ route('portal.cabinet') }}" wire:navigate class="text-sm font-medium text-ink hover:underline">{{ $context->user()->name }}</a>
                    <form method="POST" action="{{ route('portal.logout') }}">
                        @csrf
                        <button type="submit" class="btn btn-secondary btn-sm">{{ __('common.log_out') }}</button>
                    </form>
                @endif
            </div>
        </div>
        <nav class="mx-auto flex max-w-6xl gap-1 overflow-x-auto px-4 sm:px-6" aria-label="{{ __('portal.nav.label') }}">
            @foreach ($portalNav as $entry)
                @php($active = request()->routeIs($entry['active'] ?? $entry['route']))
                <a href="{{ route($entry['route']) }}" wire:navigate @if ($active) aria-current="page" @endif
                   @class(['-mb-px border-b-2 px-3 py-2.5 text-sm font-semibold whitespace-nowrap', 'border-brand-600 text-ink' => $active, 'border-transparent text-muted hover:text-ink' => ! $active])>{{ __($entry['label']) }}</a>
            @endforeach
        </nav>
    </header>

    <main id="main-content" tabindex="-1" class="mx-auto max-w-6xl px-4 py-6 sm:px-6">
        @if (filled($title ?? null))
            <h1 class="mb-4 text-2xl font-semibold tracking-tight text-ink">{{ $title }}</h1>
        @endif
        {{ $slot }}
    </main>

    @livewireScripts
</body>
</html>
