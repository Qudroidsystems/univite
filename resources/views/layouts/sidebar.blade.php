@php
    $u = auth()->user();
    $on = fn (string ...$patterns) => request()->routeIs(...$patterns);

    // [label, route name, permission, route pattern]
    $academic = [
        ['Academic Sessions', 'academics.sessions.index',    'View academic sessions',    'academics.sessions.*'],
        ['Semesters',         'academics.semesters.index',   'View semesters',   'academics.semesters.*'],
        ['Faculties',         'academics.faculties.index',   'View faculties',    'academics.faculties.*'],
        ['Departments',       'academics.departments.index', 'View departments', 'academics.departments.*'],
        ['Programmes',        'academics.programmes.index',  'View programmes',  'academics.programmes.*'],
        ['Courses',           'academics.courses.index',     'View courses',     'academics.courses.*'],
        ['Curriculum',        'academics.curriculum.index',  'View programme curriculum', 'academics.curriculum.*'],
    ];
    $students = [
        ['Applicants',           'applicants.index',        'View applicants',           'applicants.*'],
        ['Students',             'students.index',          'View students',             'students.index', 'students.show'],
        ['Matric Number Format', 'students.settings',       'View matric number format', 'students.settings*'],
    ];
    $bills = [
        ['Bill Items',   'bills.items.index',     'View bill items', 'bills.items.*'],
        ['School Bills', 'bills.structure.index', 'View school bills',      'bills.structure.*'],
    ];
    $access = [
        ['Users',       'users.index',       'View users',       'users.*'],
        ['Roles',       'roles.index',       'View roles',       'roles.*'],
        ['Permissions', 'permissions.index', 'View permissions', 'permissions.*'],
    ];

    $groups = [
        ['id' => 'sidebarAcademic', 'label' => 'Academic Setup', 'icon' => 'ri-graduation-cap-line', 'items' => $academic],
        ['id' => 'sidebarStudents', 'label' => 'Admissions & Students', 'icon' => 'ri-user-star-line', 'items' => $students],
        ['id' => 'sidebarBills',    'label' => 'School Bills',   'icon' => 'ri-bill-line',           'items' => $bills],
        ['id' => 'sidebarAccess',   'label' => 'Users & Access', 'icon' => 'ri-shield-user-line',    'items' => $access],
    ];
@endphp

<div class="app-menu navbar-menu">
    <div class="navbar-brand-box">
        <a href="{{ route('dashboard') }}" class="logo">
            <span class="logo-sm"><span class="fs-4 fw-bold text-white">U</span></span>
            <span class="logo-lg"><span class="fs-4 fw-bold text-white">{{ config('app.name') }}</span></span>
        </a>
        <button type="button" class="btn btn-sm p-0 fs-3xl header-item float-end btn-vertical-sm-hover" id="vertical-hover">
            <i class="ri-record-circle-line"></i>
        </button>
    </div>

    <div id="scrollbar">
        <div class="container-fluid">
            <ul class="navbar-nav" id="navbar-nav">
                <li class="menu-title"><span>Menu</span></li>

                @can('View dashboard')
                    <li class="nav-item">
                        <a class="nav-link menu-link {{ $on('dashboard') ? 'active' : '' }}" href="{{ route('dashboard') }}">
                            <i class="ri-dashboard-2-line"></i> <span>Dashboard</span>
                        </a>
                    </li>
                @endcan

                @foreach ($groups as $g)
                    @php
                        $visible = collect($g['items'])->filter(fn ($i) => $u->can($i[2]));
                        $open = $visible->contains(fn ($i) => $on(...array_slice($i, 3)));
                    @endphp
                    @if ($visible->isNotEmpty())
                        <li class="nav-item">
                            <a class="nav-link menu-link {{ $open ? '' : 'collapsed' }}" href="#{{ $g['id'] }}" data-bs-toggle="collapse" role="button" aria-expanded="{{ $open ? 'true' : 'false' }}" aria-controls="{{ $g['id'] }}">
                                <i class="{{ $g['icon'] }}"></i> <span>{{ $g['label'] }}</span>
                            </a>
                            <div class="collapse menu-dropdown {{ $open ? 'show' : '' }}" id="{{ $g['id'] }}">
                                <ul class="nav nav-sm flex-column">
                                    @foreach ($visible as $i)
                                        <li class="nav-item">
                                            <a href="{{ route($i[1]) }}" class="nav-link {{ $on(...array_slice($i, 3)) ? 'active' : '' }}">{{ $i[0] }}</a>
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        </li>
                    @endif
                @endforeach
            </ul>
        </div>
    </div>
    <div class="sidebar-background"></div>
</div>
