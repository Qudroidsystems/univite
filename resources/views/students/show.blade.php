@extends('layouts.master')

@section('content')
@php
    $canEdit   = auth()->user()->can('Edit students');
    $canStatus = auth()->user()->can('Change student status');
    $canReset  = auth()->user()->can('Reset student passwords');
    $row = fn (string $label, $value) => '<li class="list-group-item d-flex justify-content-between gap-3"><span class="text-muted">' . e($label) . '</span><span class="text-end">' . ($value !== null && $value !== '' ? e($value) : '—') . '</span></li>';
@endphp
<div class="main-content">
    <div class="page-content">
        <div class="container-fluid">

            <div class="row">
                <div class="col-12">
                    <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                        <h4 class="mb-sm-0">{{ $student->full_name }}</h4>
                        <div class="page-title-right">
                            <ol class="breadcrumb m-0">
                                <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Dashboard</a></li>
                                <li class="breadcrumb-item"><a href="{{ route('students.index') }}">Students</a></li>
                                <li class="breadcrumb-item active">{{ $student->matric_label }}</li>
                            </ol>
                        </div>
                    </div>
                </div>
            </div>

            @include('academics.partials.alerts')

            <div class="row">
                <div class="col-lg-4">
                    <div class="card">
                        <div class="card-body text-center">
                            @if ($student->photo_url)
                                <img src="{{ $student->photo_url }}" alt="{{ $student->full_name }}" class="rounded-circle object-fit-cover mb-3" style="width:110px;height:110px;">
                            @else
                                <span class="d-inline-flex align-items-center justify-content-center rounded-circle bg-primary-subtle text-primary fw-semibold fs-2 mb-3" style="width:110px;height:110px;">{{ $student->initials }}</span>
                            @endif
                            <h5 class="mb-1">{{ $student->full_name }}</h5>
                            <p class="text-muted mb-2">{{ $student->matric_label }}</p>
                            <span class="badge {{ \App\Models\StudentStatus::badgeClass($student->status) }}">{{ \App\Models\StudentStatus::label($student->status) }}</span>

                            @if ($canEdit)
                                <form method="POST" action="{{ route('students.photo', $student->id) }}" enctype="multipart/form-data" class="mt-3 text-start">
                                    @csrf
                                    <label class="form-label fs-12 text-muted mb-1" for="photo">Passport photo (JPG or PNG, up to 2 MB)</label>
                                    <div class="input-group">
                                        <input type="file" name="photo" id="photo" class="form-control @error('photo') is-invalid @enderror" accept="image/png,image/jpeg" required>
                                        <button class="btn btn-soft-primary" type="submit">Upload</button>
                                    </div>
                                    @error('photo') <div class="text-danger fs-12 mt-1">{{ $message }}</div> @enderror
                                </form>
                            @endif
                            <div class="mt-3 text-start">
                                <div class="fs-12 text-muted mb-1">Signature</div>
                                @if ($student->signature_url)<img src="{{ $student->signature_url }}" alt="Signature" class="border rounded bg-white p-1" style="max-height:60px;max-width:100%;">@else<span class="text-muted fs-13">None yet</span>@endif
                                @if ($canEdit)
                                    <form method="POST" action="{{ route('students.signature', $student->id) }}" enctype="multipart/form-data" class="mt-2">@csrf
                                        <div class="input-group input-group-sm"><input type="file" name="signature" class="form-control" accept="image/png,image/jpeg" required><button class="btn btn-soft-primary">Upload</button></div>
                                    </form>
                                @endif
                            </div>
                        </div>
                    </div>

                    @if ($canStatus)
                        <div class="card">
                            <div class="card-header"><h6 class="card-title mb-0">Status</h6></div>
                            <div class="card-body">
                                <form method="POST" action="{{ route('students.status', $student->id) }}" class="d-flex gap-2">
                                    @csrf
                                    <select name="status" class="form-select">
                                        @foreach (\App\Models\StudentStatus::options() as $value => $label)
                                            <option value="{{ $value }}" @selected($student->status === $value)>{{ $label }}</option>
                                        @endforeach
                                    </select>
                                    <button class="btn btn-primary" type="submit">Save</button>
                                </form>
                                <div class="form-text">Suspended and withdrawn students cannot sign in.</div>
                            </div>
                        </div>
                    @endif

                    @include('students.partials.record-actions')

                    @can('View student results')
                        <a href="{{ route('students.transcript', $student->id) }}" class="btn btn-soft-primary w-100 mb-3"><i class="ri-award-line me-1"></i>Results &amp; transcript</a>
                    @endcan

                    @if ($canReset)
                        <div class="card">
                            <div class="card-body">
                                <form method="POST" action="{{ route('students.reset-password', $student->id) }}" onsubmit="return confirm('Generate a new temporary password for this student? The old password stops working.')">
                                    @csrf
                                    <button class="btn btn-soft-warning w-100" type="submit"><i class="ri-key-2-line me-1"></i>Reset password</button>
                                </form>
                            </div>
                        </div>
                    @endif
                </div>

                <div class="col-lg-8">
                    <div class="card">
                        <div class="card-header"><h6 class="card-title mb-0">Academic record</h6></div>
                        <ul class="list-group list-group-flush">
                            {!! $row('Programme', $student->programme->name . ' (' . $student->programme->code . ')') !!}
                            {!! $row('Department', $student->programme->department->name) !!}
                            {!! $row('Faculty', $student->programme->department->faculty->name) !!}
                            {!! $row('Entry session', $student->entrySession->name) !!}
                            {!! $row('Entry mode', \App\Models\EntryMode::label($student->entry_mode)) !!}
                            {!! $row('Entry level', $student->entry_level . ' Level') !!}
                            {!! $row('Current level', $student->current_level . ' Level') !!}
                            {!! $row('Course option', $student->courseOption?->name) !!}
                            {!! $row('Sub-department', $student->subDepartment?->name) !!}
                            @if ($settings->on('show_jamb')){!! $row('JAMB registration no.', $student->jamb_reg_no) !!}@endif
                            {!! $row('Application no.', $student->applicant?->application_no ?? $student->application_ref) !!}
                        </ul>
                    </div>

                    <div class="card">
                        <div class="card-header"><h6 class="card-title mb-0">Personal details</h6></div>
                        <ul class="list-group list-group-flush">
                            {!! $row('Login email', $student->user->email) !!}
                            {!! $row('Phone', $student->phone_number) !!}
                            {!! $row('Gender', $student->gender ? ucfirst($student->gender) : null) !!}
                            {!! $row('Date of birth', $student->date_of_birth?->format('d M Y')) !!}
                            {!! $row('Nationality', $student->nationality) !!}
                            {!! $row('State of origin', $student->state_of_origin) !!}
                            {!! $row('Local government area', $student->lga) !!}
                            @if ($settings->on('show_nin')){!! $row('NIN', $student->nin) !!}@endif
                            {!! $row('Religion', $student->religion ? \App\Support\Lookups::label('religion', $student->religion) : null) !!}
                            {!! $row('Marital status', $student->marital_status ? \App\Support\Lookups::label('marital_status', $student->marital_status) : null) !!}
                            {!! $row('Blood group / genotype', trim(($student->blood_group ?? '') . ' ' . ($student->genotype ? '· ' . $student->genotype : ''), ' ·') ?: null) !!}
                            {!! $row('Home address', $student->address) !!}
                            {!! $row('Last sign-in', $student->user->last_login_at?->format('d M Y, H:i') ?? 'Never') !!}
                        </ul>
                    </div>

                    <div class="card">
                        <div class="card-header"><h6 class="card-title mb-0">Guardian / next of kin</h6></div>
                        <ul class="list-group list-group-flush">
                            {!! $row('Name', $student->guardian_name) !!}
                            {!! $row('Relationship', $student->guardian_relationship) !!}
                            {!! $row('Phone', $student->guardian_phone) !!}
                            {!! $row('Address', $student->guardian_address) !!}
                            {!! $row('Email', $student->next_of_kin_email) !!}
                        </ul>
                    </div>

                    @include('students.partials.referees', ['canEdit' => $canEdit])

                    @if ($student->tracks->isNotEmpty())
                        <div class="card">
                            <div class="card-header"><h6 class="card-title mb-0">Semester by semester</h6></div>
                            <div class="table-responsive"><table class="table table-sm mb-0">
                                <thead class="table-light"><tr><th>Session</th><th>Semester</th><th>Level</th><th>Semester / cumulative</th><th></th></tr></thead>
                                <tbody>@foreach ($student->tracks as $t)<tr><td>{{ $t->session?->name }}</td><td>{{ $t->semesterType?->name }}</td><td>{{ $t->level }}</td><td>{{ $t->semester_remark ?? '—' }}@if ($t->cumulative_remark) / {{ $t->cumulative_remark }}@endif</td><td>@if ($t->status === 'active')<span class="badge bg-primary-subtle text-primary">Now</span>@endif</td></tr>@endforeach</tbody>
                            </table></div>
                        </div>
                    @endif

                    @if ($student->transfers->isNotEmpty() || $student->deferrals->isNotEmpty())
                        <div class="card">
                            <div class="card-header"><h6 class="card-title mb-0">Transfers and deferrals</h6></div>
                            <ul class="list-group list-group-flush">
                                @foreach ($student->transfers as $t)
                                    <li class="list-group-item fs-13"><i class="ri-arrow-left-right-line text-primary me-1"></i>{{ $t->created_at->format('d M Y') }}: moved from <strong>{{ $t->fromProgramme?->name }}</strong> ({{ $t->from_level }}) to <strong>{{ $t->toProgramme?->name }}</strong> ({{ $t->to_level }}) by {{ $t->by?->name }}. <span class="text-muted">{{ $t->reason }}</span></li>
                                @endforeach
                                @foreach ($student->deferrals as $d)
                                    <li class="list-group-item fs-13"><i class="ri-time-line text-info me-1"></i>{{ $d->created_at->format('d M Y') }}: deferred to {{ $d->toSession?->name }} ({{ $d->status }}). <span class="text-muted">{{ $d->reason }}</span></li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    @include('partials.custom-field-display', ['cfTarget' => 'student', 'cfSubject' => $student])

                    @can('Edit students')
                        <a href="{{ route('students.index', ['q' => $student->matric_number]) }}" class="text-muted fs-12">To edit these details, use the pencil on the Students list.</a>
                    @endcan
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
