@extends('layouts.master')

@section('content')
<div class="main-content">
    <div class="page-content">
        <div class="container-fluid">
            <div class="page-title-box"><h4 class="mb-0">Result settings</h4></div>
            @include('academics.partials.alerts')
            <div class="row g-3 mb-3">
                @can('View grade bands')
                    <div class="col-md-6"><a href="{{ route('academics.grade-bands.index') }}" class="card card-body h-100 mb-0 text-body">
                        <h5 class="mb-1"><i class="ri-bar-chart-grouped-line text-primary me-1"></i>Key to Grade</h5>
                        <span class="text-muted">Score ranges, grades, ranks (Distinction, Credit, Merit, Pass, Fail) and points, for the university or each school, department and category.</span></a></div>
                @endcan
                @can('View result keys')
                    <div class="col-md-6"><a href="{{ route('academics.result-keys.index') }}" class="card card-body h-100 mb-0 text-body">
                        <h5 class="mb-1"><i class="ri-key-2-line text-primary me-1"></i>Key to Result</h5>
                        <span class="text-muted">CGPA ranges with the remark, rank, action and activity used when results are computed and printed on the master sheet.</span></a></div>
                @endcan
            </div>
            <form method="POST" action="{{ route('results.settings.update') }}" class="card">
                @csrf @method('PUT')
                <div class="card-body row g-3">
                    <p class="text-muted mb-0">How a course's 100 marks are split when no assessment scheme applies to it. Lecturers cannot enter a score above these limits.
                        @can('View assessment schemes') To mark courses on several assessments (tests, assignments, practicals ...), use <a href="{{ route('academics.assessments.index') }}">Assessment Schemes</a>.@endcan</p>
                    <div class="col-md-6"><label class="form-label">Continuous assessment (CA) out of</label><input type="number" step="0.01" name="ca_max" value="{{ old('ca_max', $ca) }}" class="form-control @error('ca_max') is-invalid @enderror" required></div>
                    <div class="col-md-6"><label class="form-label">Exam out of</label><input type="number" step="0.01" name="exam_max" value="{{ old('exam_max', $exam) }}" class="form-control @error('exam_max') is-invalid @enderror" required>@error('exam_max')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                    <hr class="my-1">
                    <div class="col-md-6"><label class="form-label">Students may query a result for (days)</label><input type="number" name="complaint_days" min="0" value="{{ old('complaint_days', $complaintDays) }}" class="form-control @error('complaint_days') is-invalid @enderror" required><div class="form-text">Counted from when the result is published. 0 means no time limit.</div></div>
                    <p class="text-muted mb-0 fs-13">Credit load, carry-overs, CGPA method, probation and graduation rules are under <a href="{{ route('academics.rules.index') }}">Academic Rules</a>, where each faculty, department and programme can have its own.</p>
                </div>
                @can('Edit result settings')<div class="card-footer text-end"><button class="btn btn-primary" type="submit"><i class="ri-save-line me-1"></i>Save</button></div>@endcan
            </form>
        </div>
    </div>
</div>
@endsection
