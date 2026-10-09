@extends('layouts.master')

@section('content')
@php
    $done = fn ($key) => collect($checklist)->firstWhere(0, $key)[2] ?? false;
    $accepted = $student->offer_accepted_at !== null;
    $field = fn ($name) => old($name, $student->{$name} instanceof \Carbon\Carbon ? $student->{$name}->format('Y-m-d') : $student->{$name});
    $ro = $locked ? 'disabled' : '';
@endphp
<div class="main-content">
    <div class="page-content">
        <div class="container-fluid" style="max-width:960px">
            <div class="page-title-box"><h4 class="mb-0">Getting started</h4></div>
            @include('academics.partials.alerts')

            @if ($student->verified_at)
                <div class="alert alert-success"><i class="ri-shield-check-line me-1"></i><strong>You have been verified.</strong> Your admission process is complete.</div>
            @endif

            <div class="card">
                <div class="card-header"><h6 class="card-title mb-0">{{ $student->full_name }} · {{ $student->matric_label }}</h6></div>
                <div class="card-body">
                    <p class="text-muted mb-3">{{ $student->programme->name }} · {{ $student->entry_level }} Level · {{ $student->entrySession->name }} session</p>
                    <ul class="list-unstyled mb-0">
                        @foreach ($checklist as [$key, $label, $ok])
                            <li class="d-flex align-items-center gap-2 py-1"><i class="{{ $ok ? 'ri-checkbox-circle-fill text-success' : 'ri-checkbox-blank-circle-line text-muted' }} fs-5"></i><span class="{{ $ok ? 'text-muted' : '' }}">{{ $label }}</span></li>
                        @endforeach
                    </ul>
                </div>
            </div>

            <div class="card">
                <div class="card-header d-flex justify-content-between"><h6 class="card-title mb-0">1. Accept your offer</h6>@if ($accepted)<span class="badge bg-success-subtle text-success">Accepted {{ $student->offer_accepted_at->format('d M Y') }}</span>@endif</div>
                <div class="card-body">
                    @if ($accepted)
                        <p class="mb-0 text-muted">You accepted your admission offer.</p>
                    @else
                        <p>Accepting confirms that you will take up your place. @if (\App\Services\ApplicationSettings::acceptanceItemId()) The acceptance fee is raised when you accept. @endif</p>
                        <form method="POST" action="{{ route('my.onboarding.accept') }}">@csrf<button class="btn btn-primary" type="submit"><i class="ri-check-double-line me-1"></i>Accept my admission offer</button></form>
                    @endif
                </div>
            </div>

            @if ($invoice)
            <div class="card">
                <div class="card-header d-flex justify-content-between"><h6 class="card-title mb-0">2. Pay the acceptance fee</h6><span class="badge {{ \App\Models\Invoice::BADGES[$invoice->status] }}">{{ \App\Models\Invoice::STATUSES[$invoice->status] }}</span></div>
                <div class="card-body">
                    <div class="d-flex flex-wrap gap-4">
                        <div><div class="text-muted fs-12">Amount</div><div class="fs-5 fw-semibold">₦{{ number_format((float) $invoice->total, 2) }}</div></div>
                        <div><div class="text-muted fs-12">Payment reference</div><div class="fs-5 fw-semibold">{{ $invoice->reference }}</div></div>
                        <div><div class="text-muted fs-12">Paid so far</div><div class="fs-5">₦{{ number_format((float) $invoice->amount_paid, 2) }}</div></div>
                    </div>
                    @if ($invoice->status !== 'paid')
                        <hr>
                        @if ($paymentInstructions)<div style="white-space:pre-line">{{ $paymentInstructions }}</div>@else<p class="mb-0 text-muted">Pay as directed by the bursary and quote the payment reference above.</p>@endif
                        <p class="text-muted fs-12 mt-2 mb-0">Once the bursary confirms your payment this page updates. Refresh it to check.</p>
                    @endif
                </div>
            </div>
            @endif

            <div class="card">
                <div class="card-header"><h6 class="card-title mb-0">3. Complete your biodata</h6></div>
                <form method="POST" action="{{ route('my.onboarding.biodata') }}" class="card-body row g-3" enctype="multipart/form-data">
                    @csrf @method('PUT')
                    <div class="col-md-4"><label class="form-label">Phone number</label><input name="phone_number" value="{{ $field('phone_number') }}" class="form-control" required {{ $ro }}></div>
                    <div class="col-md-4"><label class="form-label">Gender</label>
                        <select name="gender" class="form-select" required {{ $ro }}><option value="">Select…</option>@foreach (['male' => 'Male', 'female' => 'Female', 'other' => 'Other'] as $v => $l)<option value="{{ $v }}" @selected($field('gender') === $v)>{{ $l }}</option>@endforeach</select></div>
                    <div class="col-md-4"><label class="form-label">Date of birth</label><input type="date" name="date_of_birth" value="{{ $field('date_of_birth') }}" class="form-control" required {{ $ro }}></div>
                    <div class="col-md-4"><label class="form-label">Nationality</label><input name="nationality" value="{{ $field('nationality') }}" class="form-control" required {{ $ro }}></div>
                    <div class="col-md-4"><label class="form-label">State of origin</label>
                        <select name="state_of_origin" class="form-select" required {{ $ro }}><option value="">Select…</option>@foreach ($states as $v => $l)<option value="{{ $v }}" @selected($field('state_of_origin') === $v)>{{ $l }}</option>@endforeach</select></div>
                    <div class="col-md-4"><label class="form-label">Local government area</label><input name="lga" value="{{ $field('lga') }}" class="form-control" required {{ $ro }}></div>
                    <div class="col-12"><label class="form-label">Home address</label><textarea name="address" rows="2" class="form-control" required {{ $ro }}>{{ $field('address') }}</textarea></div>
                    <div class="col-md-5"><label class="form-label">Guardian / next of kin</label><input name="guardian_name" value="{{ $field('guardian_name') }}" class="form-control" required {{ $ro }}></div>
                    <div class="col-md-3"><label class="form-label">Relationship</label><input name="guardian_relationship" value="{{ $field('guardian_relationship') }}" class="form-control" required {{ $ro }}></div>
                    <div class="col-md-4"><label class="form-label">Guardian phone</label><input name="guardian_phone" value="{{ $field('guardian_phone') }}" class="form-control" required {{ $ro }}></div>
                    <div class="col-12"><label class="form-label">Guardian address</label><textarea name="guardian_address" rows="2" class="form-control" {{ $ro }}>{{ $field('guardian_address') }}</textarea></div>
                    @include('partials.custom-field-inputs', ['cfTarget' => 'student', 'cfSubject' => $student ?? null, 'cfSelfService' => true, 'cfReadonly' => $locked])
                    @unless ($locked)<div class="col-12"><button class="btn btn-primary" type="submit"><i class="ri-save-line me-1"></i>Save biodata</button></div>@endunless
                </form>
            </div>

            <div class="card">
                <div class="card-header"><h6 class="card-title mb-0">4. Documents</h6></div>
                <ul class="list-group list-group-flush">
                    @foreach ($required as $type => $label)
                        @php $doc = $documents->get($type); @endphp
                        <li class="list-group-item d-flex flex-wrap justify-content-between align-items-center gap-2">
                            <div>
                                <span class="fw-medium">{{ $label }}</span>
                                <div class="text-muted fs-12">{{ $type === 'passport' ? 'JPEG only, 50 KB or less.' : 'JPG, PNG or PDF, 2 MB or less.' }}</div>
                                @if ($doc)<div class="fs-12"><i class="ri-check-line text-success"></i> <a href="{{ route('my.onboarding.documents.show', $doc->id) }}" target="_blank">{{ $doc->original_name }}</a></div>@endif
                            </div>
                            @unless ($locked)
                                <form method="POST" action="{{ route('my.onboarding.documents.store') }}" enctype="multipart/form-data" class="d-flex gap-2">
                                    @csrf <input type="hidden" name="type" value="{{ $type }}">
                                    <input type="file" name="file" class="form-control form-control-sm" accept="{{ $type === 'passport' ? 'image/jpeg' : 'image/png,image/jpeg,application/pdf' }}" required>
                                    <button class="btn btn-sm btn-soft-primary" type="submit">{{ $doc ? 'Replace' : 'Upload' }}</button>
                                </form>
                            @endunless
                        </li>
                    @endforeach
                </ul>
            </div>

            <div class="card">
                <div class="card-header"><h6 class="card-title mb-0">5. Physical verification</h6></div>
                <div class="card-body text-muted">
                    @if ($student->verified_at)
                        Verified on {{ $student->verified_at->format('d M Y') }}.
                    @else
                        When the steps above are done, report to the university on your scheduled date with all your <strong>original</strong> documents. A verification officer will confirm you.
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
