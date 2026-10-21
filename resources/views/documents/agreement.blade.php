{{--
    Printed agreement (Spec 06 section 12, DEC-10), rendered by DocumentRenderer in the document
    language. The body is the stored text of a sent version (immutable, with its fingerprint) or a
    live preview of a draft, already sanitized and escaped by TemplateRenderer. With a second
    language the stored texts print side by side. The details of the parties and the signature
    block come from the frozen variables snapshot; labels only from documents.*.
--}}
@php
    $renderer = app(\App\Services\Agreements\TemplateRenderer::class);
    $vars = $agreement->variables ?? [];
    $t = fn (string $key): string => $renderer->text($key, $vars, $documentLanguage);
    $parties = [
        ['title' => __('documents.agreement.seller_party'), 'name' => $t('company.legal_name'), 'address' => $t('company.address'), 'tax_id' => $t('company.tax_id'), 'vat' => $t('company.vat_code'), 'oked' => $t('company.oked'), 'bank' => $t('company_bank.name'), 'mfo' => $t('company_bank.mfo'), 'account' => $t('company_bank.account'), 'signer' => $agreement->ourSignatory?->full_name ?? $t('signatory.name'), 'position' => $agreement->ourSignatory?->position ?? $t('signatory.position')],
        ['title' => __('documents.agreement.buyer_party'), 'name' => $t('customer.legal_name'), 'address' => $t('customer.address'), 'tax_id' => $t('customer.tax_id'), 'vat' => $t('customer.vat_code'), 'oked' => $t('customer.oked'), 'bank' => $t('customer_bank.name'), 'mfo' => $t('customer_bank.mfo'), 'account' => $t('customer_bank.account'), 'signer' => $agreement->customer_signatory_name ?? $t('customer.signatory_name'), 'position' => $agreement->customer_signatory_position ?? $t('customer.signatory_position')],
    ];
@endphp
<article class="space-y-5 text-[12.5px] leading-snug text-black" data-agreement-document data-version="{{ $texts[0]['version'] ?? $agreement->version }}">
    <header class="flex items-start justify-between gap-6 border-b-2 border-black pb-3">
        <img src="{{ \App\Models\Setting::logoUrl() }}" alt="{{ $t('company.legal_name') }}" width="56" height="56" class="size-14 object-contain">
        <div class="text-right">
            <p class="font-semibold">{{ __('documents.number') }} {{ $agreement->number }}</p>
            @if ($agreement->kind === \App\Enums\AgreementKind::Addendum && $agreement->parent)
                <p>{{ __('documents.agreement.addendum_title', ['number' => \Illuminate\Support\Str::afterLast($agreement->number, '/'), 'agreement' => $agreement->parent->number]) }}</p>
            @endif
            @if ($agreement->renewalOf)
                <p>{{ __('documents.agreement.renewal_of', ['number' => $agreement->renewalOf->number]) }}</p>
            @endif
            @if ($agreement->order)
                <p>{{ __('documents.agreement.based_on_order', ['number' => $agreement->order->number]) }}</p>
            @endif
            @if (! $texts[0]['stored'])
                <p class="mt-1 border border-black px-2 py-0.5 text-[11px] font-semibold uppercase" data-draft-mark>{{ __('documents.agreement.draft_mark') }}</p>
            @endif
        </div>
    </header>

    <div @class(['grid gap-6', 'grid-cols-2' => count($texts) > 1])>
        @foreach ($texts as $text)
            <section class="agreement-text" lang="{{ $text['locale'] }}" data-text-locale="{{ $text['locale'] }}">{{ \App\Support\SafeHtml::agreement($text['html']) }}</section>
        @endforeach
    </div>

    <section class="print-avoid-break">
        <h2 class="mb-2 text-sm font-bold uppercase">{{ __('documents.agreement.details_of_parties') }}</h2>
        <div class="grid grid-cols-2 gap-6">
            @foreach ($parties as $party)
                <div class="space-y-0.5">
                    <p class="text-xs font-semibold tracking-wide uppercase">{{ $party['title'] }}</p>
                    <p class="font-semibold">{{ $party['name'] }}</p>
                    @if ($party['address'] !== '') <p>{{ __('documents.address') }}: {{ $party['address'] }}</p> @endif
                    @if ($party['tax_id'] !== '') <p>{{ __('documents.tax_id') }}: {{ $party['tax_id'] }}</p> @endif
                    @if ($party['vat'] !== '') <p>{{ __('documents.agreement.vat_code') }}: {{ $party['vat'] }}</p> @endif
                    @if ($party['oked'] !== '') <p>{{ __('documents.agreement.oked') }}: {{ $party['oked'] }}</p> @endif
                    @if ($party['bank'] !== '') <p>{{ __('documents.invoice.bank') }}: {{ $party['bank'] }}</p> @endif
                    @if ($party['mfo'] !== '') <p>{{ __('documents.invoice.bank_code') }}: {{ $party['mfo'] }}</p> @endif
                    @if ($party['account'] !== '') <p>{{ __('documents.invoice.account') }}: {{ $party['account'] }}</p> @endif
                </div>
            @endforeach
        </div>
    </section>

    <section class="print-avoid-break">
        <h2 class="mb-2 text-sm font-bold uppercase">{{ __('documents.agreement.signatures') }}</h2>
        <div class="grid grid-cols-2 gap-6">
            @foreach ($parties as $party)
                <div class="space-y-3">
                    <p class="text-xs font-semibold tracking-wide uppercase">{{ $party['title'] }}</p>
                    <p>{{ $party['position'] }} {{ $party['signer'] }}</p>
                    <div class="flex items-end gap-3"><span class="whitespace-nowrap">{{ __('documents.signature') }}:</span><span class="flex-1 border-b border-black"></span></div>
                    <p>{{ __('documents.stamp') }}</p>
                </div>
            @endforeach
        </div>
    </section>

    @if ($texts[0]['sha256'])
        <footer class="border-t border-black pt-2 text-[10px]" data-fingerprint>{{ __('documents.agreement.fingerprint', ['hash' => $texts[0]['sha256']]) }} · v{{ $texts[0]['version'] }}</footer>
    @endif
</article>
