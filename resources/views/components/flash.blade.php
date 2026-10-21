@if (session('status'))
    <div {{ $attributes->merge(['class' => 'rounded-md border border-line border-l-4 border-l-brand-600 bg-surface px-4 py-3 text-sm text-ink shadow-sm']) }} role="status">
        {{ session('status') }}
    </div>
@endif
@if (session('error'))
    <div {{ $attributes->merge(['class' => 'flex items-center gap-2 rounded-md border border-brand-600 bg-brand-50 px-4 py-3 text-sm text-brand-800 shadow-sm']) }} role="alert" data-flash-error>
        <x-icon name="warning" class="size-4 shrink-0" /> {{ session('error') }}
    </div>
@endif
