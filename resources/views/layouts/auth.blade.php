<!doctype html>
<html lang="en" data-layout="vertical" data-sidebar="dark" data-sidebar-size="lg" data-preloader="disable" data-theme="default" data-topbar="light" data-bs-theme="light">
<head>
    <meta charset="utf-8">
    <title>@yield('title', 'Sign in') | {{ config('app.name') }}</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="shortcut icon" href="{{ asset('favicon.ico') }}">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="{{ asset('theme/layouts/assets/js/layout.js') }}"></script>
    <link href="{{ asset('theme/layouts/assets/css/bootstrap.min.css') }}" rel="stylesheet">
    <link href="{{ asset('theme/layouts/assets/css/icons.min.css') }}" rel="stylesheet">
    <link href="{{ asset('theme/layouts/assets/css/app.min.css') }}" rel="stylesheet">
    <link href="{{ asset('theme/layouts/assets/css/custom.min.css') }}" rel="stylesheet">
    <style>
        body { background: linear-gradient(135deg, #eef2ff 0%, #f8fafc 60%, #e0f2fe 100%); min-height: 100vh; }
        .auth-card { border: 0; border-radius: 16px; box-shadow: 0 12px 40px rgba(64, 81, 137, .12); }
        .brand-mark { width: 56px; height: 56px; border-radius: 14px; background: #405189; color: #fff; display: inline-flex; align-items: center; justify-content: center; font-size: 26px; font-weight: 700; }
    </style>
</head>
<body>
<div class="container">
    <div class="row justify-content-center align-items-center" style="min-height:100vh">
        <div class="col-md-8 col-lg-5 col-xl-4">
            <div class="text-center mb-4">
                <span class="brand-mark mb-2">U</span>
                <h4 class="mb-0 mt-2">{{ config('app.name') }}</h4>
                <p class="text-muted mb-0">University portal</p>
            </div>
            <div class="card auth-card">
                <div class="card-body p-4">
                    @yield('content')
                </div>
            </div>
            <p class="text-center text-muted fs-12 mt-4 mb-0">{{ now()->year }} © {{ config('app.name') }} · Built by Qudroid Systems</p>
        </div>
    </div>
</div>
<script src="{{ asset('theme/layouts/assets/libs/bootstrap/js/bootstrap.bundle.min.js') }}"></script>
</body>
</html>
