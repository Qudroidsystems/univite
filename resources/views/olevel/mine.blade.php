@extends('layouts.master')

@section('content')
@php
    use App\Models\StudentOlevelSitting as S;
    $form = $editing;
    $rows = old('subjects', $form ? $form->results->whereNotNull('grade')->map(fn ($r) => ['subject' => $r->subject, 'grade' => $r->grade])->values()->all() : []);
    $rows = array_pad($rows, max(9, count($rows)), ['subject' => '', 'grade' => '']);
@endphp
<div class="main-content"><div class="page-content"><div class="container-fluid">
    @include('partials.page-title', ['title' => 'My O Level results', 'icon' => 'ri-file-list-3-line', 'subtitle' => 'WAEC, NECO or NABTEB results the school confirms before you register courses', 'back' => [route('dashboard'), 'Back']])
    @include('academics.partials.alerts')

    <div class="row">
        <div class="col-xl-4">
            <div class="card {{ $check->allowsRegistration() ? 'border-success-subtle' : 'border-warning-subtle' }}">
                <div class="card-body">
                    <div class="d-flex align-items-center gap-2 mb-2">
                        <i class="fs-2 {{ $check->met ? 'ri-checkbox-circle-fill text-success' : ($check->allowsRegistration() ? 'ri-information-line text-info' : 'ri-time-line text-warning') }}"></i>
                        <div><h6 class="mb-0">{{ $check->status() }}</h6>
                            <div class="text-muted fs-12">{{ $student->programme?->department?->name }}</div></div>
                    </div>
                    @if ($requirement)
                        <div class="fs-13"><span class="text-muted">The department needs:</span> {{ $requirement->summary() }}</div>
                        @if ($requirement->note)<div class="fs-12 text-muted mt-1">{{ $requirement->note }}</div>@endif
                        @if ($check->problems && $check->hasConfirmed)<ul class="fs-13 text-danger mt-2 mb-0 ps-3">@foreach ($check->problems as $p)<li>{{ $p }}</li>@endforeach</ul>@endif
                    @endif
                    @if ($preview && ! $check->hasConfirmed && $requirement)
                        <div class="mt-3 p-2 rounded {{ $preview->met ? 'bg-success-subtle' : 'bg-light' }} fs-13">
                            <strong>With the grades you entered:</strong> {{ $preview->met ? 'you would meet the requirement.' : 'not yet enough.' }}
                            @unless ($preview->met)<ul class="mb-0 ps-3">@foreach ($preview->problems as $p)<li>{{ $p }}</li>@endforeach</ul>@endunless
                        </div>
                    @endif
                    @if ($student->olevel_override_at)<div class="alert alert-info fs-13 mt-3 mb-0">The school has allowed you to register meanwhile: {{ $student->olevel_override_note }}</div>@endif
                </div>
            </div>

            <div class="card">
                <div class="card-header"><h6 class="card-title mb-0">{{ $form ? 'Change ' . $form->label() : 'Add a sitting' }}</h6>
                    <div class="text-muted fs-12">Enter each subject and grade exactly as on your result, and upload the result slip or certificate (PDF or a clear photo).@if ($aiOn) We read the upload automatically and show what we find beside your grades.@endif</div></div>
                <form method="POST" enctype="multipart/form-data" action="{{ $form ? route('my.olevel.update', $form->id) : route('my.olevel.store') }}" class="card-body">
                    @csrf @if ($form) @method('PUT') @endif
                    <div class="row g-2">
                        <div class="col-6"><label class="form-label mb-1">Examination</label><select name="exam_type" class="form-select form-select-sm" required>@foreach ($exams as $v => $l)<option value="{{ $v }}" @selected(old('exam_type', $form?->exam_type) === $v)>{{ $l }}</option>@endforeach</select></div>
                        <div class="col-6"><label class="form-label mb-1">Year</label><input type="number" name="exam_year" min="1980" max="{{ now()->year }}" value="{{ old('exam_year', $form?->exam_year) }}" class="form-control form-control-sm" required></div>
                        <div class="col-6"><label class="form-label mb-1">Examination number</label><input name="exam_number" value="{{ old('exam_number', $form?->exam_number) }}" class="form-control form-control-sm" required maxlength="30"></div>
                        <div class="col-6"><label class="form-label mb-1">Centre <span class="text-muted">(optional)</span></label><input name="centre" value="{{ old('centre', $form?->centre) }}" class="form-control form-control-sm" maxlength="150"></div>
                    </div>
                    <datalist id="olevel-subjects">@foreach ($subjects as $s)<option value="{{ $s }}">@endforeach</datalist>
                    <table class="table table-sm align-middle mt-3 mb-2"><thead><tr><th>Subject</th><th style="width:30%">Grade</th></tr></thead><tbody>
                        @foreach ($rows as $i => $r)
                            <tr><td><input name="subjects[{{ $i }}][subject]" value="{{ $r['subject'] }}" list="olevel-subjects" class="form-control form-control-sm" placeholder="{{ $i === 0 ? 'English Language' : ($i === 1 ? 'Mathematics' : '') }}"></td>
                                <td><select name="subjects[{{ $i }}][grade]" class="form-select form-select-sm"><option value=""></option>@foreach ($grades as $g)<option @selected($r['grade'] === $g)>{{ $g }}</option>@endforeach</select></td></tr>
                        @endforeach
                    </tbody></table>
                    <label class="form-label mb-1">Result slip or certificate {{ $needsDocument ? '' : '(optional)' }}</label>
                    <input type="file" name="document" accept=".pdf,.jpg,.jpeg,.png" class="form-control form-control-sm">
                    @if ($form?->document_name)<div class="fs-12 text-muted mt-1">Uploaded: {{ $form->document_name }}. Choose a file only to replace it.</div>@endif
                    <div class="d-flex gap-2 justify-content-end mt-3">
                        @if ($form)<a href="{{ route('my.olevel') }}" class="btn btn-light btn-sm">Cancel</a>@endif
                        <button class="btn btn-light btn-sm" type="submit"><i class="ri-save-line me-1"></i>Save</button>
                        <button class="btn btn-primary btn-sm" type="submit" name="submit" value="1"><i class="ri-send-plane-line me-1"></i>Save and submit</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="col-xl-8">
            @forelse ($sittings as $s)
                <div class="card">
                    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                        <div><h6 class="card-title mb-0">{{ $s->label() }}</h6>
                            <div class="fs-12 text-muted">{{ $s->centre }}@if ($s->document_path) · <a href="{{ route('my.olevel.document', $s->id) }}" target="_blank">View uploaded result</a>@else · <span class="text-danger">No result uploaded</span>@endif</div></div>
                        <div class="d-flex gap-1 align-items-center">
                            <span class="badge {{ S::BADGES[$s->status] }}">{{ S::STATUSES[$s->status] }}</span>
                            @if ($s->isEditable())
                                <a href="{{ route('my.olevel', ['edit' => $s->id]) }}" class="btn btn-sm btn-soft-primary">Edit</a>
                                @if ($aiOn && $s->document_path)<form method="POST" action="{{ route('my.olevel.read', $s->id) }}">@csrf<button class="btn btn-sm btn-soft-info" title="Read the uploaded result again"><i class="ri-scan-2-line"></i> Read upload</button></form>@endif
                                <form method="POST" action="{{ route('my.olevel.submit', $s->id) }}">@csrf<button class="btn btn-sm btn-primary">Submit</button></form>
                                <form method="POST" action="{{ route('my.olevel.destroy', $s->id) }}" data-confirm="Remove this sitting?">@csrf @method('DELETE')<button class="btn btn-sm btn-link text-danger">Remove</button></form>
                            @endif
                        </div>
                    </div>
                    @if ($s->status === 'rejected' && $s->review_note)<div class="alert alert-danger rounded-0 mb-0 fs-13">Returned by the school: {{ $s->review_note }}</div>@endif
                    @if ($s->extraction_note)<div class="alert {{ $s->extraction_status === 'done' ? 'alert-info' : 'alert-warning' }} rounded-0 mb-0 fs-13"><i class="ri-scan-2-line me-1"></i>{{ $s->extraction_note }}</div>@endif
                    <div class="table-responsive"><table class="table table-sm align-middle mb-0">
                        <thead class="table-light"><tr><th>Subject</th><th>Your grade</th><th>Read from your upload</th>@if ($s->status === 'confirmed')<th>Confirmed by the school</th>@endif</tr></thead>
                        <tbody>
                        @foreach ($s->results as $r)
                            <tr class="{{ $r->mismatch() ? 'table-warning' : '' }}"><td>{{ $r->subject }}</td><td class="fw-semibold">{{ $r->grade ?? '—' }}</td>
                                <td>@if ($s->extraction_status === 'done'){{ $r->extracted_grade ?? 'not found' }}@if ($r->mismatch()) <i class="ri-error-warning-line text-warning" title="Not the grade you entered"></i>@elseif ($r->extracted_grade && $r->grade) <i class="ri-check-line text-success"></i>@endif @else<span class="text-muted">—</span>@endif</td>
                                @if ($s->status === 'confirmed')<td class="fw-semibold text-success">{{ $r->confirmed_grade ?? '—' }}</td>@endif</tr>
                        @endforeach
                        </tbody>
                    </table></div>
                    @if ($s->reviewed_at && $s->status === 'confirmed')<div class="card-footer fs-12 text-muted">Confirmed by {{ $s->reviewer?->name }} on {{ $s->reviewed_at->format('j M Y') }}@if ($s->review_note) · {{ $s->review_note }}@endif</div>@endif
                </div>
            @empty
                <div class="card"><div class="card-body text-center text-muted py-5"><i class="ri-file-list-3-line fs-1 d-block mb-2"></i>Add your O Level result (one card per sitting) with the form on the left.</div></div>
            @endforelse
        </div>
    </div>
</div></div></div>
@endsection
