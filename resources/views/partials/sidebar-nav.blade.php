@php
    /**
     * Sidebar built from config/navigation.php. Items the user may not see (Policy `can`)
     * are dropped; a group without visible children is dropped; an empty section is dropped.
     */
    $user = auth()->user();
    $allowed = fn (array $entry): bool => ! isset($entry['can']) || (bool) $user?->can(...$entry['can']);

    $resolve = function (array $entry) use (&$resolve, $allowed): ?array {
        if (! $allowed($entry)) {
            return null;
        }

        $entry['soon'] = ($entry['soon'] ?? false) || (! isset($entry['route']) && ! isset($entry['children']));
        $entry['href'] = ! $entry['soon'] && isset($entry['route']) ? route($entry['route']) : null;
        $entry['active'] = $entry['href'] !== null && request()->routeIs(...(array) ($entry['active'] ?? $entry['route']));

        if (isset($entry['children'])) {
            $entry['children'] = array_values(array_filter(array_map($resolve, $entry['children'])));

            if ($entry['children'] === []) {
                return null;
            }

            $entry['active'] = collect($entry['children'])->contains('active', true);
            $entry['dom_id'] = 'nav-group-'.($entry['key'] ?? \Illuminate\Support\Str::slug($entry['label']));
        }

        return $entry;
    };

    $sections = [];

    foreach (config('navigation.sections', []) as $section) {
        $items = array_values(array_filter(array_map($resolve, $section['items'])));

        if ($items !== []) {
            $sections[] = ['label' => $section['label'], 'items' => $items];
        }
    }
@endphp

<nav class="flex-1 space-y-6 overflow-y-auto px-3 py-5">
    @foreach ($sections as $section)
        <div>
            <p class="px-3 pb-2 text-xs font-semibold tracking-wider text-sidebar-muted uppercase">{{ __($section['label']) }}</p>
            <ul class="space-y-1">
                @foreach ($section['items'] as $entry)
                    <li>
                        @isset($entry['children'])
                            <div x-data="{ open: @js($entry['active']) }" data-nav-group="{{ $entry['key'] ?? '' }}">
                                <button
                                    type="button"
                                    @click="open = !open"
                                    aria-expanded="{{ $entry['active'] ? 'true' : 'false' }}"
                                    :aria-expanded="open.toString()"
                                    aria-controls="{{ $entry['dom_id'] }}"
                                    class="flex w-full items-center gap-3 rounded-md px-3 py-2 text-left text-sm font-medium text-sidebar-text transition-colors hover:bg-sidebar-line"
                                >
                                    <x-icon :name="$entry['icon']" />
                                    <span class="flex-1">{{ __($entry['label']) }}</span>
                                    <x-icon name="chevron-down" class="size-4 transition-transform" ::class="open && 'rotate-180'" />
                                </button>
                                <ul
                                    id="{{ $entry['dom_id'] }}"
                                    x-show="open"
                                    @unless ($entry['active']) x-cloak @endunless
                                    class="mt-1 space-y-1 border-l border-sidebar-line ml-5 pl-2"
                                >
                                    @foreach ($entry['children'] as $child)
                                        <li>
                                            <x-nav-link
                                                :href="$child['href']"
                                                :icon="$child['icon'] ?? null"
                                                :active="$child['active']"
                                                :disabled="$child['soon']"
                                                :new-window="$child['new_window'] ?? false"
                                            >{{ __($child['label']) }}</x-nav-link>
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        @else
                            <x-nav-link
                                :href="$entry['href']"
                                :icon="$entry['icon'] ?? null"
                                :active="$entry['active']"
                                :disabled="$entry['soon']"
                                :new-window="$entry['new_window'] ?? false"
                            >{{ __($entry['label']) }}</x-nav-link>
                        @endisset
                    </li>
                @endforeach
            </ul>
        </div>
    @endforeach
</nav>
