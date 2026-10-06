@extends('layouts.master')

@section('content')
<div class="main-content">
    <div class="page-content">
        <div class="container-fluid">

            <div class="row">
                <div class="col-12">
                    <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                        <h4 class="mb-sm-0">Dashboard</h4>
                        <div class="page-title-right">
                            <ol class="breadcrumb m-0"><li class="breadcrumb-item active">Dashboard</li></ol>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card border-0 bg-primary-subtle">
                <div class="card-body d-flex flex-wrap justify-content-between align-items-center gap-3">
                    <div>
                        <h5 class="mb-1">Welcome, {{ auth()->user()->name }}</h5>
                        @if ($currentSession)
                            <p class="mb-0 text-muted">
                                Current session <strong>{{ $currentSession->name }}</strong>
                                @if ($currentSemester) · <strong>{{ $currentSemester->name }}</strong> @endif
                            </p>
                        @else
                            <p class="mb-0 text-muted">No current session yet.
                                @can('View session') <a href="{{ route('academics.sessions.index') }}">Create one to get started</a>. @endcan
                            </p>
                        @endif
                    </div>
                </div>
            </div>

            <div class="row">
                @foreach ($stats as $s)
                    @can($s['can'])
                        <div class="col-xl col-md-4 col-6">
                            <a href="{{ route($s['route']) }}" class="card card-animate text-decoration-none">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <p class="text-uppercase fw-medium text-muted fs-12 mb-2">{{ $s['label'] }}</p>
                                            <h3 class="mb-0 text-body">{{ number_format($s['value']) }}</h3>
                                        </div>
                                        <span class="avatar-sm"><span class="avatar-title bg-primary-subtle text-primary rounded fs-3"><i class="{{ $s['icon'] }}"></i></span></span>
                                    </div>
                                </div>
                            </a>
                        </div>
                    @endcan
                @endforeach
            </div>

            <div class="card">
                <div class="card-header"><h6 class="card-title mb-0">Setup checklist</h6></div>
                <div class="card-body">
                    @php
                        $steps = [
                            ['Create an academic session and its semesters', \App\Models\AcademicSession::count() > 0, 'academics.sessions.index', 'View session'],
                            ['Add faculties', \App\Models\Faculty::count() > 0, 'academics.faculties.index', 'View faculty'],
                            ['Add departments', \App\Models\Department::count() > 0, 'academics.departments.index', 'View department'],
                            ['Add programmes', \App\Models\Programme::count() > 0, 'academics.programmes.index', 'View programme'],
                            ['Add courses with credit units', \App\Models\Course::count() > 0, 'academics.courses.index', 'View course'],
                            ['Build each programme\'s curriculum', \App\Models\ProgrammeCourse::count() > 0, 'academics.curriculum.index', 'View curriculum'],
                            ['Create bill items (tuition, hostel …)', \App\Models\BillItem::count() > 0, 'bills.items.index', 'View bill-item'],
                            ['Set school bills per programme and level', \App\Models\Bill::count() > 0, 'bills.structure.index', 'View bill'],
                        ];
                    @endphp
                    <ul class="list-unstyled mb-0">
                        @foreach ($steps as [$label, $done, $route, $perm])
                            <li class="d-flex align-items-center gap-2 py-1">
                                <i class="{{ $done ? 'ri-checkbox-circle-fill text-success' : 'ri-checkbox-blank-circle-line text-muted' }} fs-5"></i>
                                @can($perm)
                                    <a href="{{ route($route) }}" class="{{ $done ? 'text-muted' : 'fw-medium' }}">{{ $label }}</a>
                                @else
                                    <span class="{{ $done ? 'text-muted' : '' }}">{{ $label }}</span>
                                @endcan
                            </li>
                        @endforeach
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
