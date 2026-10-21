<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<body style="font-family: Arial, sans-serif; color: #111;">
    {{-- Portal login code (Spec 03 4.3): 10 minutes, single use, never share. --}}
    <p>{{ __('portal.mail.code_body', ['company' => $company]) }}</p>
    <p style="font-size: 24px; font-weight: bold; letter-spacing: 4px;">{{ $code }}</p>
    <p style="font-size: 12px; color: #555;">{{ __('portal.mail.code_warning') }}</p>
</body>
</html>
