{{--
    Tender notice / RFQ (Spec 03 section 9, transport-tender skill section 4). Bidder-facing:
    whitelisted fields only (BR-T.12, AC-27): never budget, target prices, incumbent data, internal
    notes or site contacts. Labels from documents.* and tenders.vocab.*, values via $doc; deadlines
    in the business timezone with its UTC label (BR-T.13, AC-28).
--}}
@php
    $isTransport = $tender->type === \App\Enums\TenderType::Transport;
    $local = fn ($moment) => $moment ? $doc->dateTime($moment->setTimezone($timezone)).' ('.$timezoneLabel.')' : '—';
    $vocab = fn (string $group, ?string $value) => $value === null || $value === '' ? '—' : __('tenders.vocab.'.$group.'.'.$value);
    $tri = fn ($value) => $value === null ? '—' : ($value ? __('documents.tender_notice.yes') : __('documents.tender_notice.no'));
    $money = fn (?int $minor, ?string $currency) => $minor === null || ! $currency ? '—' : $doc->money(\App\Support\Money::of($minor, $currency)->toMajor(), $currency);
    $spec = fn ($s, string $field) => filled($s->{$field}) ? $s->{$field} : '—';
@endphp
<article class="space-y-5 text-[12.5px] leading-snug text-black" data-tender-notice>
    <header class="flex items-start justify-between gap-6 border-b-2 border-black pb-4">
        <div>
            <p class="text-base font-semibold">{{ $seller['name'] }}</p>
            @if ($seller['address']) <p>{{ $seller['address'] }}</p> @endif
            @if ($seller['phone']) <p>{{ __('documents.phone') }}: {{ $seller['phone'] }}</p> @endif
        </div>
        <div class="text-right">
            <h1 class="text-xl font-bold tracking-tight uppercase">{{ __('documents.tender_notice.title') }}</h1>
            <p class="mt-1">{{ __('documents.number') }} <span class="font-semibold">{{ $tender->number }}</span>@if ($tender->version > 1) · {{ __('documents.tender_notice.version', ['version' => $tender->version]) }}@endif</p>
            <p>{{ __('documents.tender_notice.type_'.$tender->type->value) }}</p>
            @if ($invitee) <p>{{ __('documents.tender_notice.to', ['name' => $invitee]) }}</p> @endif
        </div>
    </header>

    <section>
        <h2 class="text-sm font-bold">{{ $tender->title }}</h2>
        @if ($tender->description)<p class="mt-1 whitespace-pre-line">{{ $tender->description }}</p>@endif
        <table class="mt-2 w-full border-collapse">
            <tbody>
                <tr><th scope="row" class="w-1/3 border border-black px-2 py-1 text-left font-semibold">{{ __('documents.tender_notice.clarification_deadline') }}</th><td class="border border-black px-2 py-1">{{ $local($tender->clarification_deadline_at) }}</td></tr>
                <tr><th scope="row" class="border border-black px-2 py-1 text-left font-semibold">{{ __('documents.tender_notice.bids_due') }}</th><td class="border border-black px-2 py-1 font-semibold" data-bids-due>{{ $local($tender->bids_due_at) }}</td></tr>
                <tr><th scope="row" class="border border-black px-2 py-1 text-left font-semibold">{{ __('documents.tender_notice.bid_validity') }}</th><td class="border border-black px-2 py-1">{{ trans_choice('documents.tender_notice.days', $tender->bid_validity_days, ['count' => $tender->bid_validity_days]) }}</td></tr>
                <tr><th scope="row" class="border border-black px-2 py-1 text-left font-semibold">{{ __('documents.tender_notice.currencies') }}</th><td class="border border-black px-2 py-1">{{ implode(', ', (array) $tender->accepted_currencies) }} · {{ __('documents.tender_notice.evaluation_currency', ['currency' => $tender->evaluation_currency]) }}</td></tr>
                <tr><th scope="row" class="border border-black px-2 py-1 text-left font-semibold">{{ __('documents.tender_notice.payment_terms') }}</th><td class="border border-black px-2 py-1">{{ $tender->payment_terms ?? '—' }}</td></tr>
                @unless ($isTransport)
                    <tr><th scope="row" class="border border-black px-2 py-1 text-left font-semibold">{{ __('documents.tender_notice.incoterm') }}</th><td class="border border-black px-2 py-1">{{ trim($tender->incoterm.' '.$tender->incoterm_place) ?: '—' }} (Incoterms® 2020)</td></tr>
                    <tr><th scope="row" class="border border-black px-2 py-1 text-left font-semibold">{{ __('documents.tender_notice.delivery_place') }}</th><td class="border border-black px-2 py-1">{{ $tender->delivery_address ?: ($tender->deliveryWarehouse?->name ?? '—') }}</td></tr>
                    @if ($tender->required_certificates)<tr><th scope="row" class="border border-black px-2 py-1 text-left font-semibold">{{ __('documents.tender_notice.certificates') }}</th><td class="border border-black px-2 py-1 whitespace-pre-line">{{ $tender->required_certificates }}</td></tr>@endif
                    @if ($tender->warranty_terms)<tr><th scope="row" class="border border-black px-2 py-1 text-left font-semibold">{{ __('documents.tender_notice.warranty') }}</th><td class="border border-black px-2 py-1">{{ $tender->warranty_terms }}</td></tr>@endif
                @endunless
            </tbody>
        </table>
    </section>

    @foreach ($tender->lots as $lot)
        <section class="break-inside-avoid">
            <h2 class="text-sm font-bold">{{ __('documents.tender_notice.lot', ['number' => $lot->number]) }}: {{ $lot->title }}</h2>
            @if ($lot->description)<p class="whitespace-pre-line">{{ $lot->description }}</p>@endif

            @if ($isTransport && $lot->transportSpec)
                @php($s = $lot->transportSpec)
                <table class="mt-1 w-full border-collapse">
                    <tbody>
                        <tr><th scope="row" class="w-1/3 border border-black px-2 py-1 text-left font-semibold">{{ __('documents.tender_notice.route') }}</th><td class="border border-black px-2 py-1">{{ collect([$s->origin_country, $s->origin_city, $s->origin_address])->filter()->implode(', ') }} → {{ collect([$s->destination_country, $s->destination_city, $s->destination_address])->filter()->implode(', ') }}</td></tr>
                        <tr><th scope="row" class="border border-black px-2 py-1 text-left font-semibold">{{ __('documents.tender_notice.border') }}</th><td class="border border-black px-2 py-1">{{ $vocab('border_policy', $s->border_policy) }}@if ($s->border_crossing) · {{ $s->border_crossing }}@endif @if ($s->clearance_place) · {{ __('documents.tender_notice.clearance_place') }}: {{ $s->clearance_place }}@endif</td></tr>
                        <tr><th scope="row" class="border border-black px-2 py-1 text-left font-semibold">{{ __('documents.tender_notice.dates') }}</th><td class="border border-black px-2 py-1">{{ __('documents.tender_notice.readiness') }}: {{ $doc->date($s->readiness_date) ?: '—' }} · {{ __('documents.tender_notice.pickup') }}: {{ $doc->date($s->pickup_from) }}–{{ $doc->date($s->pickup_to) }} · {{ __('documents.tender_notice.delivery_by') }}: {{ $doc->date($s->delivery_to) ?: '—' }}@if ($s->max_transit_days) · {{ __('documents.tender_notice.max_transit', ['days' => $s->max_transit_days]) }}@endif</td></tr>
                        <tr><th scope="row" class="border border-black px-2 py-1 text-left font-semibold">{{ __('documents.tender_notice.equipment') }}</th><td class="border border-black px-2 py-1">{{ $vocab('mode', $s->mode) }} · {{ $vocab('load_type', $s->load_type) }} · {{ $spec($s, 'vehicle_type') }} × {{ $s->units_count }} · {{ __('documents.tender_notice.transshipment') }}: {{ $tri($s->transshipment_allowed) }} · {{ __('documents.tender_notice.partial') }}: {{ $tri($s->partial_allowed) }}</td></tr>
                        <tr><th scope="row" class="border border-black px-2 py-1 text-left font-semibold">{{ __('documents.tender_notice.cargo') }}</th><td class="border border-black px-2 py-1">{{ $spec($s, 'cargo_description') }}<br>{{ __('documents.tender_notice.hs_codes') }}: {{ implode(', ', (array) $s->hs_codes) ?: '—' }} · {{ __('documents.tender_notice.weights', ['gross' => $s->gross_kg !== null ? $doc->quantity((string) $s->gross_kg) : '—', 'net' => $s->net_kg !== null ? $doc->quantity((string) $s->net_kg) : '—', 'volume' => $s->volume_m3 !== null ? $doc->quantity((string) $s->volume_m3) : '—']) }} · {{ __('documents.tender_notice.packages', ['count' => $s->packages_count ?? '—', 'type' => $s->package_type ?? '—']) }}@if ($s->dimensions) · {{ $s->dimensions }}@endif · {{ __('documents.tender_notice.stackable') }}: {{ $tri($s->stackable) }}<br>{{ __('documents.tender_notice.cargo_value') }}: {{ $money($s->cargo_value_minor, $s->cargo_currency) }} · {{ __('documents.tender_notice.dangerous') }}: {{ $tri($s->is_dangerous) }}@if ($s->is_dangerous) (UN {{ $s->un_number }}, {{ $s->adr_class }}, {{ $s->packing_group }})@endif · {{ __('documents.tender_notice.temperature') }}: {{ $tri($s->is_temperature_controlled) }}@if ($s->is_temperature_controlled) ({{ $s->temp_min_c }}…{{ $s->temp_max_c }} °C)@endif · {{ __('documents.tender_notice.oversize') }}: {{ $tri($s->is_oversize) }}@if ($s->special_handling)<br>{{ $s->special_handling }}@endif</td></tr>
                        <tr><th scope="row" class="border border-black px-2 py-1 text-left font-semibold">{{ __('documents.tender_notice.handling') }}</th><td class="border border-black px-2 py-1">{{ __('documents.tender_notice.loading') }}: {{ $vocab('handling_method', $s->loading_method) }}, {{ $vocab('handled_by', $s->loaded_by) }}@if ($s->loading_equipment) ({{ $s->loading_equipment }})@endif · {{ __('documents.tender_notice.unloading') }}: {{ $vocab('handling_method', $s->unloading_method) }}, {{ $vocab('handled_by', $s->unloaded_by) }}@if ($s->unloading_equipment) ({{ $s->unloading_equipment }})@endif<br>{{ __('documents.tender_notice.free_time', ['loading' => $s->free_loading_hours ?? '—', 'customs' => $s->free_customs_hours ?? '—', 'unloading' => $s->free_unloading_hours ?? '—']) }}@if ($s->isContainer()) · {{ __('documents.tender_notice.container', ['owner' => $vocab('container_owner', $s->container_owner), 'days' => $s->container_free_days ?? '—', 'place' => $s->container_return_location ?? '—']) }}@endif</td></tr>
                        <tr><th scope="row" class="border border-black px-2 py-1 text-left font-semibold">{{ __('documents.tender_notice.responsibility') }}</th><td class="border border-black px-2 py-1">{{ trim($s->incoterm.' '.$s->incoterm_place) ?: '—' }} (Incoterms® 2020) · {{ __('documents.tender_notice.export_clearance') }}: {{ $vocab('clearance_by', $s->export_clearance_by) }} · {{ __('documents.tender_notice.import_clearance') }}: {{ $vocab('clearance_by', $s->import_clearance_by) }}@if ($s->importer_of_record) · {{ __('documents.tender_notice.importer') }}: {{ $s->importer_of_record }}@endif
                            @if ($s->charges_payer)<br>@foreach ((array) $s->charges_payer as $charge => $payer){{ __('tenders.vocab.payer_charges.'.$charge) }}: {{ $vocab('payer', $payer) }}@if (! $loop->last); @endif @endforeach @endif
                            <br>{{ __('documents.tender_notice.insurance') }}: {{ $vocab('insurance_by', $s->insurance_by) }}@if ($s->insured_value_minor) · {{ $money($s->insured_value_minor, $s->cargo_currency) }}@endif @if ($s->insurance_cover) · {{ $s->insurance_cover }}@endif</td></tr>
                        <tr><th scope="row" class="border border-black px-2 py-1 text-left font-semibold">{{ __('documents.tender_notice.documents') }}</th><td class="border border-black px-2 py-1">{{ collect((array) $s->required_documents)->map(fn ($d) => __('tenders.vocab.documents.'.$d))->implode(', ') ?: '—' }}<br>{{ __('documents.tender_notice.driver_documents') }}: {{ collect((array) $s->driver_vehicle_documents)->map(fn ($d) => __('tenders.vocab.driver_documents.'.$d))->implode(', ') ?: '—' }}@if ($s->tracking_requirement)<br>{{ __('documents.tender_notice.tracking') }}: {{ $s->tracking_requirement }}@endif</td></tr>
                        @if ($s->penalties || $s->late_penalty_bp_per_day || $s->fuel_adjustment || $s->force_majeure)
                            <tr><th scope="row" class="border border-black px-2 py-1 text-left font-semibold">{{ __('documents.tender_notice.sla') }}</th><td class="border border-black px-2 py-1">@if ($s->penalties){{ $s->penalties }}<br>@endif @if ($s->late_penalty_bp_per_day){{ __('documents.tender_notice.late_penalty', ['percent' => $doc->percent(bcdiv((string) $s->late_penalty_bp_per_day, '100', 2))]) }}@if ($s->penalty_cap_bp) · {{ __('documents.tender_notice.penalty_cap', ['percent' => $doc->percent(bcdiv((string) $s->penalty_cap_bp, '100', 2))]) }}@endif<br>@endif @if ($s->fuel_adjustment){{ __('documents.tender_notice.fuel') }}: {{ $s->fuel_adjustment }}<br>@endif @if ($s->force_majeure){{ __('documents.tender_notice.force_majeure') }}: {{ $s->force_majeure }}@endif</td></tr>
                        @endif
                    </tbody>
                </table>
                <p class="mt-1">{{ __('documents.tender_notice.bid_form_transport') }}</p>
            @else
                <table class="mt-1 w-full border-collapse">
                    <thead>
                        <tr class="text-left text-xs font-semibold">
                            <th scope="col" class="border border-black px-2 py-1">{{ __('documents.line_no') }}</th>
                            <th scope="col" class="border border-black px-2 py-1">{{ __('documents.item') }}</th>
                            <th scope="col" class="border border-black px-2 py-1 text-right">{{ __('documents.qty') }}</th>
                            <th scope="col" class="border border-black px-2 py-1">{{ __('documents.tender_notice.required_by') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($lot->items as $item)
                            <tr class="align-top">
                                <td class="border border-black px-2 py-1">{{ $item->position }}</td>
                                <td class="border border-black px-2 py-1"><span class="font-semibold">{{ $item->name }}</span>@if ($item->sku) <span class="text-[11px]">{{ $item->sku }}</span>@endif @if ($item->specification)<br><span class="whitespace-pre-line">{{ $item->specification }}</span>@endif @if ($item->alternatives_allowed)<br>{{ __('documents.tender_notice.or_equivalent') }}@endif</td>
                                <td class="border border-black px-2 py-1 text-right whitespace-nowrap">{{ $doc->quantity((string) $item->quantity) }} {{ $item->unit_code }}</td>
                                <td class="border border-black px-2 py-1">{{ $doc->date($item->required_by) ?: '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <p class="mt-1">{{ __('documents.tender_notice.bid_form_purchase') }}</p>
            @endif
        </section>
    @endforeach

    <section class="break-inside-avoid">
        <h2 class="text-sm font-bold">{{ __('documents.tender_notice.criteria') }}</h2>
        <p>{{ $tender->criteria->map(fn ($c) => $c->displayLabel().' '.$doc->percent(bcdiv((string) $c->weight_bp, '100', 2)))->implode('; ') }}</p>
        @if ($tender->requirements->isNotEmpty())
            <ul class="mt-1 list-disc pl-5">
                @foreach ($tender->requirements as $requirement)
                    <li>{{ $requirement->text }}@if ($requirement->is_knockout) ({{ __('documents.tender_notice.knockout') }})@endif</li>
                @endforeach
            </ul>
        @endif
    </section>

    @if ($tender->questions->isNotEmpty())
        <section class="break-inside-avoid">
            <h2 class="text-sm font-bold">{{ __('documents.tender_notice.clarifications') }}</h2>
            <ol class="list-decimal pl-5">
                @foreach ($tender->questions as $question)
                    <li><span class="font-semibold">{{ $question->question }}</span><br>{{ $question->answer }}</li>
                @endforeach
            </ol>
        </section>
    @endif

    @if ($tender->addenda->isNotEmpty())
        <section class="break-inside-avoid">
            <h2 class="text-sm font-bold">{{ __('documents.tender_notice.addenda') }}</h2>
            <ul class="list-disc pl-5">
                @foreach ($tender->addenda as $addendum)
                    <li>{{ __('documents.tender_notice.version', ['version' => $addendum->version]) }}: {{ $addendum->summary }}@if ($addendum->new_bids_due_at) · {{ __('documents.tender_notice.new_due', ['at' => $local($addendum->new_bids_due_at)]) }}@endif</li>
                @endforeach
            </ul>
        </section>
    @endif

    <section class="break-inside-avoid border-2 border-black p-3" data-important-conditions>
        <h2 class="text-sm font-bold uppercase">{{ __('documents.tender_notice.important') }}</h2>
        <ol class="mt-1 list-decimal space-y-0.5 pl-5">
            <li>{{ __('documents.tender_notice.conditions.communications', ['deadline' => $local($tender->clarification_deadline_at)]) }}</li>
            <li>{{ __('documents.tender_notice.conditions.deadline', ['deadline' => $local($tender->bids_due_at)]) }}</li>
            <li>{{ __('documents.tender_notice.conditions.prices', ['currencies' => implode(', ', (array) $tender->accepted_currencies)]) }}</li>
            @if ($isTransport)
                <li>{{ __('documents.tender_notice.conditions.free_time') }}</li>
                <li>{{ __('documents.tender_notice.conditions.vehicle_data') }}</li>
            @endif
            <li>{{ __('documents.tender_notice.conditions.subcontracting') }}</li>
            <li>{{ __('documents.tender_notice.conditions.claims') }}</li>
            <li>{{ __('documents.tender_notice.conditions.validity', ['days' => $tender->bid_validity_days]) }}</li>
        </ol>
    </section>
</article>
