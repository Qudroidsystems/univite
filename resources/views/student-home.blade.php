@extends('layouts.master')

@section('content')
<div class="main-content">
    <div class="page-content">
        <div class="container-fluid">
            <div class="card border-0 bg-primary-subtle">
                <div class="card-body">
                    <h5 class="mb-1">Welcome, {{ $student->first_name }}</h5>
                    <p class="mb-0 text-muted">{{ $student->matric_label }} · {{ $student->programme->name }} · {{ $student->current_level }} Level</p>
                </div>
            </div>
            @if ($checklist)
                @php $left = collect($checklist)->reject(fn ($i) => $i[2])->count(); @endphp
                <a href="{{ route('my.onboarding') }}" class="card card-animate text-decoration-none border-0 {{ $left ? 'bg-warning-subtle' : 'bg-success-subtle' }}"><div class="card-body d-flex align-items-center gap-3"><i class="ri-flag-line fs-1"></i><div><h6 class="mb-0 text-body">Getting started</h6><span class="text-muted">{{ $left ? $left . ' step(s) left: accept your offer, pay, complete your biodata and attend verification' : 'All steps complete' }}</span></div></div></a>
            @endif
            @if ($student->needs_onboarding && $student->verified_at)
                <a href="{{ route('my.clearance') }}" class="card card-animate text-decoration-none border-0 {{ $student->cleared_at ? 'bg-success-subtle' : 'bg-warning-subtle' }}"><div class="card-body d-flex align-items-center gap-3"><i class="ri-checkbox-circle-line fs-1"></i><div><h6 class="mb-0 text-body">Clearance</h6><span class="text-muted">{{ $student->cleared_at ? 'You are fully cleared' : 'See what is left to clear before you get your matric number' }}</span></div></div></a>
            @endif
            <a href="{{ route('my.fees') }}" class="card card-animate text-decoration-none"><div class="card-body d-flex align-items-center gap-3"><i class="ri-wallet-3-line fs-1 text-primary"></i><div><h6 class="mb-0 text-body">My Fees</h6><span class="text-muted">See what you owe, your payments and receipts</span></div></div></a>
            <div class="card">
                <div class="card-body text-center text-muted py-5">
                    <i class="ri-graduation-cap-line fs-1 d-block mb-2"></i>
                    Your student portal (course registration, fees and results) is being set up. You will be able to use it here soon.
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
