{{-- Portal members of one company (shared by the portal "Company users" page and the staff customer tab). --}}
<div class="card overflow-x-auto" data-portal-members>
    <table class="min-w-full divide-y divide-line text-sm">
        <thead class="text-left text-xs font-semibold tracking-wide text-muted uppercase">
            <tr><th scope="col" class="px-4 py-2">{{ __('portal.users.name') }}</th><th scope="col" class="px-4 py-2">{{ __('portal.users.roles') }}</th><th scope="col" class="px-4 py-2">{{ __('common.status') }}</th><th scope="col" class="px-4 py-2">{{ __('portal.users.telegram') }}</th><th></th></tr>
        </thead>
        <tbody class="divide-y divide-line">
            @forelse ($members as $member)
                <tr wire:key="pm-{{ $member->id }}">
                    <td class="px-4 py-2 text-ink">{{ $member->user?->name }}<span class="block text-xs text-muted">{{ $member->user?->hasDeliverableEmail() ? $member->user->email : __('telegram.groups.telegram_only') }}@if (! $member->activated_at) · {{ __('telegram.groups.pending_activation') }}@endif</span></td>
                    <td class="px-4 py-2 text-ink">{{ collect($member->roles)->map(fn ($role) => \App\Enums\CustomerPortalRole::tryFrom($role)?->label())->filter()->implode(', ') }}</td>
                    <td class="px-4 py-2 text-ink">{{ $member->is_active ? __('common.active') : __('common.inactive') }}@if ($member->user?->last_login_at)<span class="block text-xs text-muted">{{ __('portal.users.last_login', ['at' => $member->user->last_login_at->translatedFormat(\App\Enums\Locale::current()->dateTimeFormat())]) }}</span>@endif</td>
                    <td class="px-4 py-2 text-ink">{{ $member->user?->telegramLink ? __('common.yes') : __('common.no') }}</td>
                    <td class="px-4 py-2 text-right">
                        @if ($canManage && $member->is_active)
                            <button type="button" wire:click="{{ $deactivateAction }}({{ $member->id }})" wire:confirm="{{ __('portal.users.confirm_deactivate') }}" class="btn btn-secondary btn-sm">{{ __('portal.users.deactivate') }}</button>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="px-4 py-3 text-sm text-muted">{{ __('portal.users.empty') }}</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
