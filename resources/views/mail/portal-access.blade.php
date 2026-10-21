<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<body style="font-family: Arial, sans-serif; color: #111;">
    {{-- Portal access notice (Spec 03 4.7): no password; sign in with a one-time code. --}}
    <p>{{ __('portal.mail.access_body', ['company' => $company, 'customer' => $customer]) }}</p>
    <p><a href="{{ $url }}">{{ __('portal.mail.access_open') }}</a></p>
    <p style="font-size: 12px; color: #555;">{{ __('portal.mail.access_hint') }}</p>
</body>
</html>
