@props(['offer'])

{{-- Selected total when choices are complete, else the price range (alternatives never added together). --}}
@if ($offer->selection_complete || $offer->range_min_gross_minor === $offer->range_max_gross_minor)
    <span class="whitespace-nowrap tabular-nums">{{ $offer->money($offer->selection_complete ? $offer->selected_gross_minor : $offer->range_min_gross_minor)->format() }}</span>
@else
    <span class="whitespace-nowrap tabular-nums" title="{{ __('offers.range_hint') }}">{{ __('offers.range', ['min' => $offer->money($offer->range_min_gross_minor)->format(), 'max' => $offer->money($offer->range_max_gross_minor)->format()]) }}</span>
@endif
