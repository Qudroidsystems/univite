<!doctype html>
<html lang="en" data-layout="vertical" data-sidebar="dark" data-sidebar-size="lg" data-preloader="disable" data-theme="default" data-topbar="light" data-bs-theme="light">
<head>
    <meta charset="utf-8">
    <title>@yield('title', 'Apply') | {{ \App\Support\Institution::get('name') }}</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="uv-layout" content="public">
    <meta name="uv-ajax" content="on">
    <meta name="uv-ping" content="{{ route('ping', [], false) }}">
    <link href="{{ asset('theme/layouts/assets/libs/sweetalert2/sweetalert2.min.css') }}" rel="stylesheet" type="text/css">
    <link href="{{ asset('css/univite-app.css') }}?v={{ @filemtime(public_path('css/univite-app.css')) }}" rel="stylesheet">
    @include('partials.brand-head')
    @include('partials.appearance-head')
    <script src="{{ asset('theme/layouts/assets/js/layout.js') }}"></script>
    <link href="{{ asset('theme/layouts/assets/css/bootstrap.min.css') }}" rel="stylesheet">
    <link href="{{ asset('theme/layouts/assets/css/icons.min.css') }}" rel="stylesheet">
    <link href="{{ asset('theme/layouts/assets/css/app.min.css') }}" rel="stylesheet">
    <link href="{{ asset('theme/layouts/assets/css/custom.min.css') }}" rel="stylesheet">
    <link href="{{ asset('css/univite-forms.css') }}?v={{ @filemtime(public_path('css/univite-forms.css')) }}" rel="stylesheet">
    <link href="{{ asset('css/univite-appearance.css') }}?v={{ @filemtime(public_path('css/univite-appearance.css')) }}" rel="stylesheet">
    <style>
        body { background: linear-gradient(135deg, #eef2ff 0%, #f8fafc 60%, #e0f2fe 100%); min-height: 100vh; }
        .pub-card { border: 0; border-radius: 14px; box-shadow: 0 8px 30px rgba(64, 81, 137, .10); }
        .brand-mark { width: 40px; height: 40px; border-radius: 10px; background: #405189; color: #fff; display: inline-flex; align-items: center; justify-content: center; font-size: 20px; font-weight: 700; }
        @media print { body { background: #fff; } .d-print-none { display: none !important; } .pub-card { box-shadow: none; border: 1px solid #ddd; } }
    </style>
</head>
<body>
<div id="ajax-root">
<nav class="navbar bg-white border-bottom d-print-none">
    <div class="container d-flex justify-content-between">
        <a href="{{ url('/') }}" class="d-flex align-items-center gap-2 text-decoration-none text-body">@include('partials.brand-mark', ['size' => 40])<span class="fw-semibold fs-5">{{ \App\Support\Institution::get('name') }}</span></a>
        <div class="d-flex gap-2 align-items-center">
            @if (session('applicant_id'))
                <a href="{{ route('apply.dashboard') }}" class="btn btn-sm btn-soft-primary">My application</a>
                <form method="POST" action="{{ route('apply.logout') }}" data-no-ajax>@csrf<button class="btn btn-sm btn-light" type="submit">Sign out</button></form>
            @else
                <a href="{{ route('apply.register') }}" class="btn btn-sm btn-soft-primary">Apply</a>
                <a href="{{ route('apply.login') }}" class="btn btn-sm btn-light">Continue my application</a>
                <a href="{{ route('login') }}" class="btn btn-sm btn-light">Student / staff sign in</a>
            @endif
        </div>
    </div>
</nav>
<div class="container py-4">
    @yield('content')
    <p class="text-center text-muted fs-12 mt-4 mb-0 d-print-none">{{ now()->year }} © {{ \App\Support\Institution::get('name') }}</p>
</div>
</div>
<script src="{{ asset('theme/layouts/assets/libs/bootstrap/js/bootstrap.bundle.min.js') }}"></script>
<script src="{{ asset('theme/layouts/assets/libs/sweetalert2/sweetalert2.min.js') }}"></script>
<script src="{{ asset('js/univite-app.js') }}?v={{ @filemtime(public_path('js/univite-app.js')) }}"></script>
<script src="{{ asset('js/univite-nav.js') }}?v={{ @filemtime(public_path('js/univite-nav.js')) }}"></script>
<script src="{{ asset('js/univite-select.js') }}?v={{ @filemtime(public_path('js/univite-select.js')) }}"></script>
<div id="uv-page-scripts">
@stack('scripts')
</div>
</body>
</html>
