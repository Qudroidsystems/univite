@extends('layouts.master')

@section('content')
@php
    $field = fn ($k) => old($k, $student->{$k} instanceof \Carbon\Carbon ? $student->{$k}->format('Y-m-d') : $student->{$k});
    $ro = $canUpdate ? '' : 'readonly';
    $refs = $student->referees->keyBy('position');
    $row = fn ($label, $value) => '<li class="list-group-item d-flex justify-content-between gap-3"><span class="text-muted">' . e($label) . '</span><span class="text-end">' . ($value !== null && $value !== '' ? e($value) : '—') . '</span></li>';
@endphp
<div class="main-content"><div class="page-content"><div class="container-fluid">
    @include('partials.page-title', ['title' => 'My profile', 'icon' => 'ri-user-line', 'subtitle' => $canUpdate ? 'Keep your contact details, next of kin and referees up to date.' : 'The school holds these details about you. Ask the registry to change anything that is wrong.'])
    @include('academics.partials.alerts')
    @if ($errors->any())<div class="alert alert-danger">{{ $errors->first() }}</div>@endif

    <div class="row g-3">
        <div class="col-lg-4">
            <div class="card"><div class="card-header"><h6 class="card-title mb-0">Academic record</h6></div>
                <ul class="list-group list-group-flush">
                    {!! $row('Name', $student->full_name) !!}
                    {!! $row('Matric number', $student->matric_label) !!}
                    @if ($settings->on('show_jamb')){!! $row('JAMB registration no.', $student->jamb_reg_no) !!}@endif
                    {!! $row('Programme', $student->programme->name) !!}
                    {!! $row('Course option', $student->courseOption?->name) !!}
                    {!! $row('Department', $student->programme->department->name) !!}
                    {!! $row('Level', $student->current_level . ' Level') !!}
                    {!! $row('Entry session', $student->entrySession?->name) !!}
                </ul>
            </div>
        </div>
        <div class="col-lg-8">
            <form method="POST" action="{{ route('my.profile.update') }}" enctype="multipart/form-data">@csrf @method('PUT')
                <div class="card"><div class="card-header"><h6 class="card-title mb-0">Contact and personal details</h6></div>
                    <div class="card-body row g-3">
                        <div class="col-md-4"><label class="form-label">Phone number</label><input name="phone_number" value="{{ $field('phone_number') }}" class="form-control" {{ $ro }}></div>
                        @include('partials.student-extra-fields')
                        <div class="col-12"><label class="form-label">Home address</label><textarea name="address" rows="2" class="form-control" {{ $ro }}>{{ $field('address') }}</textarea></div>
                    </div>
                </div>
                <div class="card"><div class="card-header"><h6 class="card-title mb-0">Guardian / next of kin</h6></div>
                    <div class="card-body row g-3">
                        <div class="col-md-5"><label class="form-label">Name</label><input name="guardian_name" value="{{ $field('guardian_name') }}" class="form-control" {{ $ro }}></div>
                        <div class="col-md-3"><label class="form-label">Relationship</label><input name="guardian_relationship" value="{{ $field('guardian_relationship') }}" class="form-control" {{ $ro }}></div>
                        <div class="col-md-4"><label class="form-label">Phone</label><input name="guardian_phone" value="{{ $field('guardian_phone') }}" class="form-control" {{ $ro }}></div>
                        <div class="col-12"><label class="form-label">Address</label><textarea name="guardian_address" rows="2" class="form-control" {{ $ro }}>{{ $field('guardian_address') }}</textarea></div>
                    </div>
                </div>
                @if ($refereeCount)
                    <div class="card"><div class="card-header"><h6 class="card-title mb-0">Referees</h6></div>
                        <div class="card-body">
                            @for ($i = 1; $i <= $refereeCount; $i++)
                                <div class="row g-2 mb-3">
                                    <div class="col-12 fw-semibold fs-13">Referee {{ $i }}</div>
                                    <div class="col-md-6"><input name="referees[{{ $i }}][name]" value="{{ old("referees.$i.name", $refs[$i]->name ?? '') }}" class="form-control" placeholder="Name" {{ $ro }}></div>
                                    <div class="col-md-6"><input name="referees[{{ $i }}][occupation]" value="{{ old("referees.$i.occupation", $refs[$i]->occupation ?? '') }}" class="form-control" placeholder="Occupation" {{ $ro }}></div>
                                    <div class="col-md-6"><input name="referees[{{ $i }}][phone]" value="{{ old("referees.$i.phone", $refs[$i]->phone ?? '') }}" class="form-control" placeholder="Phone" {{ $ro }}></div>
                                    <div class="col-md-6"><input type="email" name="referees[{{ $i }}][email]" value="{{ old("referees.$i.email", $refs[$i]->email ?? '') }}" class="form-control" placeholder="Email" {{ $ro }}></div>
                                    <div class="col-12"><input name="referees[{{ $i }}][address]" value="{{ old("referees.$i.address", $refs[$i]->address ?? '') }}" class="form-control" placeholder="Address" {{ $ro }}></div>
                                </div>
                            @endfor
                        </div>
                    </div>
                @endif
                <div class="card"><div class="card-header"><h6 class="card-title mb-0">Signature</h6></div>
                    <div class="card-body d-flex flex-wrap gap-3 align-items-center">
                        @if ($student->signature_url)<img src="{{ $student->signature_url }}" alt="Signature" class="border rounded bg-white p-1" style="max-height:60px;">@endif
                        @if ($canUpdate)<div><input type="file" name="signature" class="form-control" accept="image/png,image/jpeg"><div class="form-text">Sign on white paper and upload a clear photo (JPG or PNG, up to 1 MB).</div></div>@endif
                    </div>
                </div>
                @if ($canUpdate)<button class="btn btn-primary"><i class="ri-save-line me-1"></i>Save my profile</button>@endif
            </form>
        </div>
    </div>
</div></div></div>
@endsection
