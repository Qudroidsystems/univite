{{-- Favicon and the institution's main colour; included in every layout's <head>. --}}
@php $I = \App\Support\Institution::class; $icon = $I::image('favicon') ?? $I::image('logo'); @endphp
<link rel="shortcut icon" href="{{ $icon ?? asset('favicon.ico') }}">
@if ($I::get('primary_color') && strtolower($I::get('primary_color')) !== '#405189')
    <style>:root, [data-bs-theme=light], [data-bs-theme=dark] { --vz-primary: {{ $I::get('primary_color') }}; --vz-primary-rgb: {{ $I::primaryRgb() }}; --vz-link-color: {{ $I::get('primary_color') }}; } .btn-primary { --vz-btn-bg: {{ $I::get('primary_color') }}; --vz-btn-border-color: {{ $I::get('primary_color') }}; } .brand-mark { background: {{ $I::get('primary_color') }} !important; }</style>
@endif
