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
    <meta name="uv-lookups" content="{{ url('lookups') }}">
    <link href="{{ asset('theme/layouts/assets/libs/sweetalert2/sweetalert2.min.css') }}" rel="stylesheet" type="text/css">
    <link href="{{ asset('css/univite-app.css') }}?v={{ @filemtime(public_path('css/univite-app.css')) }}" rel="stylesheet">
    @include('partials.brand-head')

    <link rel="preconnect" href="https://fonts.googleapis.com/">
    <link rel="preconnect" href="https://fonts.gstatic.com/" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet" id="fontsLink">
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
    <link href="{{ asset('css/univite-shell.css') }}?v={{ @filemtime(public_path('css/univite-shell.css')) }}" rel="stylesheet">
    {{-- Apply the saved light/dark choice before the page paints. --}}
    <script>(function(){try{var m=localStorage.getItem('uv-theme')||'light';var d=m==='auto'?(matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light'):m;document.documentElement.setAttribute('data-bs-theme',d);document.documentElement.setAttribute('data-topbar',d==='dark'?'dark':'light');}catch(e){}})();</script>
    @stack('styles')
</head>

<body class="cb-ui">
<div id="layout-wrapper">

    {{-- ========== TOPBAR ========== --}}
    @php
        $authUser = auth()->user();
        $unread = $authUser->unreadNotifications()->latest()->limit(6)->get();
        $unreadCount = $authUser->unreadNotifications()->count();
        $avatar = $authUser->student?->photo_url;
        $currentSemester = \App\Models\Semester::current();
        $currentSession = $currentSemester?->session ?? \App\Models\AcademicSession::current();
    @endphp
    <header id="page-topbar">
        <div class="layout-width">
            <div class="navbar-header">
                <div class="d-flex align-items-center">
                    <button type="button" class="btn btn-sm px-3 fs-16 header-item vertical-menu-btn topnav-hamburger shadow-none" id="topnav-hamburger-icon" aria-label="Menu">
                        <span class="hamburger-icon"><span></span><span></span><span></span></span>
                    </button>
                    <div class="d-none d-md-inline-flex align-items-center position-relative">
                        <button type="button" id="spotlight-trigger" aria-label="Search">
                            <i class="ri-search-line"></i>
                            <span>Search everything…</span>
                            <span class="d-flex gap-1"><kbd>⌘</kbd><kbd>K</kbd></span>
                        </button>
                        <div class="search-tooltip">Press <kbd>⌘K</kbd> or <kbd>Ctrl+K</kbd> to search</div>
                    </div>
                    <button type="button" class="btn btn-icon btn-topbar btn-ghost-secondary rounded-circle d-md-none" id="spotlight-trigger-sm" aria-label="Search"><i class="ri-search-line fs-20"></i></button>
                    @if ($currentSession)
                        <span class="badge bg-primary-subtle text-primary fs-12 ms-2 d-none d-lg-inline-block">
                            <i class="ri-calendar-event-line me-1"></i>{{ $currentSession->name }}@if ($currentSemester) · {{ $currentSemester->name }}@endif
                        </span>
                    @endif
                </div>

                <div class="d-flex align-items-center gap-1">
                    <span id="uv-net" class="uv-net uv-net--unknown" role="status" aria-live="polite" title="Checking connection…"><i></i><i></i><i></i><i></i><span class="uv-net-label d-none d-md-inline">…</span></span>

                    <div class="position-relative" id="theme-toggle-wrapper">
                        <button type="button" id="theme-toggle-btn" class="btn btn-icon btn-topbar btn-ghost-secondary rounded-circle" aria-label="Light or dark">
                            <i id="theme-icon" class="ri-sun-line fs-20"></i>
                        </button>
                        <div id="theme-dropdown" class="uv-pop" style="display:none;min-width:170px;">
                            <a href="#" class="theme-mode-item uv-pop-item" data-mode="light"><i class="ri-sun-line"></i> Light</a>
                            <a href="#" class="theme-mode-item uv-pop-item" data-mode="dark"><i class="ri-moon-line"></i> Dark</a>
                            <a href="#" class="theme-mode-item uv-pop-item" data-mode="auto"><i class="ri-contrast-2-line"></i> Same as device</a>
                        </div>
                    </div>

                    <div class="position-relative" id="pn-wrapper">
                        <button type="button" id="pn-btn" class="btn btn-icon btn-topbar btn-ghost-secondary rounded-circle position-relative" aria-label="Notifications">
                            <i class="ri-notification-3-line fs-20"></i>
                            @if ($unreadCount)<span class="position-absolute topbar-badge fs-10 translate-middle badge rounded-pill bg-danger">{{ $unreadCount > 99 ? '99+' : $unreadCount }}</span>@endif
                        </button>
                        <div id="pn-dropdown" class="uv-pop" style="display:none;width:340px;max-width:92vw;padding:0;right:-60px;">
                            <div class="d-flex justify-content-between align-items-center px-3 py-2 border-bottom"><strong class="fs-14">Notifications</strong><a href="{{ route('notifications.index') }}" class="fs-12">See all</a></div>
                            <div style="max-height:360px;overflow:auto;">
                                @forelse ($unread as $n)
                                    <a href="{{ route('notifications.open', $n->id) }}" class="d-flex gap-2 px-3 py-2 text-decoration-none border-bottom uv-pn-item">
                                        <i class="ri-notification-badge-line fs-18 text-primary"></i>
                                        <span class="flex-grow-1"><span class="d-block fw-semibold fs-13">{{ $n->data['title'] ?? 'Notice' }}</span>
                                            <span class="d-block text-muted fs-12">{{ \Illuminate\Support\Str::limit($n->data['body'] ?? '', 110) }}</span>
                                            <span class="text-muted fs-11">{{ $n->created_at->diffForHumans() }}</span></span>
                                    </a>
                                @empty
                                    <div class="p-4 text-center text-muted fs-13">You are all caught up.</div>
                                @endforelse
                            </div>
                        </div>
                    </div>

                    <div class="position-relative ms-sm-2 header-item topbar-user" id="user-dropdown-wrapper">
                        <button type="button" id="user-menu-btn" class="btn shadow-none p-0 bg-transparent border-0" aria-label="Account">
                            <span class="d-flex align-items-center gap-2">
                                @if ($avatar)<img src="{{ $avatar }}" alt="" class="header-profile-user-enhanced" style="width:40px;height:40px;border-radius:10px;object-fit:cover;">
                                @else<span class="header-profile-user-enhanced d-inline-flex align-items-center justify-content-center fw-semibold text-white" style="width:40px;height:40px;border-radius:10px;background:#405189;">{{ $authUser->initials }}</span>@endif
                                <span class="d-none d-xl-flex flex-column align-items-start">
                                    <span class="fw-medium fs-13">{{ $authUser->name }}</span>
                                    <span class="text-muted fs-11">{{ $authUser->roles->first()->name ?? ($authUser->student ? 'Student' : 'User') }}</span>
                                </span>
                            </span>
                        </button>
                        <div id="user-dropdown" class="uv-pop" style="display:none;min-width:240px;padding:0;">
                            <div class="px-3 pt-3 pb-2"><h6 class="mb-0">Welcome back!</h6><small class="text-muted">{{ $authUser->email }}</small></div>
                            @if ($authUser->roles->isNotEmpty())
                                <div class="px-3 pb-2 border-bottom">
                                    <div class="text-muted text-uppercase mb-1" style="font-size:10px;">Your roles</div>
                                    <div class="d-flex flex-wrap gap-1">@foreach ($authUser->roles->pluck('name') as $roleName)<span class="uv-role-chip">{{ $roleName }}</span>@endforeach</div>
                                </div>
                            @endif
                            <a class="uv-pop-item" href="{{ route('notifications.index') }}"><i class="ri-notification-3-line"></i>Notifications</a>
                            <a class="uv-pop-item" href="{{ route('notifications.preferences') }}"><i class="ri-notification-badge-line"></i>Notification settings</a>
                            <div class="border-top"></div>
                            <form method="POST" action="{{ route('logout') }}" data-no-ajax>@csrf
                                <button type="submit" class="uv-pop-item text-danger w-100 border-0 bg-transparent text-start"><i class="ri-logout-box-r-line"></i>Sign out</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </header>

    {{-- ========== SIDEBAR ========== --}}
    @include('layouts.sidebar')
    <div class="vertical-overlay" id="vertical-overlay"></div>

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

<button type="button" class="btn btn-dark btn-icon" id="back-to-top" aria-label="Back to top"><i class="ri-arrow-up-line fs-20"></i></button>
@include('layouts.partials.spotlight')

<script src="{{ asset('theme/layouts/assets/libs/bootstrap/js/bootstrap.bundle.min.js') }}"></script>
<script src="{{ asset('theme/layouts/assets/js/app.js') }}"></script>
<script src="{{ asset('theme/layouts/assets/libs/simplebar/simplebar.min.js') }}"></script>
<script src="{{ asset('theme/layouts/assets/js/plugins.js') }}"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

@if (Route::is('roles.*'))       @include('layouts.pages-assets.js.role-list-js') @endif
@if (Route::is('permissions.*')) @include('layouts.pages-assets.js.permissions-list-js') @endif
<script src="{{ asset('theme/layouts/assets/libs/sweetalert2/sweetalert2.min.js') }}"></script>
<script src="{{ asset('js/univite-app.js') }}?v={{ @filemtime(public_path('js/univite-app.js')) }}"></script>
<script src="{{ asset('js/univite-shell.js') }}?v={{ @filemtime(public_path('js/univite-shell.js')) }}"></script>
<script src="{{ asset('js/univite-lookups.js') }}?v={{ @filemtime(public_path('js/univite-lookups.js')) }}"></script>
<div id="uv-page-scripts">
@stack('scripts')
</div>
</body>
</html>
