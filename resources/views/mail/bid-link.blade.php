<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<body style="font-family: Arial, sans-serif; color: #111;">
    {{-- Bid link e-mail (Spec 08 8.2). Contains the link once; no amounts, no other participants. --}}
    <p>{{ __('bid_link.mail.greeting', ['name' => $counterparty?->name]) }}</p>
    <p>{{ __('bid_link.mail.invitation_body', ['company' => $company, 'number' => $tender->number, 'title' => $tender->title, 'deadline' => $deadline]) }}</p>
    <p><a href="{{ $url }}">{{ __('bid_link.mail.open') }}</a></p>
    <p style="font-size: 12px; color: #555;">{{ __('bid_link.mail.do_not_forward') }}</p>
</body>
</html>
