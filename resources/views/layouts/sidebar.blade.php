@php
    $u = auth()->user();
    $on = fn (string ...$patterns) => request()->routeIs(...$patterns);

    // [label, route name, permission, route pattern]
    $academic = [
        ['Institution Profile', 'institution.edit',              'View institution profile', 'institution.*'],
        ['Academic Sessions', 'academics.sessions.index',    'View academic sessions',    'academics.sessions.*'],
        ['Semesters',         'academics.semesters.index',   'View semesters',   'academics.semesters.*'],
        ['Faculties',         'academics.faculties.index',   'View faculties',    'academics.faculties.*'],
        ['Departments',       'academics.departments.index', 'View departments', 'academics.departments.*'],
        ['Programmes',        'academics.programmes.index',  'View programmes',  'academics.programmes.*'],
        ['Courses',           'academics.courses.index',     'View courses',     'academics.courses.*'],
        ['Curriculum',        'academics.curriculum.index',  'View programme curriculum', 'academics.curriculum.*'],
        ['Study Categories',  'academics.study-categories.index', 'View study categories', 'academics.study-categories.*'],
        ['Semester Types',    'academics.semester-types.index',   'View semester types',   'academics.semester-types.*'],
        ['Student Statuses',  'academics.student-statuses.index', 'View student statuses', 'academics.student-statuses.*'],
        ['Entry Modes',       'academics.entry-modes.index',      'View entry modes',      'academics.entry-modes.*'],
        ['Clearance Items',   'academics.clearance-items.index',  'View clearance items',  'academics.clearance-items.*'],
        ['Custom Form Fields','academics.custom-fields.index',   'View custom fields',    'academics.custom-fields.*'],
        ['Grading Scale',     'academics.grade-bands.index',      'View grade bands',      'academics.grade-bands.*'],
        ['Classes of Degree', 'academics.degree-classes.index',   'View degree classes',   'academics.degree-classes.*'],
        ['Academic Rules',    'academics.rules.index',            ['View academic rules', 'Edit academic rules', 'Edit department academic rules'], 'academics.rules.*'],
        ['Course Lecturers',  'academics.course-assignments.index','View course assignments','academics.course-assignments.*'],
        ['Result Approval Steps','academics.result-stages.index', 'View result stages',    'academics.result-stages.*'],
        ['Reference Lists',   'academics.lists.index',            'View reference lists',  'academics.lists.*'],
        ['Copy Session Setup','sessions.clone',                   'Copy session setup',    'sessions.clone*'],
        ['Session Rules',     'sessions.rules',                   'View session rules',    'sessions.rules*'],
        ['Operation History', 'operations.index',                 'View operation history','operations.*'],
        ['Emailed Documents', 'emails.index',                     'View email log',        'emails.*'],
    ];
    $students = [
        ['Applicants',           'applicants.index',        'View applicants',           'applicants.index', 'applicants.show'],
        ['New Student Verification', 'verification.index',  'View students',             'verification.*'],
        ['Clearance',            'clearance.index',         'View clearance',            'clearance.*'],
        ['Application Settings', 'applicants.settings',     'View application settings', 'applicants.settings*'],
        ['Students',             'students.index',          'View students',             'students.index', 'students.show'],
        ['Course Registrations', 'registrations.index',     'View course registrations', 'registrations.index', 'registrations.show'],
        ['Registration Settings', 'registrations.settings', 'View registration settings', 'registrations.settings*'],
        ['Score Sheets',      'results.sheets.index',        'View result sheets',        'results.sheets.*', 'results.open'],
        ['Broadsheet',        'results.broadsheet',          'View student results',      'results.broadsheet'],
        ['Result Summary',    'results.summary',             ['View student results', 'Edit academic rules', 'Edit department academic rules'], 'results.summary*'],
        ['Result Queries',    'results.complaints',          'View result complaints',    'results.complaints*'],
        ['Graduation Lists',  'graduation.index',            'View graduation lists',     'graduation.*'],
        ['Result Settings',   'results.settings',            'View result settings',      'results.settings*'],
        ['Matric Numbers',       'matric.index',            'View matric numbers',       'matric.*'],
        ['Matric Number Format', 'students.settings',       'View matric number format', 'students.settings*'],
    ];
    $bills = [
        ['Bill Items',   'bills.items.index',     'View bill items', 'bills.items.*'],
        ['School Bills', 'bills.structure.index', 'View school bills',      'bills.structure.*'],
    ];
    $fees = [
        ['Invoices & Payments', 'invoices.index', 'View invoices', 'invoices.*'],
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
        ['id' => 'sidebarFees',     'label' => 'Fees & Payments', 'icon' => 'ri-wallet-3-line', 'items' => $fees],
        ['id' => 'sidebarAccess',   'label' => 'Users & Access', 'icon' => 'ri-shield-user-line',    'items' => $access],
    ];
@endphp

<div class="app-menu navbar-menu">
    <div class="navbar-brand-box">
        <a href="{{ route('dashboard') }}" class="logo">
            @php $logo = \App\Support\Institution::image('logo'); @endphp
            <span class="logo-sm">@if ($logo)<img src="{{ $logo }}" alt="" height="30">@else<span class="fs-4 fw-bold text-white">{{ \App\Support\Institution::initials() }}</span>@endif</span>
            <span class="logo-lg d-inline-flex align-items-center gap-2">@if ($logo)<img src="{{ $logo }}" alt="" height="34">@endif<span class="fs-5 fw-bold text-white text-truncate" style="max-width:170px" title="{{ \App\Support\Institution::get('name') }}">{{ \App\Support\Institution::get('short_name') ?: \App\Support\Institution::get('name') }}</span></span>
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

                @if ($u->student?->needs_onboarding)
                    <li class="nav-item">
                        <a class="nav-link menu-link {{ $on('my.onboarding*') ? 'active' : '' }}" href="{{ route('my.onboarding') }}">
                            <i class="ri-flag-line"></i> <span>Getting Started</span>
                        </a>
                    </li>
                @endif
                @if ($u->student && (! $u->student->needs_onboarding || $u->student->cleared_at))
                    <li class="nav-item">
                        <a class="nav-link menu-link {{ $on('my.registration*') ? 'active' : '' }}" href="{{ route('my.registration') }}">
                            <i class="ri-draft-line"></i> <span>Course Registration</span>
                        </a>
                    </li>
                @endif
                @if (! $u->can('View course registrations') && \App\Models\CourseAdviser::where('user_id', $u->id)->exists())
                    <li class="nav-item">
                        <a class="nav-link menu-link {{ $on('registrations.*') ? 'active' : '' }}" href="{{ route('registrations.index') }}">
                            <i class="ri-team-line"></i> <span>My Advisees</span>
                        </a>
                    </li>
                @endif
                @if ($u->courseAssignments()->exists() && ! $u->can('View result sheets'))
                    <li class="nav-item">
                        <a class="nav-link menu-link {{ $on('results.*') ? 'active' : '' }}" href="{{ route('results.sheets.index') }}">
                            <i class="ri-file-list-3-line"></i> <span>My Courses &amp; Scores</span>
                        </a>
                    </li>
                @endif
                @if ($u->student && (! $u->student->needs_onboarding || $u->student->cleared_at))
                    <li class="nav-item">
                        <a class="nav-link menu-link {{ $on('my.results*') ? 'active' : '' }}" href="{{ route('my.results') }}">
                            <i class="ri-award-line"></i> <span>My Results</span>
                        </a>
                    </li>
                @endif
                @if ($u->student)
                    <li class="nav-item">
                        <a class="nav-link menu-link {{ $on('my.fees*') ? 'active' : '' }}" href="{{ route('my.fees') }}">
                            <i class="ri-wallet-3-line"></i> <span>My Fees</span>
                        </a>
                    </li>
                @endif

                @foreach ($groups as $g)
                    @php
                        $visible = collect($g['items'])->filter(fn ($i) => $u->canAny((array) $i[2]));
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
