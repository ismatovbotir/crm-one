<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<body style="font-family: Arial, sans-serif; color: #111;">
    {{-- Verification code for a bid link (Spec 08 8.2): 10 minutes, single use. --}}
    <p>{{ __('bid_link.mail.code_body', ['number' => $tender->number, 'company' => $company]) }}</p>
    <p style="font-size: 24px; font-weight: bold; letter-spacing: 4px;">{{ $code }}</p>
    <p style="font-size: 12px; color: #555;">{{ __('bid_link.mail.code_warning') }}</p>
</body>
</html>
