@extends('layouts.master')

@section('content')
@php $n = fn ($v) => number_format((float) $v, 2); $outUnits = (int) $outstanding->sum(fn ($o) => $o['course']->credit_units); @endphp
<div class="main-content">
    <div class="page-content">
        <div class="container-fluid">
            <x-owing-notice />
            <div class="card border-0 bg-primary-subtle">
                <div class="card-body d-flex flex-wrap justify-content-between align-items-center gap-3">
                    <div>
                        <h5 class="mb-1">Welcome, {{ $student->first_name }}</h5>
                        <p class="mb-0 text-muted">{{ $student->matric_label }} · {{ $student->programme->name }} · {{ $student->current_level }} Level</p>
                    </div>
                    <div class="d-flex gap-4 text-center">
                        <div><div class="fs-4 fw-semibold text-primary">{{ number_format($transcript->cgpa, 2) }}</div><div class="text-muted fs-12">CGPA</div></div>
                        <div><div class="fs-4 fw-semibold">{{ $transcript->earned }}</div><div class="text-muted fs-12">Units passed</div></div>
                        <div><div class="fs-4 fw-semibold {{ $outstanding->isNotEmpty() ? 'text-danger' : 'text-success' }}">{{ $outstanding->count() }}</div><div class="text-muted fs-12">Outstanding</div></div>
                    </div>
                </div>
            </div>
            @if ($checklist)
                @php $left = collect($checklist)->reject(fn ($i) => $i[2])->count(); @endphp
                <a href="{{ route('my.onboarding') }}" class="card card-animate text-decoration-none border-0 {{ $left ? 'bg-warning-subtle' : 'bg-success-subtle' }}"><div class="card-body d-flex align-items-center gap-3"><i class="ri-flag-line fs-1"></i><div><h6 class="mb-0 text-body">Getting started</h6><span class="text-muted">{{ $left ? $left . ' step(s) left: accept your offer, pay, complete your biodata and attend verification' : 'All steps complete' }}</span></div></div></a>
            @endif
            @if ($student->needs_onboarding && $student->verified_at)
                <a href="{{ route('my.clearance') }}" class="card card-animate text-decoration-none border-0 {{ $student->cleared_at ? 'bg-success-subtle' : 'bg-warning-subtle' }}"><div class="card-body d-flex align-items-center gap-3"><i class="ri-checkbox-circle-line fs-1"></i><div><h6 class="mb-0 text-body">Clearance</h6><span class="text-muted">{{ $student->cleared_at ? 'You are fully cleared' : 'See what is left to clear before you get your matric number' }}</span></div></div></a>
            @endif

            <div class="row">
                @if ($outstanding->isNotEmpty())
                    <div class="col-lg-6">
                        <div class="card border-danger-subtle">
                            <div class="card-header bg-danger-subtle d-flex justify-content-between align-items-center"><h6 class="card-title mb-0 text-danger"><i class="ri-error-warning-line me-1"></i>Outstanding courses ({{ $outUnits }} units)</h6>
                                <a href="{{ route('my.registration') }}" class="btn btn-sm btn-danger">Register them</a></div>
                            <ul class="list-group list-group-flush">
                                @foreach ($outstanding as $o)
                                    <li class="list-group-item d-flex justify-content-between align-items-center gap-2">
                                        <span><strong>{{ $o['course']->code }}</strong> <span class="fs-13">{{ $o['course']->title }}</span>
                                            <div class="fs-12 text-muted">Last {{ $o['last_grade'] ?? '—' }} in {{ $o['last_term'] }} · {{ $o['course']->credit_units }} units{{ $o['semester'] ? ' · offered in ' . $o['semester'] : '' }}</div></span>
                                        @if ($o['chosen_now'])<span class="badge bg-success-subtle text-success">Registered</span>@elseif ($o['offered_now'])<span class="badge bg-warning-subtle text-warning">Register now</span>@else<span class="badge bg-light text-muted">Not this semester</span>@endif
                                    </li>
                                @endforeach
                            </ul>
                            <div class="card-footer fs-12 text-muted">Carry-overs must be registered before this semester's own courses. The GPA planner and reading plan give them extra weight.</div>
                        </div>
                    </div>
                @endif
                <div class="col-lg-6">
                    @if ($ctx)
                        <a href="{{ route('my.registration') }}" class="card card-animate text-decoration-none"><div class="card-body d-flex align-items-center gap-3">
                            <span class="uv-tile-icon"><i class="ri-draft-line"></i></span>
                            <div><h6 class="mb-0 text-body">Course registration · {{ $ctx->label() }}</h6>
                                <span class="text-muted fs-13">{{ $form ? \App\Models\RegistrationForm::STATUSES[$form->status] . ' · ' . $form->total_units . ' units' : 'Not started yet' }}</span></div></div></a>
                    @endif
                    @if ($olevel)
                        <a href="{{ route('my.olevel') }}" class="card card-animate text-decoration-none {{ $olevel->allowsRegistration() ? '' : 'border-warning' }}"><div class="card-body d-flex align-items-center gap-3">
                            <span class="uv-tile-icon"><i class="ri-file-list-3-line"></i></span>
                            <div><h6 class="mb-0 text-body">O Level results</h6><span class="text-muted fs-13">{{ $olevel->status() }}</span></div></div></a>
                    @endif
                    @if ($standing && $standing->billed)
                        <div class="card"><div class="card-header d-flex justify-content-between align-items-center"><h6 class="card-title mb-0">Fees</h6><a href="{{ route('my.fees') }}" class="btn btn-sm btn-soft-primary">Pay</a></div>
                            <div class="card-body">@include('fees.partials.standing', ['standing' => $standing])</div></div>
                    @endif
                </div>
            </div>

            @include('dashboard.menu-cards', ['sections' => [new \App\Data\Navigation\MenuSection('My Portal', $tiles)]])
        </div>
    </div>
</div>
@endsection
