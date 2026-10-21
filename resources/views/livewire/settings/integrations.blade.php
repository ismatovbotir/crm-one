<div class="space-y-6">
    <p class="text-sm text-muted">{{ __('integration.settings.intro') }}</p>

    <x-flash />

    @if ($issuedToken)
        <div class="card border-2 border-brand-600 p-5" role="alert" data-issued-token>
            <p class="font-semibold text-ink">{{ __('integration.tokens.issued_title') }}</p>
            <p class="mt-1 text-sm text-brand-700">{{ __('integration.tokens.issued_warning') }}</p>
            <div class="mt-3 flex flex-col gap-2 sm:flex-row sm:items-center" x-data="copyField">
                <label for="issued-token" class="sr-only">{{ __('integration.tokens.token') }}</label>
                <input id="issued-token" type="text" readonly value="{{ $issuedToken }}" class="form-input font-mono text-xs" x-ref="token" @focus="selectAll">
                <button type="button" class="btn btn-secondary shrink-0" @click="copy">
                    <span x-show="notCopied">{{ __('integration.tokens.copy') }}</span>
                    <span x-show="copied" x-cloak>{{ __('integration.tokens.copied') }}</span>
                </button>
            </div>
            @if ($issuedSecret)
                <div class="mt-3">
                    <label for="issued-secret" class="form-label">{{ __('integration.tokens.signing_secret') }}</label>
                    <input id="issued-secret" type="text" readonly value="{{ $issuedSecret }}" class="form-input font-mono text-xs" x-data="copyField" @focus="selectAll">
                </div>
            @endif
            <button type="button" wire:click="dismissIssued" class="btn btn-primary mt-4">{{ __('integration.tokens.issued_done') }}</button>
        </div>
    @endif

    <div class="grid gap-6 xl:grid-cols-2">
        <form wire:submit="saveSettings" class="card" novalidate>
            <div class="space-y-5 p-6">
                <h2 class="font-semibold text-ink">{{ __('integration.settings.inbound_title') }}</h2>

                <div>
                    <p class="form-label">{{ __('integration.settings.api_base_url') }}</p>
                    <p class="rounded-md border border-line bg-neutral-50 px-3 py-2 font-mono text-xs break-all text-ink" data-api-base-url>{{ $apiBaseUrl }}</p>
                    <p class="mt-1 text-xs text-muted">{{ __('integration.settings.api_base_url_help') }}</p>
                </div>

                <div class="rounded-md border border-line p-3 text-sm" data-api-docs>
                    <a href="{{ route('api-docs') }}" class="link font-medium" target="_blank" rel="noopener">{{ __('integration.docs.link') }}</a>
                    <span class="text-xs text-muted">· <a href="{{ route('api-docs.json') }}" class="link">openapi.json</a> · <a href="{{ route('api-docs.yaml') }}" class="link">openapi.yaml</a></span>
                    <p class="mt-1 text-xs text-muted">{{ __('integration.docs.hint') }}</p>
                    <p class="mt-1 text-xs text-muted">{{ __('integration.docs.token_hint') }}</p>
                </div>

                <label class="flex items-start gap-3">
                    <input type="checkbox" wire:model="inboundEnabled" class="mt-0.5 size-4 rounded border-line-strong accent-brand-600">
                    <span>
                        <span class="block text-sm font-medium text-ink">{{ __('integration.settings.inbound_enabled') }}</span>
                        <span class="block text-xs text-muted">{{ __('integration.settings.inbound_enabled_help') }}</span>
                    </span>
                </label>

                <fieldset>
                    <legend class="form-label">{{ __('integration.settings.stale_title') }}</legend>
                    <p class="mb-2 text-xs text-muted">{{ __('integration.settings.stale_help') }}</p>
                    <div class="grid gap-3 sm:grid-cols-3">
                        @foreach ($feeds as $key => $status)
                            <div wire:key="stale-input-{{ $key }}">
                                <label for="stale-{{ $key }}" class="text-xs font-medium text-ink">{{ $status['feed']->label() }}</label>
                                <input id="stale-{{ $key }}" type="number" min="1" max="10080" wire:model="staleMinutes.{{ $key }}" class="form-input">
                                @error('staleMinutes.'.$key) <p class="form-error">{{ $message }}</p> @enderror
                            </div>
                        @endforeach
                    </div>
                </fieldset>
            </div>
            <div class="flex justify-end border-t border-line bg-neutral-50 px-6 py-4">
                <button type="submit" class="btn btn-primary" wire:loading.attr="disabled">{{ __('common.save') }}</button>
            </div>
        </form>

        <div class="card">
            <div class="border-b border-line px-6 py-4">
                <h2 class="font-semibold text-ink">{{ __('integration.settings.feeds_title') }}</h2>
            </div>
            <ul class="divide-y divide-line">
                @foreach ($feeds as $key => $status)
                    <li wire:key="feed-{{ $key }}" class="flex flex-wrap items-center justify-between gap-2 px-6 py-3 text-sm">
                        <span class="font-medium text-ink">{{ $status['feed']->label() }}</span>
                        <span class="text-muted">
                            {{ __('integration.last_received') }}:
                            @if ($status['last_received_at'])
                                <time datetime="{{ $status['last_received_at']->toIso8601String() }}" class="text-ink">{{ $status['last_received_at']->diffForHumans() }}</time>
                            @else
                                <span class="text-ink">{{ __('common.never') }}</span>
                            @endif
                        </span>
                        @if ($status['is_stale'])
                            <x-badge tone="red" icon="warning">{{ __('integration.stale') }}</x-badge>
                        @endif
                    </li>
                @endforeach
            </ul>
        </div>
    </div>

    <section class="card p-6" data-document-outbox>
        <h3 class="text-base font-semibold text-ink">{{ __('integration.document_outbox.title') }}</h3>
        <p class="mt-1 text-sm text-muted">{{ __('integration.document_outbox.intro') }}</p>
        @php($oldest = $documentOutbox['oldest_open_at'])
        <dl class="mt-4 grid gap-4 text-sm sm:grid-cols-3 lg:grid-cols-6">
            <div><dt class="text-xs text-muted">{{ __('integration.document_outbox.open') }}</dt><dd class="font-semibold text-ink">{{ $documentOutbox['open'] }}</dd></div>
            <div><dt class="text-xs text-muted">{{ __('integration.document_outbox.blocked') }}</dt><dd class="font-semibold text-ink">{{ $documentOutbox['blocked'] }}</dd></div>
            <div><dt class="text-xs text-muted">{{ __('integration.document_outbox.rejected') }}</dt><dd @class(['font-semibold', 'text-brand-700' => $documentOutbox['rejected'] > 0, 'text-ink' => $documentOutbox['rejected'] === 0])>{{ $documentOutbox['rejected'] }}</dd></div>
            <div><dt class="text-xs text-muted">{{ __('integration.document_outbox.oldest') }}</dt><dd @class(['font-semibold', 'text-brand-700' => $oldest && $oldest->lt(now()->subMinutes((int) config('accounting.outbox.stale_minutes'))), 'text-ink' => ! $oldest || $oldest->gte(now()->subMinutes((int) config('accounting.outbox.stale_minutes')))])>{{ $oldest?->diffForHumans() ?? '—' }}</dd></div>
            <div><dt class="text-xs text-muted">{{ __('integration.document_outbox.last_poll') }}</dt><dd class="text-ink">{{ $documentContacts['poll']?->diffForHumans() ?? __('integration.document_outbox.never') }}</dd></div>
            <div><dt class="text-xs text-muted">{{ __('integration.document_outbox.last_payments') }}</dt><dd class="text-ink">{{ $documentContacts['payments']?->diffForHumans() ?? __('integration.document_outbox.never') }}</dd></div>
            <div><dt class="text-xs text-muted">{{ __('integration.document_outbox.last_receipts') }}</dt><dd class="text-ink">{{ $documentContacts['receipts']?->diffForHumans() ?? __('integration.document_outbox.never') }}</dd></div>
        </dl>
    </section>

    <form wire:submit="saveCustomerExchange" class="card" novalidate data-customer-exchange>
        <div class="border-b border-line px-6 py-4">
            <h2 class="font-semibold text-ink">{{ __('integration.customer_exchange.title') }}</h2>
            <p class="text-xs text-muted">{{ __('integration.customer_exchange.help') }}</p>
        </div>
        <div class="grid gap-6 p-6 lg:grid-cols-2">
            <div class="space-y-4">
                <label class="flex items-start gap-3">
                    <input type="checkbox" wire:model="customersEnabled" class="mt-0.5 size-4 rounded border-line-strong accent-brand-600">
                    <span>
                        <span class="block text-sm font-medium text-ink">{{ __('integration.customer_exchange.enabled') }}</span>
                        <span class="block text-xs text-muted">{{ __('integration.customer_exchange.enabled_help') }}</span>
                    </span>
                </label>
                <label class="flex items-start gap-3" data-allow-create>
                    <input type="checkbox" wire:model="allowCreateFromOneC" class="mt-0.5 size-4 rounded border-line-strong accent-brand-600">
                    <span>
                        <span class="block text-sm font-medium text-ink">{{ __('integration.customer_exchange.allow_create') }}</span>
                        <span class="block text-xs text-muted">{{ __('integration.customer_exchange.allow_create_help') }}</span>
                    </span>
                </label>
                <div>
                    <label for="poll-stale" class="form-label">{{ __('integration.customer_exchange.poll_stale') }}</label>
                    <input id="poll-stale" type="number" min="1" max="10080" wire:model="customerPollStaleMinutes" class="form-input max-w-40">
                    <p class="mt-1 text-xs text-muted">{{ __('integration.customer_exchange.poll_stale_help') }}</p>
                    @error('customerPollStaleMinutes') <p class="form-error">{{ $message }}</p> @enderror
                </div>
            </div>
            <div class="space-y-3 text-sm">
                <p class="font-medium text-ink">{{ __('integration.customer_exchange.last_contact') }}</p>
                <ul class="divide-y divide-line rounded-md border border-line">
                    @foreach ($customerContacts as $channel => $time)
                        <li class="flex items-center justify-between gap-2 px-3 py-2" wire:key="contact-{{ $channel }}" data-last-contact="{{ $channel }}">
                            <span class="text-ink">{{ __('integration.customer_exchange.channels.'.$channel) }}</span>
                            <span class="text-muted">{{ $time ? $time->diffForHumans() : __('common.never') }}</span>
                        </li>
                    @endforeach
                </ul>
                @if ($pollStale)
                    <x-badge tone="red" icon="warning" data-poll-stale>{{ __('integration.customer_exchange.poll_stale_badge') }}</x-badge>
                @endif
                <p class="text-xs text-muted">{{ __('integration.customer_exchange.outbox_line', ['pending' => $outbox['pending'], 'failed' => $outbox['failed']]) }}@if ($outbox['oldest']) · {{ __('integration.customer_exchange.oldest', ['time' => \Illuminate\Support\Carbon::parse($outbox['oldest'])->diffForHumans()]) }}@endif</p>
            </div>
        </div>
        <div class="flex justify-end border-t border-line bg-neutral-50 px-6 py-4">
            <button type="submit" class="btn btn-primary" wire:loading.attr="disabled">{{ __('common.save') }}</button>
        </div>
    </form>

    <div class="card">
        <div class="border-b border-line px-6 py-4">
            <h2 class="font-semibold text-ink">{{ __('integration.tokens.title') }}</h2>
            <p class="text-xs text-muted">{{ __('integration.tokens.help') }}</p>
        </div>

        <form wire:submit="createToken" class="grid gap-4 border-b border-line p-6 lg:grid-cols-2" novalidate>
            <div>
                <label for="token-name" class="form-label">{{ __('integration.tokens.name') }}</label>
                <input id="token-name" type="text" wire:model="tokenName" maxlength="100" class="form-input">
                @error('tokenName') <p class="form-error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="token-days" class="form-label">{{ __('integration.tokens.days') }}</label>
                <input id="token-days" type="number" min="1" max="{{ $maxDays }}" wire:model="tokenDays" class="form-input">
                <p class="mt-1 text-xs text-muted">{{ __('integration.tokens.days_help', ['max' => $maxDays]) }}</p>
                @error('tokenDays') <p class="form-error">{{ $message }}</p> @enderror
            </div>
            <fieldset>
                <legend class="form-label">{{ __('integration.tokens.abilities') }}</legend>
                <div class="space-y-1">
                    @foreach ($abilities as $ability)
                        <label wire:key="ability-{{ $ability->value }}" class="flex items-center gap-2 text-sm text-ink">
                            <input type="checkbox" wire:model="tokenAbilities" value="{{ $ability->value }}" class="size-4 rounded border-line-strong accent-brand-600">
                            {{ $ability->label() }} <span class="font-mono text-xs text-muted">{{ $ability->value }}</span>
                        </label>
                    @endforeach
                </div>
                @error('tokenAbilities') <p class="form-error">{{ $message }}</p> @enderror
            </fieldset>
            <div class="space-y-3">
                <div>
                    <label for="token-ips" class="form-label">{{ __('integration.tokens.allowed_ips') }}</label>
                    <textarea id="token-ips" rows="2" wire:model="tokenAllowedIps" class="form-input font-mono text-xs" placeholder="10.0.0.5/32"></textarea>
                    <p class="mt-1 text-xs text-muted">{{ __('integration.tokens.allowed_ips_help') }}</p>
                    @error('tokenAllowedIps') <p class="form-error">{{ $message }}</p> @enderror
                </div>
                <label class="flex items-start gap-2 text-sm text-ink">
                    <input type="checkbox" wire:model="tokenWithSecret" class="mt-0.5 size-4 rounded border-line-strong accent-brand-600">
                    <span>
                        {{ __('integration.tokens.with_secret') }}
                        <span class="block text-xs text-muted">{{ __('integration.tokens.with_secret_help') }}</span>
                    </span>
                </label>
                @error('tokenWithSecret') <p class="form-error">{{ $message }}</p> @enderror
            </div>
            <div class="lg:col-span-2">
                <button type="submit" class="btn btn-primary" wire:loading.attr="disabled">{{ __('integration.tokens.create') }}</button>
            </div>
        </form>

        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-line text-sm">
                <thead class="bg-sidebar text-left text-xs font-semibold tracking-wide text-sidebar-text uppercase">
                    <tr>
                        <th scope="col" class="px-4 py-3">{{ __('integration.tokens.name') }}</th>
                        <th scope="col" class="px-4 py-3">{{ __('integration.tokens.abilities') }}</th>
                        <th scope="col" class="px-4 py-3">{{ __('integration.tokens.allowed_ips') }}</th>
                        <th scope="col" class="px-4 py-3">{{ __('integration.tokens.last_used') }}</th>
                        <th scope="col" class="px-4 py-3">{{ __('integration.tokens.expires') }}</th>
                        <th scope="col" class="px-4 py-3">{{ __('common.status') }}</th>
                        <th scope="col" class="px-4 py-3 text-right"><span class="sr-only">{{ __('common.actions') }}</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line bg-surface">
                    @forelse ($this->tokens as $token)
                        <tr wire:key="token-{{ $token->id }}" @class(['bg-neutral-50' => ! $token->isActive()])>
                            <td class="px-4 py-3">
                                <span class="font-medium text-ink">{{ $token->name }}</span>
                                <span class="block font-mono text-xs text-muted">{{ $token->token_prefix }}…</span>
                            </td>
                            <td class="px-4 py-3 font-mono text-xs text-ink">{{ implode(', ', $token->abilities) }}</td>
                            <td class="px-4 py-3 font-mono text-xs text-ink">{{ $token->allowed_ips ? implode(', ', $token->allowed_ips) : __('integration.tokens.any_ip') }}@if ($token->requiresSignature()) <span class="block font-sans text-muted">{{ __('integration.tokens.hmac_on') }}</span>@endif</td>
                            <td class="px-4 py-3 text-xs text-ink">{{ $token->last_used_at ? $token->last_used_at->diffForHumans().' · '.$token->last_used_ip : __('common.never') }}</td>
                            <td class="px-4 py-3 text-xs text-ink">{{ $token->expires_at->translatedFormat(\App\Enums\Locale::current()->dateFormat()) }}</td>
                            <td class="px-4 py-3">
                                @if ($token->revoked_at)
                                    <x-badge tone="outline">{{ __('integration.tokens.status_revoked') }}</x-badge>
                                @elseif ($token->isExpired())
                                    <x-badge tone="outline">{{ __('integration.tokens.status_expired') }}</x-badge>
                                @else
                                    <x-badge tone="dark" icon="check">{{ __('common.active') }}</x-badge>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right whitespace-nowrap">
                                @if ($token->isActive())
                                    <button type="button" wire:click="rotateToken({{ $token->id }})" wire:confirm="{{ __('integration.tokens.rotate_confirm') }}" class="btn btn-secondary">{{ __('integration.tokens.rotate') }}</button>
                                    <button type="button" wire:click="revokeToken({{ $token->id }})" wire:confirm="{{ __('integration.tokens.revoke_confirm') }}" class="btn btn-danger-outline">{{ __('integration.tokens.revoke') }}</button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-4 py-6 text-center text-muted">{{ __('integration.tokens.empty') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <div class="border-b border-line px-6 py-4">
            <h2 class="font-semibold text-ink">{{ __('integration.batches.title') }}</h2>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-line text-sm">
                <thead class="bg-sidebar text-left text-xs font-semibold tracking-wide text-sidebar-text uppercase">
                    <tr>
                        <th scope="col" class="px-4 py-3">{{ __('integration.batches.received_at') }}</th>
                        <th scope="col" class="px-4 py-3">{{ __('integration.batches.endpoint') }}</th>
                        <th scope="col" class="px-4 py-3">{{ __('integration.batches.mode') }}</th>
                        <th scope="col" class="px-4 py-3">{{ __('common.status') }}</th>
                        <th scope="col" class="px-4 py-3">{{ __('integration.batches.counts') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line bg-surface">
                    @forelse ($batches as $batch)
                        <tr wire:key="batch-{{ $batch->id }}">
                            <td class="px-4 py-3 text-xs text-ink">
                                {{ $batch->received_at->translatedFormat(\App\Enums\Locale::current()->dateTimeFormat()) }}
                                <span class="block font-mono text-muted">{{ $batch->batch_id }}</span>
                            </td>
                            <td class="px-4 py-3 font-mono text-xs text-ink">{{ $batch->endpoint }}</td>
                            <td class="px-4 py-3 text-xs text-ink">{{ $batch->mode?->label() ?? '—' }}@if ($batch->snapshot_part) <span class="text-muted">({{ $batch->snapshot_part }}/{{ $batch->snapshot_total_parts }})</span>@endif</td>
                            <td class="px-4 py-3"><x-badge :tone="in_array($batch->status->value, ['failed', 'rejected', 'partial'], true) ? 'danger-outline' : 'silver'">{{ $batch->status->label() }}</x-badge></td>
                            <td class="px-4 py-3 text-xs text-ink">
                                {{ __('integration.batches.counts_line', [
                                    'accepted' => $batch->accepted_count,
                                    'rejected' => $batch->rejected_count,
                                    'failed' => $batch->counts['failed'] ?? 0,
                                ]) }}
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-4 py-6 text-center text-muted">{{ __('integration.batches.empty') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
