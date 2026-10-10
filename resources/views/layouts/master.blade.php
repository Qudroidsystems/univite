<!doctype html>
<html lang="en" data-layout="vertical" data-sidebar="dark" data-sidebar-size="lg" data-preloader="disable" data-theme="default" data-topbar="light" data-bs-theme="light">

<head>
    <meta charset="utf-8">
    <title>{{ $pagetitle ?? 'Portal' }} | {{ \App\Support\Institution::get('name') }}</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="uv-layout" content="master">
    <meta name="uv-ajax" content="{{ Route::is('roles.*') || Route::is('permissions.*') ? 'off' : 'on' }}">
    <meta name="uv-ping" content="{{ route('ping', [], false) }}">
    <link href="{{ asset('theme/layouts/assets/libs/sweetalert2/sweetalert2.min.css') }}" rel="stylesheet" type="text/css">
    <link href="{{ asset('css/univite-app.css') }}?v={{ @filemtime(public_path('css/univite-app.css')) }}" rel="stylesheet">
    @include('partials.brand-head')

    <link rel="preconnect" href="https://fonts.googleapis.com/">
    <link rel="preconnect" href="https://fonts.gstatic.com/" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://unpkg.com/@phosphor-icons/web@2.0.3/src/bold/style.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">

    <link href="{{ asset('theme/layouts/assets/css/bootstrap.min.css') }}" rel="stylesheet">
    <link href="{{ asset('theme/layouts/assets/css/icons.min.css') }}" rel="stylesheet">
    <link href="{{ asset('theme/layouts/assets/css/app.min.css') }}" rel="stylesheet">
    <link href="{{ asset('theme/layouts/assets/css/custom.min.css') }}" rel="stylesheet">
    <script src="{{ asset('theme/layouts/assets/js/layout.js') }}"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>

    @if (Route::is('roles.*'))       @include('layouts.pages-assets.css.roles-list-css') @endif
    @if (Route::is('permissions.*')) @include('layouts.pages-assets.css.permission-list-css') @endif

    <link href="{{ asset('css/cb-ui.css') }}?v={{ @filemtime(public_path('css/cb-ui.css')) }}" rel="stylesheet">
    @stack('styles')
</head>

<body class="cb-ui">
<div id="layout-wrapper">

    {{-- ========== TOPBAR ========== --}}
    <header id="page-topbar">
        <div class="layout-width">
            <div class="navbar-header">
                <div class="d-flex align-items-center">
                    <button type="button" class="btn btn-sm px-3 fs-16 header-item vertical-menu-btn topnav-hamburger shadow-none" id="topnav-hamburger-icon">
                        <span class="hamburger-icon"><span></span><span></span><span></span></span>
                    </button>
                    @php($currentSemester = \App\Models\Semester::current())
                    @php($currentSession  = $currentSemester?->session ?? \App\Models\AcademicSession::current())
                    @if ($currentSession)
                        <span class="badge bg-primary-subtle text-primary fs-12 ms-2">
                            <i class="ri-calendar-event-line me-1"></i>{{ $currentSession->name }}@if($currentSemester) · {{ $currentSemester->name }}@endif
                        </span>
                    @endif
                </div>

                <div class="d-flex align-items-center">
                    <span id="uv-net" class="uv-net uv-net--unknown" role="status" aria-live="polite" title="Checking connection…"><i></i><i></i><i></i><i></i><span class="uv-net-label d-none d-md-inline">…</span></span>
                    @php($authUser = auth()->user())
                    <div class="dropdown ms-sm-3 header-item topbar-user">
                        <button type="button" class="btn shadow-none" data-bs-toggle="dropdown" aria-expanded="false">
                            <span class="d-flex align-items-center gap-2">
                                <span class="d-inline-flex align-items-center justify-content-center rounded-3 bg-primary text-white fw-semibold" style="width:38px;height:38px;">{{ $authUser->initials }}</span>
                                <span class="d-none d-xl-flex flex-column align-items-start">
                                    <span class="fw-medium fs-13">{{ $authUser->name }}</span>
                                    <span class="text-muted fs-11">{{ $authUser->roles->first()->name ?? 'User' }}</span>
                                </span>
                            </span>
                        </button>
                        <div class="dropdown-menu dropdown-menu-end">
                            <h6 class="dropdown-header">{{ $authUser->email }}</h6>
                            <form method="POST" action="{{ route('logout') }}" data-no-ajax>
                                @csrf
                                <button type="submit" class="dropdown-item text-danger"><i class="mdi mdi-logout me-2"></i>Logout</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </header>

    {{-- ========== SIDEBAR ========== --}}
    @include('layouts.sidebar')
    <div class="vertical-overlay"></div>

    {{-- ========== PAGE ========== --}}
    <div id="ajax-root">
    @yield('content')
    </div>

    <footer class="footer">
        <div class="container-fluid">
            <div class="row">
                <div class="col-sm-6">{{ now()->year }} © {{ \App\Support\Institution::get('name') }}@if (\App\Support\Institution::get('footer_text')) · {{ \App\Support\Institution::get('footer_text') }}@endif</div>
                <div class="col-sm-6"><div class="text-sm-end">Built by <a href="https://qudroids.com" target="_blank" rel="noopener" class="fw-semibold">Qudroid Systems</a></div></div>
            </div>
        </div>
    </footer>
</div>

<script src="{{ asset('theme/layouts/assets/libs/bootstrap/js/bootstrap.bundle.min.js') }}"></script>
<script src="{{ asset('theme/layouts/assets/js/app.js') }}"></script>
<script src="{{ asset('theme/layouts/assets/libs/simplebar/simplebar.min.js') }}"></script>
<script src="{{ asset('theme/layouts/assets/js/plugins.js') }}"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

@if (Route::is('roles.*'))       @include('layouts.pages-assets.js.role-list-js') @endif
@if (Route::is('permissions.*')) @include('layouts.pages-assets.js.permissions-list-js') @endif
<script src="{{ asset('theme/layouts/assets/libs/sweetalert2/sweetalert2.min.js') }}"></script>
<script src="{{ asset('js/univite-app.js') }}?v={{ @filemtime(public_path('js/univite-app.js')) }}"></script>
<div id="uv-page-scripts">
@stack('scripts')
</div>
</body>
</html>
