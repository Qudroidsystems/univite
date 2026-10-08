<!doctype html>
<html lang="en" data-layout="vertical" data-sidebar="dark" data-sidebar-size="lg" data-preloader="disable" data-theme="default" data-topbar="light" data-bs-theme="light">
<head>
    <meta charset="utf-8">
    <title>@yield('title', 'Apply') | {{ config('app.name') }}</title>
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
        .pub-card { border: 0; border-radius: 14px; box-shadow: 0 8px 30px rgba(64, 81, 137, .10); }
        .brand-mark { width: 40px; height: 40px; border-radius: 10px; background: #405189; color: #fff; display: inline-flex; align-items: center; justify-content: center; font-size: 20px; font-weight: 700; }
        @media print { body { background: #fff; } .d-print-none { display: none !important; } .pub-card { box-shadow: none; border: 1px solid #ddd; } }
    </style>
</head>
<body>
<nav class="navbar bg-white border-bottom d-print-none">
    <div class="container d-flex justify-content-between">
        <a href="{{ url('/') }}" class="d-flex align-items-center gap-2 text-decoration-none text-body"><span class="brand-mark">U</span><span class="fw-semibold fs-5">{{ config('app.name') }}</span></a>
        <div class="d-flex gap-2 align-items-center">
            @if (session('applicant_id'))
                <a href="{{ route('apply.dashboard') }}" class="btn btn-sm btn-soft-primary">My application</a>
                <form method="POST" action="{{ route('apply.logout') }}">@csrf<button class="btn btn-sm btn-light" type="submit">Sign out</button></form>
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
    <p class="text-center text-muted fs-12 mt-4 mb-0 d-print-none">{{ now()->year }} © {{ config('app.name') }}</p>
</div>
<script src="{{ asset('theme/layouts/assets/libs/bootstrap/js/bootstrap.bundle.min.js') }}"></script>
@stack('scripts')
</body>
</html>
