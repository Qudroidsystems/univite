@extends('layouts.public')

@section('title', 'My application')

@section('content')
@php
    $done = collect($checklist)->every(fn ($i) => $i[2]);
    $docFor = fn ($type) => $applicant->documents->firstWhere('type', $type);
    $field = fn ($name) => old($name, $applicant->{$name} instanceof \Carbon\Carbon ? $applicant->{$name}->format('Y-m-d') : $applicant->{$name});
    $ro = $editable ? '' : 'disabled';
@endphp
<div class="row justify-content-center">
    <div class="col-lg-10">

        <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
            <div>
                <h4 class="mb-0">{{ $applicant->full_name }}</h4>
                <div class="text-muted">{{ $applicant->application_no }} · {{ $applicant->programme->name }} · {{ \App\Models\EntryMode::label($applicant->entry_mode) }}</div>
            </div>
            <span class="badge fs-6 {{ ['draft' => 'bg-secondary-subtle text-secondary', 'pending' => 'bg-warning-subtle text-warning', 'admitted' => 'bg-success-subtle text-success', 'rejected' => 'bg-danger-subtle text-danger'][$applicant->status] }}">{{ \App\Models\Applicant::STATUSES[$applicant->status] }}</span>
        </div>

        @foreach (['success' => 'success', 'error' => 'danger'] as $key => $cls)
            @if (session($key))<div class="alert alert-{{ $cls }}">{{ session($key) }}</div>@endif
        @endforeach
        @if ($errors->any())<div class="alert alert-danger"><ul class="mb-0 ps-3">@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul></div>@endif

        @if ($applicant->status === 'admitted')
            <div class="alert alert-success">
                <h5 class="alert-heading"><i class="ri-medal-line me-1"></i>Congratulations, you have been admitted!</h5>
                @if ($applicant->student)<p class="mb-1">Your matric number is <strong>{{ $applicant->student->matric_number }}</strong>.</p>@endif
                <p class="mb-0">Sign in at <a href="{{ route('login') }}">Student / staff sign in</a> with <strong>{{ $applicant->email }}</strong> and the password you chose when you applied.</p>
            </div>
        @elseif ($applicant->status === 'rejected')
            <div class="alert alert-danger">We are sorry. Your application was not successful this time.</div>
        @elseif ($applicant->status === 'pending')
            <div class="alert alert-info d-flex justify-content-between align-items-center">
                <span>Your application has been submitted and is being reviewed. We will contact you by email.</span>
                <a href="{{ route('apply.slip') }}" class="btn btn-primary btn-sm">Screening slip</a>
            </div>
        @endif

        @if ($editable)
        <div class="card pub-card mb-3">
            <div class="card-header"><h6 class="card-title mb-0">Your checklist</h6></div>
            <ul class="list-group list-group-flush">
                @foreach ($checklist as [$key, $label, $ok])
                    <li class="list-group-item d-flex gap-2 align-items-center"><i class="{{ $ok ? 'ri-checkbox-circle-fill text-success' : 'ri-checkbox-blank-circle-line text-muted' }} fs-5"></i><span class="{{ $ok ? 'text-muted' : '' }}">{{ $label }}</span></li>
                @endforeach
            </ul>
            <div class="card-footer">
                <form method="POST" action="{{ route('apply.submit') }}" onsubmit="return confirm('Submit your application? You will not be able to change it afterwards.')">
                    @csrf
                    <button class="btn btn-primary" type="submit" @disabled(! $done)>Submit application</button>
                    @unless ($done)<span class="text-muted ms-2">Finish every item above to submit.</span>@endunless
                </form>
            </div>
        </div>
        @endif

        @if ($invoice)
        <div class="card pub-card mb-3">
            <div class="card-header d-flex justify-content-between"><h6 class="card-title mb-0">Screening fee</h6>
                <span class="badge {{ \App\Models\Invoice::BADGES[$invoice->status] }}">{{ \App\Models\Invoice::STATUSES[$invoice->status] }}</span></div>
            <div class="card-body">
                <div class="d-flex flex-wrap gap-4">
                    <div><div class="text-muted fs-12">Amount</div><div class="fs-5 fw-semibold">₦{{ number_format((float) $invoice->total, 2) }}</div></div>
                    <div><div class="text-muted fs-12">Payment reference</div><div class="fs-5 fw-semibold">{{ $invoice->reference }}</div></div>
                    <div><div class="text-muted fs-12">Paid so far</div><div class="fs-5">₦{{ number_format((float) $invoice->amount_paid, 2) }}</div></div>
                </div>
                @if ($invoice->status !== 'paid')
                    <hr>
                    @if ($paymentInstructions)<div style="white-space:pre-line">{{ $paymentInstructions }}</div>@else<p class="mb-0 text-muted">Pay at the bursary or the bank you are told about and quote the payment reference above.</p>@endif
                    <p class="text-muted fs-12 mt-2 mb-0">Once the payment is confirmed this page updates by itself. Refresh it to check.</p>
                @endif
            </div>
        </div>
        @endif

        <div class="card pub-card mb-3">
            <div class="card-header"><h6 class="card-title mb-0">Personal details</h6></div>
            <form method="POST" action="{{ route('apply.profile') }}" class="card-body row g-3">
                @csrf @method('PUT')
                <div class="col-md-4"><label class="form-label">First name</label><input name="first_name" value="{{ $field('first_name') }}" class="form-control" required {{ $ro }}></div>
                <div class="col-md-4"><label class="form-label">Surname</label><input name="last_name" value="{{ $field('last_name') }}" class="form-control" required {{ $ro }}></div>
                <div class="col-md-4"><label class="form-label">Other names</label><input name="other_names" value="{{ $field('other_names') }}" class="form-control" {{ $ro }}></div>
                <div class="col-md-4"><label class="form-label">Phone number</label><input name="phone_number" value="{{ $field('phone_number') }}" class="form-control" required {{ $ro }}></div>
                <div class="col-md-4"><label class="form-label">Gender</label>
                    <select name="gender" class="form-select" required {{ $ro }}><option value="">Select…</option>@foreach (['male' => 'Male', 'female' => 'Female', 'other' => 'Other'] as $v => $l)<option value="{{ $v }}" @selected($field('gender') === $v)>{{ $l }}</option>@endforeach</select></div>
                <div class="col-md-4"><label class="form-label">Date of birth</label><input type="date" name="date_of_birth" value="{{ $field('date_of_birth') }}" class="form-control" required {{ $ro }}></div>
                <div class="col-md-4"><label class="form-label">Nationality</label><input name="nationality" value="{{ $field('nationality') ?: 'Nigerian' }}" class="form-control" required {{ $ro }}></div>
                <div class="col-md-4"><label class="form-label">State of origin</label>
                    <select name="state_of_origin" class="form-select" required {{ $ro }}><option value="">Select…</option>@foreach ($states as $v => $l)<option value="{{ $v }}" @selected($field('state_of_origin') === $v)>{{ $l }}</option>@endforeach</select></div>
                <div class="col-md-4"><label class="form-label">Local government area</label><input name="lga" value="{{ $field('lga') }}" class="form-control" required {{ $ro }}></div>
                @if (\App\Models\EntryMode::byCode($applicant->entry_mode)?->uses_utme)
                    <div class="col-md-3"><label class="form-label">UTME score</label><input type="number" name="jamb_score" value="{{ $field('jamb_score') }}" min="{{ $minScore }}" max="400" class="form-control" required {{ $ro }}></div>
                @endif
                <div class="col-12"><label class="form-label">Home address</label><textarea name="address" rows="2" class="form-control" required {{ $ro }}>{{ $field('address') }}</textarea></div>
                <div class="col-12"><h6 class="mb-0 mt-2">Guardian / next of kin</h6></div>
                <div class="col-md-5"><label class="form-label">Name</label><input name="guardian_name" value="{{ $field('guardian_name') }}" class="form-control" required {{ $ro }}></div>
                <div class="col-md-3"><label class="form-label">Relationship</label><input name="guardian_relationship" value="{{ $field('guardian_relationship') }}" class="form-control" required {{ $ro }}></div>
                <div class="col-md-4"><label class="form-label">Phone</label><input name="guardian_phone" value="{{ $field('guardian_phone') }}" class="form-control" required {{ $ro }}></div>
                <div class="col-12"><label class="form-label">Address</label><textarea name="guardian_address" rows="2" class="form-control" {{ $ro }}>{{ $field('guardian_address') }}</textarea></div>
                @if ($editable)<div class="col-12"><button class="btn btn-primary" type="submit"><i class="ri-save-line me-1"></i>Save details</button></div>@endif
            </form>
        </div>

        <div class="card pub-card mb-3">
            <div class="card-header"><h6 class="card-title mb-0">{{ \App\Models\EntryMode::byCode($applicant->entry_mode)?->needs_prior_result ? "O'Level and NCE / ND results" : "O'Level results" }}</h6></div>
            <div class="card-body">
                <p class="text-muted">Enter each subject exactly as it appears on your result. You need at least 5 credits (C6 or better) including English Language and Mathematics.</p>
                <div class="table-responsive mb-3">
                    <table class="table table-sm align-middle mb-0">
                        <thead class="table-light"><tr><th>Exam</th><th>Year</th><th>Exam number</th><th>Subject</th><th>Grade</th><th></th></tr></thead>
                        <tbody>
                            @forelse ($applicant->results as $r)
                                <tr><td>{{ \App\Models\ApplicantResult::EXAMS[$r->exam_type] }}</td><td>{{ $r->exam_year }}</td><td>{{ $r->exam_number }}</td><td>{{ $r->subject }}</td><td>{{ $r->grade }}</td>
                                    <td class="text-end">@if ($editable)<form method="POST" action="{{ route('apply.results.destroy', $r->id) }}">@csrf @method('DELETE')<button class="btn btn-sm btn-soft-danger" title="Remove"><i class="ri-delete-bin-line"></i></button></form>@endif</td></tr>
                            @empty
                                <tr><td colspan="6" class="text-center text-muted py-3">No results added yet.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if ($editable)
                <form method="POST" action="{{ route('apply.results.store') }}" class="row g-2 align-items-end">
                    @csrf
                    <div class="col-md-2"><label class="form-label fs-12">Exam</label><select name="exam_type" class="form-select" required>@foreach (\App\Models\ApplicantResult::EXAMS as $v => $l)<option value="{{ $v }}" @selected(old('exam_type') === $v)>{{ $l }}</option>@endforeach</select></div>
                    <div class="col-md-1"><label class="form-label fs-12">Year</label><input type="number" name="exam_year" value="{{ old('exam_year') }}" min="1990" max="{{ now()->year }}" class="form-control" required></div>
                    <div class="col-md-3"><label class="form-label fs-12">Exam number</label><input name="exam_number" value="{{ old('exam_number') }}" class="form-control" required></div>
                    <div class="col-md-3"><label class="form-label fs-12">Subject</label><input name="subject" value="{{ old('subject') }}" class="form-control" placeholder="English Language" required></div>
                    <div class="col-md-2"><label class="form-label fs-12">Grade</label>
                        <select name="grade" class="form-select" required>
                            @foreach (\App\Models\ApplicantResult::GRADES as $group => $grades)
                                <optgroup label="{{ strtoupper($group) }}">@foreach ($grades as $g)<option value="{{ $g }}" @selected(old('grade') === $g)>{{ $g }}</option>@endforeach</optgroup>
                            @endforeach
                        </select></div>
                    <div class="col-md-1"><button class="btn btn-soft-primary w-100" type="submit">Add</button></div>
                </form>
                @endif
            </div>
        </div>

        <div class="card pub-card mb-3">
            <div class="card-header"><h6 class="card-title mb-0">Documents</h6></div>
            <ul class="list-group list-group-flush">
                @foreach ($documents as $type => $label)
                    @php $doc = $docFor($type); $req = array_key_exists($type, $required); @endphp
                    <li class="list-group-item">
                        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                            <div>
                                <span class="fw-medium">{{ $label }}</span>
                                @if ($req)<span class="badge bg-danger-subtle text-danger ms-1">Required</span>@else<span class="badge bg-light text-muted ms-1">Optional</span>@endif
                                <div class="text-muted fs-12">{{ $type === 'passport' ? 'JPEG only, 50 KB or less, no glasses or veil.' : 'JPG, PNG or PDF, 2 MB or less.' }}</div>
                                @if ($doc)<div class="fs-12"><i class="ri-check-line text-success"></i> <a href="{{ route('apply.documents.show', $doc->id) }}" target="_blank">{{ $doc->original_name }}</a> ({{ number_format($doc->size / 1024, 0) }} KB)</div>@endif
                            </div>
                            @if ($editable)
                                <form method="POST" action="{{ route('apply.documents.store') }}" enctype="multipart/form-data" class="d-flex gap-2">
                                    @csrf <input type="hidden" name="type" value="{{ $type }}">
                                    <input type="file" name="file" class="form-control form-control-sm" accept="{{ $type === 'passport' ? 'image/jpeg' : 'image/png,image/jpeg,application/pdf' }}" required>
                                    <button class="btn btn-sm btn-soft-primary" type="submit">{{ $doc ? 'Replace' : 'Upload' }}</button>
                                </form>
                            @endif
                        </div>
                        @if ($doc && $editable)<form method="POST" action="{{ route('apply.documents.destroy', $doc->id) }}" class="mt-1">@csrf @method('DELETE')<button class="btn btn-link btn-sm text-danger p-0">Remove file</button></form>@endif
                    </li>
                @endforeach
            </ul>
        </div>
    </div>
</div>
@endsection
