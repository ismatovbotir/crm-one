{{-- Tender header (wizard step 1). Internal-only fields are marked; never shown to bidders (BR-T.12). --}}
<form wire:submit="saveHeader" class="space-y-6" novalidate data-tender-overview>
    <fieldset class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3" @disabled(! $editable)>
        <legend class="mb-2 text-sm font-semibold text-ink">{{ __('tenders.sections.general') }}</legend>
        <div class="sm:col-span-2 lg:col-span-3">
            <label for="h-title" class="form-label">{{ __('tenders.fields.title') }}</label>
            <input wire:model="header.title" id="h-title" type="text" class="form-input">
            @error('header.title') <p class="form-error">{{ $message }}</p> @enderror
        </div>
        <div class="sm:col-span-2 lg:col-span-3">
            <label for="h-desc" class="form-label">{{ __('tenders.fields.description') }}</label>
            <textarea wire:model="header.description" id="h-desc" rows="3" class="form-input"></textarea>
        </div>
        <div>
            <label for="h-method" class="form-label">{{ __('tenders.fields.method') }}</label>
            <select wire:model.live="header.method" id="h-method" class="form-input">
                @foreach ($methods as $value => $label) <option value="{{ $value }}">{{ $label }}</option> @endforeach
            </select>
        </div>
        <div>
            <label for="h-kind" class="form-label">{{ __('tenders.fields.kind') }}</label>
            <select wire:model.live="header.kind" id="h-kind" class="form-input">
                @foreach ($kinds as $value => $label) <option value="{{ $value }}">{{ $label }}</option> @endforeach
            </select>
        </div>
        <div>
            <label for="h-locale" class="form-label">{{ __('tenders.fields.notice_locale') }}</label>
            <select wire:model="header.notice_locale" id="h-locale" class="form-input">
                @foreach (\App\Enums\Locale::available() as $locale) <option value="{{ $locale->value }}">{{ $locale->label() }}</option> @endforeach
            </select>
        </div>
        @if ($header['method'] === 'single_source')
            <div class="sm:col-span-2 lg:col-span-3">
                <label for="h-ss" class="form-label">{{ __('tenders.fields.single_source_justification') }}</label>
                <textarea wire:model="header.single_source_justification" id="h-ss" rows="2" class="form-input"></textarea>
            </div>
        @endif
    </fieldset>

    <fieldset class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3" @disabled(! $editable)>
        <legend class="mb-2 text-sm font-semibold text-ink">{{ __('tenders.sections.deadlines') }} ({{ $tz }})</legend>
        <div>
            <label for="h-clar" class="form-label">{{ __('tenders.fields.clarification_deadline_at') }}</label>
            <input wire:model="header.clarification_deadline_at" id="h-clar" type="datetime-local" class="form-input">
        </div>
        <div>
            <label for="h-due" class="form-label">{{ __('tenders.fields.bids_due_at') }}</label>
            <input wire:model="header.bids_due_at" id="h-due" type="datetime-local" class="form-input">
        </div>
        <div>
            <label for="h-validity" class="form-label">{{ __('tenders.fields.bid_validity_days') }}</label>
            <input wire:model="header.bid_validity_days" id="h-validity" type="number" min="1" class="form-input">
            @error('header.bid_validity_days') <p class="form-error">{{ $message }}</p> @enderror
        </div>
        <label class="flex items-center gap-2 text-sm text-ink"><input wire:model.live="header.urgent" type="checkbox" class="rounded border-line"> {{ __('tenders.fields.urgent') }}</label>
        @if ($header['urgent'])
            <div class="sm:col-span-2">
                <label for="h-urgent" class="form-label">{{ __('tenders.fields.urgent_justification') }}</label>
                <input wire:model="header.urgent_justification" id="h-urgent" type="text" class="form-input">
            </div>
        @endif
    </fieldset>

    <fieldset class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3" @disabled(! $editable)>
        <legend class="mb-2 text-sm font-semibold text-ink">{{ __('tenders.sections.commercial') }}</legend>
        <div class="sm:col-span-2 lg:col-span-3">
            <label for="h-terms" class="form-label">{{ __('tenders.fields.payment_terms') }}</label>
            <input wire:model="header.payment_terms" id="h-terms" type="text" class="form-input" placeholder="{{ __('tenders.placeholders.payment_terms') }}">
        </div>
        <div>
            <label for="h-days" class="form-label">{{ __('tenders.fields.payment_days') }}</label>
            <input wire:model="header.payment_days" id="h-days" type="number" min="0" class="form-input">
        </div>
        <div>
            <label for="h-prep" class="form-label">{{ __('tenders.fields.prepayment_percent') }}</label>
            <input wire:model="header.prepayment_percent" id="h-prep" type="text" inputmode="decimal" class="form-input">
            @error('header.prepayment_percent') <p class="form-error">{{ $message }}</p> @enderror
        </div>
        <div>
            <label for="h-eval" class="form-label">{{ __('tenders.fields.evaluation_currency') }}</label>
            <select wire:model="header.evaluation_currency" id="h-eval" class="form-input">
                @foreach (config('tenders.currencies') as $currency) <option value="{{ $currency }}">{{ $currency }}</option> @endforeach
            </select>
        </div>
        <fieldset class="sm:col-span-2 lg:col-span-3">
            <legend class="form-label">{{ __('tenders.fields.accepted_currencies') }}</legend>
            <div class="flex flex-wrap gap-4">
                @foreach (config('tenders.currencies') as $currency)
                    <label class="flex items-center gap-2 text-sm text-ink"><input wire:model="header.accepted_currencies" value="{{ $currency }}" type="checkbox" class="rounded border-line"> {{ $currency }}</label>
                @endforeach
            </div>
            @error('header.accepted_currencies') <p class="form-error">{{ $message }}</p> @enderror
        </fieldset>
        <label class="flex items-center gap-2 text-sm text-ink"><input wire:model="header.confidential" type="checkbox" class="rounded border-line"> {{ __('tenders.fields.confidential') }}</label>
        <label class="flex items-center gap-2 text-sm text-ink"><input wire:model="header.link_verification_required" type="checkbox" class="rounded border-line"> {{ __('tenders.fields.link_verification_required') }}</label>
        @if ($isTransport)
            <div>
                <label for="h-downtime" class="form-label">{{ __('tenders.fields.expected_downtime_days') }}</label>
                <input wire:model="header.expected_downtime_days" id="h-downtime" type="text" inputmode="decimal" class="form-input">
            </div>
        @endif
        @if ($header['kind'] === 'lane_contract')
            <div><label for="h-lane-from" class="form-label">{{ __('tenders.fields.lane_period_from') }}</label><input wire:model="header.lane_period_from" id="h-lane-from" type="date" class="form-input"></div>
            <div><label for="h-lane-to" class="form-label">{{ __('tenders.fields.lane_period_to') }}</label><input wire:model="header.lane_period_to" id="h-lane-to" type="date" class="form-input">@error('header.lane_period_to') <p class="form-error">{{ $message }}</p> @enderror</div>
            <div><label for="h-spm" class="form-label">{{ __('tenders.fields.shipments_per_month') }}</label><input wire:model="header.shipments_per_month" id="h-spm" type="number" min="0" class="form-input"></div>
        @endif
    </fieldset>

    @unless ($isTransport)
        <fieldset class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3" @disabled(! $editable)>
            <legend class="mb-2 text-sm font-semibold text-ink">{{ __('tenders.sections.delivery') }}</legend>
            <div>
                <label for="h-inco" class="form-label">{{ __('tenders.fields.incoterm') }}</label>
                <select wire:model="header.incoterm" id="h-inco" class="form-input">
                    <option value="">—</option>
                    @foreach ($incoterms as $value => $label) <option value="{{ $value }}">{{ $value }} — {{ $label }}</option> @endforeach
                </select>
            </div>
            <div class="sm:col-span-2">
                <label for="h-place" class="form-label">{{ __('tenders.fields.incoterm_place') }}</label>
                <input wire:model="header.incoterm_place" id="h-place" type="text" class="form-input">
            </div>
            <div>
                <label for="h-wh" class="form-label">{{ __('tenders.fields.delivery_warehouse') }}</label>
                <select wire:model="header.delivery_warehouse_id" id="h-wh" class="form-input">
                    <option value="">—</option>
                    @foreach ($warehouses as $warehouse) <option value="{{ $warehouse->id }}">{{ $warehouse->name }}</option> @endforeach
                </select>
            </div>
            <div class="sm:col-span-2">
                <label for="h-addr" class="form-label">{{ __('tenders.fields.delivery_address') }}</label>
                <input wire:model="header.delivery_address" id="h-addr" type="text" class="form-input">
            </div>
            <div>
                <label for="h-partial" class="form-label">{{ __('tenders.fields.partial_deliveries_allowed') }}</label>
                <select wire:model="header.partial_deliveries_allowed" id="h-partial" class="form-input">
                    <option value="">{{ __('tenders.unanswered') }}</option><option value="1">{{ __('common.yes') }}</option><option value="0">{{ __('common.no') }}</option>
                </select>
            </div>
            <label class="flex items-center gap-2 text-sm text-ink"><input wire:model="header.is_import" type="checkbox" class="rounded border-line"> {{ __('tenders.fields.is_import') }}</label>
            <div class="sm:col-span-2 lg:col-span-3">
                <label for="h-certs" class="form-label">{{ __('tenders.fields.required_certificates') }}</label>
                <textarea wire:model="header.required_certificates" id="h-certs" rows="2" class="form-input"></textarea>
            </div>
            <div class="sm:col-span-2 lg:col-span-3">
                <label for="h-warranty" class="form-label">{{ __('tenders.fields.warranty_terms') }}</label>
                <input wire:model="header.warranty_terms" id="h-warranty" type="text" class="form-input">
            </div>
        </fieldset>
    @endunless

    <fieldset class="grid gap-4 rounded-md border border-dashed border-line p-4 sm:grid-cols-2 lg:grid-cols-3" @disabled(! $editable) data-internal-fields>
        <legend class="px-1 text-sm font-semibold text-ink">{{ __('tenders.sections.internal') }}</legend>
        <p class="text-xs text-muted sm:col-span-2 lg:col-span-3">{{ __('tenders.internal_hint') }}</p>
        <div>
            <label for="h-budget" class="form-label">{{ __('tenders.fields.budget') }}</label>
            <input wire:model="header.budget" id="h-budget" type="text" inputmode="decimal" class="form-input">
            @error('header.budget') <p class="form-error">{{ $message }}</p> @enderror
        </div>
        <div>
            <label for="h-bcur" class="form-label">{{ __('tenders.fields.budget_currency') }}</label>
            <select wire:model="header.budget_currency" id="h-bcur" class="form-input">
                @foreach (config('tenders.currencies') as $currency) <option value="{{ $currency }}">{{ $currency }}</option> @endforeach
            </select>
        </div>
        <div>
            <label for="h-incumbent" class="form-label">{{ __('tenders.fields.incumbent') }}</label>
            <select wire:model="header.incumbent_counterparty_id" id="h-incumbent" class="form-input">
                <option value="">—</option>
                @foreach ($tender->invitations as $invitation) <option value="{{ $invitation->counterparty_id }}">{{ $invitation->counterparty?->name }}</option> @endforeach
            </select>
        </div>
    </fieldset>

    @if ($editable)
        <div class="flex justify-end"><button type="submit" class="btn btn-primary" data-action="save-header">{{ __('common.save') }}</button></div>
    @endif
</form>
