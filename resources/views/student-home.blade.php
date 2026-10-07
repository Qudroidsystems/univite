@extends('layouts.master')

@section('content')
<div class="main-content">
    <div class="page-content">
        <div class="container-fluid">
            <div class="card border-0 bg-primary-subtle">
                <div class="card-body">
                    <h5 class="mb-1">Welcome, {{ $student->first_name }}</h5>
                    <p class="mb-0 text-muted">{{ $student->matric_number }} · {{ $student->programme->name }} · {{ $student->current_level }} Level</p>
                </div>
            </div>
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
