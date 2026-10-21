<form wire:submit="convert" class="mt-5 space-y-5 border-t border-line pt-5" data-panel="convert-customer" novalidate>
    <div>
        <h3 class="font-semibold text-ink">{{ __('leads.conversion.title') }}</h3>
        <p class="mt-1 text-sm text-muted">{{ __('leads.conversion.intro') }}</p>
    </div>

    @error('conversion')
        <div class="flex items-center gap-2 rounded-md border border-brand-600 bg-brand-50 px-4 py-3 text-sm text-brand-800" role="alert" data-conversion-error>
            <x-icon name="warning" class="size-4 shrink-0" /> {{ $message }}
        </div>
    @enderror

    <fieldset class="space-y-2">
        <legend class="form-label">{{ __('leads.conversion.target') }}</legend>

        @if ($this->matches->isNotEmpty())
            <p class="text-sm text-muted">{{ __('leads.conversion.matches_found') }}</p>
            <ul class="divide-y divide-line rounded-md border border-line" data-conversion-matches>
                @foreach ($this->matches as $match)
                    @php($organization = $match['organization'])
                    <li class="flex flex-wrap items-center justify-between gap-3 px-4 py-3 text-sm" wire:key="match-{{ $organization->id }}" data-match="{{ $organization->id }}">
                        <div class="min-w-0">
                            <p class="font-semibold text-ink">{{ $organization->name }} <span class="font-mono text-xs text-muted">{{ $organization->tax_id }}</span></p>
                            <p class="text-xs text-muted">
                                {{ collect($match['reasons'])->map(fn ($reason) => __('leads.conversion.reasons.'.$reason))->join(', ') }}
                                · {{ $organization->is_customer ? __('leads.conversion.role_customer') : __('leads.conversion.role_supplier_only') }}
                                @if ($organization->owner) · {{ $organization->owner->name }} @endif
                            </p>
                        </div>
                        @if ($match['action'] === 'unavailable')
                            <x-badge tone="outline">{{ __('leads.conversion.unavailable') }}</x-badge>
                        @else
                            <label class="flex items-center gap-2 text-sm text-ink">
                                <input type="radio" name="conversion-target" class="accent-brand-600" wire:click="choose('{{ $match['action'] }}', {{ $organization->id }})" @checked($mode === $match['action'] && $organizationId === $organization->id) data-choose="{{ $match['action'] }}">
                                {{ $match['action'] === 'link' ? __('leads.conversion.link') : __('leads.conversion.add_role') }}
                            </label>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif

        <label @class(['flex items-center gap-2 text-sm', 'text-ink' => ! $this->taxIdBlocked, 'text-muted' => $this->taxIdBlocked])>
            <input type="radio" name="conversion-target" class="accent-brand-600" wire:click="choose('create')" @checked($mode === 'create') @disabled($this->taxIdBlocked) data-choose="create">
            {{ __('leads.conversion.create', ['name' => $this->lead->company_name ?? '—']) }}
        </label>
        @if ($this->taxIdBlocked)
            <p class="text-xs text-muted">{{ __('leads.conversion.tax_id_blocks_create') }}</p>
        @elseif ($mode === 'create' && $this->matches->isNotEmpty())
            <label class="flex items-center gap-2 text-sm text-ink">
                <input type="checkbox" wire:model="notDuplicate" class="rounded border-line"> {{ __('leads.conversion.not_duplicate') }}
            </label>
        @endif
    </fieldset>

    @if ($mode !== 'create' && $this->diff !== [])
        <div data-conversion-diff>
            <p class="form-label">{{ __('leads.conversion.diff_title') }}</p>
            <p class="mb-2 text-xs text-muted">{{ $this->mayChangeFields ? __('leads.conversion.diff_hint') : __('leads.conversion.diff_read_only') }}</p>
            <div class="overflow-x-auto rounded-md border border-line">
                <table class="w-full text-sm">
                    <thead class="bg-sidebar text-left text-xs text-muted uppercase">
                        <tr>
                            <th scope="col" class="px-3 py-2">{{ __('leads.conversion.field') }}</th>
                            <th scope="col" class="px-3 py-2">{{ __('leads.conversion.current') }}</th>
                            <th scope="col" class="px-3 py-2">{{ __('leads.conversion.from_lead') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        @foreach ($this->diff as $row)
                            <tr wire:key="diff-{{ $row['field'] }}" data-diff-field="{{ $row['field'] }}">
                                <th scope="row" class="px-3 py-2 text-left font-medium text-ink">{{ __('customers.fields.'.$row['field']) }}</th>
                                <td class="px-3 py-2">
                                    <label class="flex items-center gap-2 text-ink">
                                        <input type="radio" wire:model="fields.{{ $row['field'] }}" value="keep" class="accent-brand-600" @disabled(! $this->mayChangeFields)>
                                        {{ $row['current'] !== '' ? $row['current'] : __('leads.conversion.empty') }}
                                    </label>
                                </td>
                                <td class="px-3 py-2">
                                    <label class="flex items-center gap-2 text-ink">
                                        <input type="radio" wire:model="fields.{{ $row['field'] }}" value="lead" class="accent-brand-600" @disabled(! $this->mayChangeFields)>
                                        {{ $row['lead'] }}
                                    </label>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    @if ($mode !== 'link')
        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label for="conv-sale-type" class="form-label">{{ __('customers.fields.sale_type') }}</label>
                <select wire:model="saleType" id="conv-sale-type" class="form-input">
                    @foreach ($saleTypes as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            @if ($mode === 'create')
                <div>
                    <label for="conv-legal-type" class="form-label">{{ __('customers.fields.legal_type') }}</label>
                    <select wire:model="legalType" id="conv-legal-type" class="form-input">
                        @foreach ($legalTypes as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
            @endif
        </div>
    @endif

    <fieldset class="space-y-2">
        <legend class="form-label">{{ __('leads.conversion.options') }}</legend>
        <label class="flex items-start gap-2 text-sm text-ink">
            <input type="checkbox" wire:model="createOffer" class="mt-0.5 rounded border-line" data-option="create-offer">
            <span>{{ __('leads.conversion.option_offer', ['count' => $this->lead->items->count()]) }}</span>
        </label>
        <label class="flex items-start gap-2 text-sm text-ink">
            <input type="checkbox" wire:model="invitePortal" class="mt-0.5 rounded border-line" data-option="invite-portal">
            <span>{{ __('leads.conversion.option_portal') }}</span>
        </label>
        <label class="flex items-start gap-2 text-sm text-ink">
            <input type="checkbox" wire:model="onecPull" class="mt-0.5 rounded border-line" data-option="onec-pull">
            <span>{{ __('leads.conversion.option_onec') }}</span>
        </label>
    </fieldset>

    <p class="text-xs text-muted">{{ __('leads.conversion.moves') }}</p>

    <div class="flex justify-end gap-2">
        <button type="button" wire:click="$parent.openPanel('')" class="btn btn-secondary">{{ __('common.cancel') }}</button>
        <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" data-action="confirm-convert-customer">{{ __('leads.actions.convert_customer') }}</button>
    </div>
</form>
