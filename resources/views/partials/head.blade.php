@php
    // Runtime theme overrides (Settings > Appearance). Values are sanitized to #rrggbb in ThemeColor::current().
    $themeColors = \App\Enums\ThemeColor::current();
@endphp
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="{{ csrf_token() }}">
<meta name="theme-color" content="{{ $themeColors[\App\Enums\ThemeColor::Sidebar->value] }}">

<link rel="icon" href="{{ \App\Models\Setting::logoUrl() }}">

<title>{{ filled($title ?? null) ? $title.' · ' : '' }}{{ config('crm.name') }}</title>

@fonts
@vite(['resources/css/app.css', 'resources/js/app.js'])
<style id="theme-overrides" nonce="{{ Vite::cspNonce() }}">:root{ {{ \App\Enums\ThemeColor::cssDeclarations($themeColors) }} }</style>
@livewireStyles
