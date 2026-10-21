<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    @include('partials.head')
</head>
<body class="h-full">
    <a href="#main-content" class="skip-link">{{ __('common.skip_to_content') }}</a>
    <div x-data="{ sidebarOpen: false }" @keydown.escape.window="sidebarOpen = false" class="min-h-full">
        {{-- Mobile backdrop --}}
        <div
            x-show="sidebarOpen"
            x-cloak
            x-transition.opacity
            class="fixed inset-0 z-30 bg-ink/60 lg:hidden"
            @click="sidebarOpen = false"
            aria-hidden="true"
        ></div>

        {{-- Sidebar --}}
        <aside
            id="sidebar"
            class="fixed inset-y-0 left-0 z-40 flex w-64 -translate-x-full flex-col bg-sidebar transition-transform duration-200 lg:translate-x-0"
            :class="{ 'translate-x-0': sidebarOpen, '-translate-x-full': !sidebarOpen }"
            aria-label="{{ __('common.main_navigation') }}"
        >
            <div class="flex h-16 items-center justify-between border-b border-sidebar-line px-5">
                <a href="{{ route('dashboard') }}" wire:navigate><x-brand sidebar /></a>
                <button type="button" class="rounded-md p-1.5 text-sidebar-text hover:bg-sidebar-line lg:hidden" @click="sidebarOpen = false">
                    <span class="sr-only">{{ __('common.close_sidebar') }}</span>
                    <x-icon name="close" />
                </button>
            </div>

            @include('partials.sidebar-nav')

            <div class="border-t border-sidebar-line px-5 py-4 text-xs text-sidebar-muted">
                {{ __('common.phase') }}
            </div>
        </aside>

        <div class="flex min-h-full flex-col lg:pl-64">
            {{-- Topbar --}}
            <header class="sticky top-0 z-20 flex h-16 items-center gap-4 border-b border-line bg-surface px-4 sm:px-6">
                <button
                    type="button"
                    class="-ml-1 rounded-md p-2 text-ink hover:bg-neutral-100 lg:hidden"
                    @click="sidebarOpen = true"
                    aria-controls="sidebar"
                    :aria-expanded="sidebarOpen.toString()"
                >
                    <span class="sr-only">{{ __('common.open_sidebar') }}</span>
                    <x-icon name="menu" class="size-6" />
                </button>

                <a href="{{ route('dashboard') }}" wire:navigate class="lg:hidden"><x-brand :size="32" :wordmark="false" /></a>

                <h1 class="flex-1 truncate text-lg font-semibold text-ink">{{ $title ?? '' }}</h1>

                @auth
                    <form method="GET" action="{{ route('search') }}" role="search" class="hidden md:block" data-global-search>
                        <label for="global-search" class="sr-only">{{ __('search.label') }}</label>
                        <input id="global-search" name="q" type="search" value="{{ request()->routeIs('search') ? request('q') : '' }}" placeholder="{{ __('search.placeholder_short') }}" class="form-input w-56 py-1.5 text-sm lg:w-72" maxlength="100" autocomplete="off">
                    </form>
                @endauth
                <x-dev-role-switcher />
                <x-locale-switcher />

                @auth
                    @php($unreadNotifications = auth()->user()->unreadNotifications()->count())
                    <a href="{{ route('notifications.index') }}" wire:navigate class="relative inline-flex size-9 items-center justify-center rounded-md text-ink hover:bg-brand-50" data-notification-bell>
                        <x-icon name="bell" class="size-5" />
                        <span class="sr-only">{{ __('notifications.title') }}: {{ trans_choice('notifications.unread_count', $unreadNotifications, ['count' => $unreadNotifications]) }}</span>
                        @if ($unreadNotifications > 0)
                            <span class="absolute -top-0.5 -right-0.5 inline-flex min-w-5 items-center justify-center rounded-full bg-brand-600 px-1 text-[10px] font-bold text-white tabular-nums" aria-hidden="true">{{ $unreadNotifications > 99 ? '99+' : $unreadNotifications }}</span>
                        @endif
                    </a>
                    <div x-data="{ open: false }" class="relative" @click.outside="open = false">
                        <button
                            type="button"
                            class="flex items-center gap-2 rounded-full py-1 pr-2 pl-1 hover:bg-neutral-100"
                            @click="open = !open"
                            :aria-expanded="open.toString()"
                            aria-haspopup="menu"
                        >
                            <span class="grid size-8 place-items-center rounded-full bg-ink text-xs font-semibold text-white">{{ auth()->user()->initials() }}</span>
                            <span class="hidden text-left sm:block">
                                <span class="block text-sm leading-tight font-medium text-ink">{{ auth()->user()->name }}</span>
                                <span class="block text-xs leading-tight text-muted">{{ auth()->user()->role?->label() }}</span>
                            </span>
                            <x-icon name="chevron-down" class="size-4 text-muted" />
                        </button>

                        <div
                            x-show="open"
                            x-cloak
                            x-transition
                            class="absolute right-0 mt-2 w-56 overflow-hidden rounded-md border border-line bg-surface shadow-lg"
                            role="menu"
                        >
                            <div class="border-b border-line px-4 py-3">
                                <p class="truncate text-sm font-medium text-ink">{{ auth()->user()->name }}</p>
                                <p class="truncate text-xs text-muted">{{ auth()->user()->email }}</p>
                            </div>
                            <a href="{{ route('cabinet') }}" wire:navigate class="flex w-full items-center gap-2 px-4 py-2.5 text-left text-sm text-ink hover:bg-brand-50 hover:text-brand-700" role="menuitem">
                                <x-icon name="contact" class="size-4" />
                                {{ __('telegram.cabinet.title') }}
                            </a>
                            <form method="POST" action="{{ route('logout') }}">
                                @csrf
                                <button type="submit" class="flex w-full items-center gap-2 px-4 py-2.5 text-left text-sm text-ink hover:bg-brand-50 hover:text-brand-700" role="menuitem">
                                    <x-icon name="logout" class="size-4" />
                                    {{ __('common.log_out') }}
                                </button>
                            </form>
                        </div>
                    </div>
                @endauth
            </header>

            <main id="main-content" tabindex="-1" class="flex-1 px-4 py-6 sm:px-6 lg:px-8">
                {{ $slot }}
            </main>
        </div>
    </div>

    @livewireScripts
</body>
</html>
