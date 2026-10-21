{{-- Development only (APP_ENV=local): sign in as another staff user to check the interface and menu per role. --}}
@if (app()->environment('local') && auth()->check() && auth()->user()->isInternal() && \Illuminate\Support\Facades\Route::has('dev.switch-user'))
    @php
        $devUsers = \App\Models\User::query()->internal()->active()->orderBy('role')->orderBy('name')->limit(100)->get();
        $originalId = session(\App\Http\Controllers\DevRoleSwitchController::SESSION_KEY);
    @endphp
    <form method="POST" action="{{ route('dev.switch-user') }}" class="hidden items-center gap-1 sm:flex" data-dev-role-switcher>
        @csrf
        <span class="rounded bg-brand-600 px-1.5 py-0.5 text-[10px] font-bold tracking-wide text-white" aria-hidden="true">DEV</span>
        <label for="dev-user" class="sr-only">{{ __('common.dev_switch_label') }}</label>
        <select id="dev-user" name="user_id" class="form-input w-56 py-1 text-xs">
            @foreach ($devUsers as $devUser)
                @php($department = $devUser->primaryDepartment())
                <option value="{{ $devUser->id }}" @selected($devUser->is(auth()->user()))>
                    {{ $devUser->role?->label() }}@if ($department) · {{ $department->displayName() }} ({{ $department->membership->role->label() }})@endif — {{ $devUser->name }}
                </option>
            @endforeach
        </select>
        <button type="submit" class="btn btn-secondary px-2 py-1 text-xs" data-action="dev-switch">{{ __('common.dev_switch') }}</button>
        @if ($originalId && (int) $originalId !== auth()->id())
            <span class="text-[11px] text-muted">{{ __('common.dev_switched_from', ['name' => \App\Models\User::query()->whereKey($originalId)->value('name')]) }}</span>
        @endif
    </form>
@endif
