<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ __('integration.docs.title') }} · {{ config('app.name') }}</title>
    {{-- Self-hosted Swagger UI (public/vendor/swagger-ui, Apache-2.0); no CDN, no inline script. --}}
    <link rel="stylesheet" href="{{ asset('vendor/swagger-ui/swagger-ui.css') }}">
    <link rel="stylesheet" href="{{ asset('vendor/swagger-ui/crm-api-docs.css') }}">
</head>
<body>
    <header class="crm-api-docs-header">
        <a href="{{ route('settings.integrations') }}">&larr; {{ __('integration.docs.back') }}</a>
        <span>{{ __('integration.docs.read_only') }}</span>
        <a href="{{ route('api-docs.json') }}">openapi.json</a>
        <a href="{{ route('api-docs.yaml') }}">openapi.yaml</a>
    </header>
    <main id="swagger-ui" data-spec-url="{{ $specUrl }}"></main>
    <script src="{{ asset('vendor/swagger-ui/swagger-ui-bundle.js') }}"></script>
    <script src="{{ asset('vendor/swagger-ui/crm-api-docs.js') }}"></script>
</body>
</html>
