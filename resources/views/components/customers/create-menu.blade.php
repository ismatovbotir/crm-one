@props(['customer'])

{{--
    "Create" quick actions on the customer card (user decision 2026-10-20): each entry opens the
    form with this customer already selected (?customer=ID, validated again server-side). Shown only
    for abilities the user has; nothing for archived customers or those deleted in 1C.
--}}
@php
    $user = auth()->user();
    $actions = [];

    if (! $customer->trashed() && ! $customer->isDeletedInOneC()) {
        if ($user->can('create', \App\Models\Lead::class)) {
            $actions['lead'] = route('leads.create', ['customer' => $customer->id]);
        }

        if ($user->can('create', \App\Models\Offer::class)) {
            $actions['offer'] = route('offers.create', ['customer' => $customer->id]);
        }

        if ($user->can('create', \App\Models\Order::class) && \App\Authorization\PermissionMap::allowsFor($user, 'orders.create', $customer->owner_id)) {
            $actions['order'] = route('orders.create', ['customer' => $customer->id]);
        }

        if ($user->can('create', \App\Models\Payment::class)) {
            $actions['payment'] = route('payments.for-orders', ['customer' => $customer->id]);
        }

        if ($user->can('create', \App\Models\Agreement::class)) {
            $actions['framework'] = route('customers.show', ['customer' => $customer, 'tab' => 'finance']);
        }

        if ($user->can('register', [\App\Models\CustomerAsset::class, $customer])) {
            $actions['equipment'] = route('customers.show', ['customer' => $customer, 'tab' => 'equipment']);
        }
    }
@endphp

@if ($actions !== [])
    <div x-data="{ open: false }" class="relative" @click.outside="open = false" @keydown.escape="open = false" data-create-menu>
        <button type="button" class="btn btn-secondary" @click="open = !open" aria-haspopup="menu" aria-expanded="false" :aria-expanded="open.toString()" aria-controls="create-menu-{{ $customer->id }}">
            <x-icon name="plus" class="size-4" /> {{ __('customers.create_menu.button') }} <x-icon name="chevron-down" class="size-3.5" />
        </button>
        <div x-show="open" x-cloak x-transition id="create-menu-{{ $customer->id }}" role="menu" aria-label="{{ __('customers.create_menu.button') }}" class="absolute right-0 z-30 mt-2 w-64 overflow-hidden rounded-md border border-line bg-surface py-1 shadow-lg">
            @foreach ($actions as $key => $url)
                <a href="{{ $url }}" wire:navigate role="menuitem" class="block px-4 py-2 text-sm text-ink hover:bg-brand-50 hover:text-brand-700 focus:bg-brand-50 focus:outline-none" data-create="{{ $key }}">{{ __('customers.create_menu.'.$key) }}</a>
            @endforeach
        </div>
    </div>
@endif
