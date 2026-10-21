{{--
    Payment reminder letter (Spec 06 section 12): the amounts due or overdue per currency with the
    order, due date and days overdue. Labels from documents.*, values via $doc. No bank details.
--}}
<article class="mx-auto max-w-2xl space-y-5 text-[12.5px] leading-snug text-black" data-reminder-document>
    <header class="flex items-start justify-between gap-6 border-b-2 border-black pb-4">
        <div>
            <p class="text-base font-semibold">{{ $seller['name'] }}</p>
            @if ($seller['address']) <p>{{ $seller['address'] }}</p> @endif
            @if ($seller['tax_id']) <p>{{ __('documents.tax_id') }}: {{ $seller['tax_id'] }}</p> @endif
        </div>
        <div class="text-right">
            <h1 class="text-xl font-bold tracking-tight uppercase">{{ __('documents.reminder.title') }}</h1>
            <p>{{ __('documents.date') }}: {{ $doc->date($date) }}</p>
        </div>
    </header>

    <section>
        <p class="font-semibold">{{ $customer->legal_name ?: $customer->name }}</p>
        @if ($customer->tax_id) <p>{{ __('documents.tax_id') }}: {{ $customer->tax_id }}</p> @endif
    </section>

    <p>{{ __('documents.reminder.intro') }}</p>

    @forelse ($rows as $currency => $items)
        <table class="w-full border-collapse" data-reminder-currency="{{ $currency }}">
            <thead>
                <tr class="text-left text-xs font-semibold">
                    <th scope="col" class="border border-black px-2 py-1.5">{{ __('documents.reminder.order') }}</th>
                    <th scope="col" class="border border-black px-2 py-1.5">{{ __('documents.reminder.due_on') }}</th>
                    <th scope="col" class="border border-black px-2 py-1.5 text-right">{{ __('documents.reminder.outstanding') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($items as $row)
                    <tr>
                        <td class="border border-black px-2 py-1.5">{{ $row['order']->number }}</td>
                        <td class="border border-black px-2 py-1.5">{{ $doc->date($row['due_on']) }}@if ($row['days_overdue'] > 0) · {{ __('documents.reminder.days_overdue', ['days' => $row['days_overdue']]) }}@endif</td>
                        <td class="border border-black px-2 py-1.5 text-right">{{ $doc->money(\App\Support\Money::of($row['outstanding'], $currency)->toMajor(), $currency) }}</td>
                    </tr>
                @endforeach
                <tr class="font-semibold">
                    <td class="border border-black px-2 py-1.5" colspan="2">{{ __('documents.reminder.total') }} ({{ $currency }})</td>
                    <td class="border border-black px-2 py-1.5 text-right">{{ $doc->money(\App\Support\Money::of(array_sum(array_column($items, 'outstanding')), $currency)->toMajor(), $currency) }}</td>
                </tr>
            </tbody>
        </table>
    @empty
        <p class="text-muted" data-reminder-empty>{{ __('documents.reminder.nothing_due') }}</p>
    @endforelse

    <p class="pt-2 text-xs">{{ __('documents.reminder.ignore_if_paid') }}</p>
</article>
