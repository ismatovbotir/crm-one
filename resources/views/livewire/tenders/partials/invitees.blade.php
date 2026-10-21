{{-- Invitation board (Spec 08 12.1): before opening only metadata (status, submitted yes/no and time). --}}
<div class="space-y-4" data-invitees>
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-line text-sm">
            <thead class="text-left text-xs font-semibold tracking-wide text-muted uppercase">
                <tr>
                    <th scope="col" class="py-2">{{ __('tenders.invitees.counterparty') }}</th>
                    <th scope="col" class="py-2">{{ __('tenders.invitees.qualification') }}</th>
                    <th scope="col" class="py-2">{{ __('common.status') }}</th>
                    <th scope="col" class="py-2">{{ __('tenders.invitees.language') }}</th>
                    <th scope="col" class="py-2"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-line">
                @forelse ($tender->invitations as $invitation)
                    <tr wire:key="inv-{{ $invitation->id }}">
                        <td class="py-2">
                            @can('view', $invitation->counterparty)
                                <a href="{{ route('counterparties.show', $invitation->counterparty) }}" wire:navigate class="link">{{ $invitation->counterparty?->name }}</a>
                            @else
                                {{ $invitation->counterparty?->name }}
                            @endcan
                            @if ($invitation->needs_addendum_confirmation) <x-badge tone="silver" icon="warning">{{ __('tenders.invitees.needs_confirmation') }}</x-badge> @endif
                        </td>
                        <td class="py-2">@php([$tone, $icon] = $invitation->counterparty->status->badge())<x-badge :tone="$tone" :icon="$icon">{{ $invitation->counterparty->status->label() }}</x-badge></td>
                        <td class="py-2 text-ink">{{ $invitation->status->label() }}@if ($invitation->decline_reason)<span class="block text-xs text-muted">{{ $invitation->decline_reason }}</span>@endif
                            @php($activeLink = $invitation->activeLink())
                            @if ($activeLink)
                                <span class="block text-xs text-muted" data-link-status>{{ __('tenders.links.status', ['via' => __('tenders.links.via.'.$activeLink->sent_via), 'at' => $local($activeLink->sent_at), 'opens' => $activeLink->open_count]) }}@if ($activeLink->verified_at) · {{ __('tenders.links.verified') }}@endif</span>
                                @if ($activeLink->possiblyForwarded())<x-badge tone="outline" icon="warning">{{ __('tenders.links.forwarded') }}</x-badge>@endif
                            @endif
                        </td>
                        <td class="py-2 text-ink">{{ strtoupper((string) $invitation->notice_locale) }}</td>
                        <td class="py-2 text-right whitespace-nowrap">
                            @can('print', $tender)
                                <a href="{{ route('tenders.notice', ['tender' => $tender, 'invitation' => $invitation->id]) }}" target="_blank" rel="noopener" class="link">{{ __('tenders.invitees.notice') }}</a>
                            @endcan
                            @can('enterBid', $tender)
                                @if ($invitation->status !== \App\Enums\InvitationStatus::Declined)
                                    <button type="button" wire:click="sendLink({{ $invitation->id }}, 'email')" class="btn btn-secondary btn-sm" @disabled(blank($invitation->contact_email)) data-action="send-link">{{ __('tenders.links.send') }}</button>
                                    <button type="button" wire:click="sendLink({{ $invitation->id }}, 'copy')" class="btn btn-secondary btn-sm" data-action="copy-link">{{ __('tenders.links.copy') }}</button>
                                @endif
                            @endcan
                            @if ($activeLink && $user->can('manageInvitations', $tender))
                                <button type="button" wire:click="revokeLink({{ $activeLink->id }})" wire:confirm="{{ __('tenders.links.confirm_revoke') }}" class="btn btn-secondary btn-sm" data-action="revoke-link">{{ __('tenders.links.revoke') }}</button>
                            @endif
                            @if ($editable)
                                <button type="button" wire:click="removeInvitation({{ $invitation->id }})" class="btn btn-secondary btn-sm">{{ __('tenders.remove') }}</button>
                            @endif
                            @if ($issuedForInvitation === $invitation->id && $issuedLink !== '')
                                <span class="mt-2 block text-left" data-issued-link>
                                    <label for="issued-{{ $invitation->id }}" class="text-xs text-muted">{{ __('tenders.links.copy_hint') }}</label>
                                    <input id="issued-{{ $invitation->id }}" type="text" readonly value="{{ $issuedLink }}" class="form-input font-mono text-xs">
                                </span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5"><x-empty-state :title="__('tenders.invitees.empty')" icon="customers" /></td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @can('manageInvitations', $tender)
        <div class="border-t border-line pt-4">
            <label for="inv-search" class="form-label">{{ __('tenders.invitees.add') }}</label>
            <input wire:model.live.debounce.300ms="inviteSearch" id="inv-search" type="search" class="form-input" placeholder="{{ __('tenders.invitees.search_placeholder') }}">
            @if ($this->inviteOptions->isNotEmpty())
                <ul class="mt-2 divide-y divide-line rounded-md border border-line">
                    @foreach ($this->inviteOptions as $option)
                        <li class="flex items-center justify-between gap-2 px-3 py-2 text-sm" wire:key="opt-{{ $option->id }}">
                            <span class="text-ink">{{ $option->name }} <span class="text-xs text-muted">{{ $option->status->label() }}</span></span>
                            <button type="button" wire:click="invite({{ $option->id }})" class="btn btn-secondary btn-sm">{{ __('tenders.invitees.invite') }}</button>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>

        @if ($tender->status === \App\Enums\TenderStatus::Published && $tender->invitations->isNotEmpty())
            <form wire:submit="recordDecline" class="flex flex-wrap items-end gap-2 border-t border-line pt-4">
                <div>
                    <label for="dec-inv" class="form-label">{{ __('tenders.invitees.record_decline') }}</label>
                    <select wire:model="declineInvitationId" id="dec-inv" class="form-input">
                        <option value="">—</option>
                        @foreach ($tender->invitations->where('status', '!=', \App\Enums\InvitationStatus::Declined) as $invitation) <option value="{{ $invitation->id }}">{{ $invitation->counterparty?->name }}</option> @endforeach
                    </select>
                </div>
                <div class="min-w-64 flex-1"><label for="dec-reason" class="form-label">{{ __('tenders.invitees.decline_reason') }}</label><input wire:model="declineReason" id="dec-reason" type="text" class="form-input"></div>
                <button type="submit" class="btn btn-secondary">{{ __('common.save') }}</button>
            </form>
        @endif
    @endcan
</div>
