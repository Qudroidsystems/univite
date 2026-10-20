@extends('layouts.master')

@section('content')
@php
    use App\Models\StudentOlevelSitting as S;
    $isImage = str_starts_with((string) $sitting->document_mime, 'image/');
    $ex = $sitting->extracted ?? [];
@endphp
<div class="main-content"><div class="page-content"><div class="container-fluid">
    @include('partials.page-title', ['title' => $student->full_name . ' · O Level', 'icon' => 'ri-file-list-3-line',
        'subtitle' => ($student->matric_number ?? $student->provisional_matric) . ' · ' . $student->programme?->name . ' · ' . $student->programme?->department?->name . ' · entered at ' . $student->entry_level . ' level, now ' . $student->current_level,
        'back' => [route('olevel.index'), 'All submissions']])
    @include('academics.partials.alerts')

    <div class="row">
        <div class="col-xl-5">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center"><h6 class="card-title mb-0">Uploaded result</h6>
                    @if ($sitting->document_path)<a href="{{ route('olevel.document', $sitting->id) }}" target="_blank" class="btn btn-sm btn-light"><i class="ri-external-link-line"></i> Open</a>@endif</div>
                <div class="card-body p-2 text-center bg-light">
                    @if (! $sitting->document_path)
                        <div class="text-danger py-5">The student did not upload the result.</div>
                    @elseif ($isImage)
                        <img src="{{ route('olevel.document', $sitting->id) }}" alt="Uploaded result" class="img-fluid rounded" style="max-height:640px">
                    @else
                        <iframe src="{{ route('olevel.document', $sitting->id) }}" title="Uploaded result" style="width:100%;height:640px;border:0"></iframe>
                    @endif
                </div>
                @if ($sitting->extraction_status === 'done' && $ex)
                    <div class="card-footer fs-13">
                        <div class="text-muted fs-12 text-uppercase fw-semibold mb-1">Read from the document</div>
                        <div>{{ $ex['candidate_name'] ?? 'Name not read' }} · {{ $ex['exam_number'] ?? '—' }} · {{ $ex['exam_year'] ?? '—' }} · {{ strtoupper((string) ($ex['exam_type'] ?? '')) }}</div>
                        @if (! empty($ex['candidate_name']) && ! str_contains(strtolower($ex['candidate_name']), strtolower($student->last_name)))<div class="text-danger"><i class="ri-error-warning-line"></i> The name on the document does not contain the student's surname ({{ $student->last_name }}).</div>@endif
                    </div>
                @endif
            </div>
        </div>

        <div class="col-xl-7">
            <div class="card">
                <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <div><h6 class="card-title mb-0">{{ $sitting->label() }}</h6><div class="fs-12 text-muted">{{ $sitting->centre }} · submitted {{ $sitting->submitted_at?->format('j M Y, g:i a') ?? '—' }}</div></div>
                    <div class="d-flex gap-1 align-items-center"><span class="badge {{ S::BADGES[$sitting->status] }}">{{ S::STATUSES[$sitting->status] }}</span>
                        @if ($canVerify && $aiOn && $sitting->document_path)<form method="POST" action="{{ route('olevel.read', $sitting->id) }}">@csrf<button class="btn btn-sm btn-soft-info"><i class="ri-scan-2-line"></i> {{ $sitting->extraction_status === 'done' ? 'Read again' : 'Read with AI' }}</button></form>@endif</div>
                </div>
                @if ($sitting->extraction_note)<div class="alert {{ $sitting->extraction_status === 'done' ? 'alert-info' : 'alert-warning' }} rounded-0 mb-0 fs-13"><i class="ri-scan-2-line me-1"></i>{{ $sitting->extraction_note }}</div>@endif
                <form method="POST" action="{{ route('olevel.confirm', $sitting->id) }}" id="confirm-form">
                    @csrf
                    <div class="table-responsive"><table class="table align-middle mb-0">
                        <thead class="table-light"><tr><th>Subject</th><th class="text-center">Student entered</th><th class="text-center">Read from upload</th><th style="width:22%">Grade to keep</th></tr></thead>
                        <tbody>
                        @foreach ($sitting->results as $r)
                            @php $keep = old("grades.{$r->id}", $r->confirmed_grade ?? ($r->grade ?? $r->extracted_grade)); @endphp
                            <tr class="{{ $r->mismatch() ? 'table-warning' : '' }}">
                                <td>{{ $r->subject }}@if (! $r->grade && $r->extracted_grade)<div class="fs-12 text-warning">On the document, not entered by the student</div>@endif</td>
                                <td class="text-center fw-semibold"><button type="button" class="btn btn-sm btn-light uv-pick" data-row="{{ $r->id }}" data-grade="{{ $r->grade }}" @disabled(! $r->grade)>{{ $r->grade ?? '—' }}</button></td>
                                <td class="text-center fw-semibold">@if ($sitting->extraction_status === 'done')<button type="button" class="btn btn-sm btn-light uv-pick" data-row="{{ $r->id }}" data-grade="{{ $r->extracted_grade }}" @disabled(! $r->extracted_grade)>{{ $r->extracted_grade ?? 'not found' }}</button>@else<span class="text-muted">—</span>@endif
                                    @if ($r->mismatch())<i class="ri-error-warning-line text-warning ms-1" title="The grades differ"></i>@endif</td>
                                <td><select name="grades[{{ $r->id }}]" id="g{{ $r->id }}" class="form-select form-select-sm" @disabled(! $canVerify)><option value="">Leave out</option>@foreach ($grades as $g)<option @selected($keep === $g)>{{ $g }}</option>@endforeach</select></td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table></div>
                    @if ($canVerify)
                        <div class="card-body border-top">
                            <input type="hidden" name="source" value="mixed" id="source">
                            <div class="d-flex flex-wrap gap-2 mb-2">
                                <button type="button" class="btn btn-sm btn-soft-secondary" data-fill="student"><i class="ri-user-line me-1"></i>Use all the student's grades</button>
                                @if ($sitting->extraction_status === 'done')<button type="button" class="btn btn-sm btn-soft-info" data-fill="extracted"><i class="ri-scan-2-line me-1"></i>Use all the grades read from the upload</button>@endif
                            </div>
                            <textarea name="note" rows="2" class="form-control form-control-sm mb-2" placeholder="Note (optional when confirming, required when returning)">{{ old('note') }}</textarea>
                            <div class="d-flex flex-wrap gap-2 justify-content-end">
                                <button class="btn btn-outline-danger btn-sm" type="submit" formaction="{{ route('olevel.reject', $sitting->id) }}"><i class="ri-arrow-go-back-line me-1"></i>Return to student</button>
                                <button class="btn btn-success btn-sm" type="submit"><i class="ri-check-double-line me-1"></i>{{ $sitting->status === 'confirmed' ? 'Confirm again with these grades' : 'Confirm these grades' }}</button>
                            </div>
                        </div>
                    @endif
                </form>
            </div>

            <div class="card">
                <div class="card-header"><h6 class="card-title mb-0">Against {{ $student->programme?->department?->name }}'s requirement</h6>
                    <div class="fs-12 text-muted">{{ $requirement ? $requirement->summary() : 'No O Level requirement is set for this department or programme.' }}@if ($requirement && ! $requirement->is_active) (switched off)@endif</div></div>
                <div class="card-body">
                    <div class="row g-2">
                        @foreach ([['Confirmed so far', $check], ['With the student\'s grades', $withStudent], ['With the grades read from uploads', $withExtracted]] as [$label, $c])
                            @continue(! $c)
                            <div class="col-md-4"><div class="p-2 rounded h-100 {{ $c->met ? 'bg-success-subtle' : 'bg-light' }}">
                                <div class="fs-12 text-uppercase fw-semibold text-muted">{{ $label }}</div>
                                <div class="fw-semibold">{{ $c->met ? 'Meets it' : 'Not met' }} · {{ $c->credits }} credits</div>
                                @unless ($c->met)<ul class="fs-12 ps-3 mb-0">@foreach ($c->problems as $p)<li>{{ $p }}</li>@endforeach</ul>@endunless
                            </div></div>
                        @endforeach
                    </div>
                    @if ($canVerify)
                        <form method="POST" action="{{ route('olevel.override', $student->id) }}" class="d-flex flex-wrap gap-2 align-items-center mt-3">
                            @csrf
                            @if ($student->olevel_override_at)
                                <input type="hidden" name="allow" value="0">
                                <span class="fs-13">Allowed to register meanwhile: {{ $student->olevel_override_note }}</span>
                                <button class="btn btn-sm btn-link text-danger">Take back</button>
                            @else
                                <input type="hidden" name="allow" value="1">
                                <input name="note" class="form-control form-control-sm flex-grow-1" style="max-width:420px" placeholder="Reason, e.g. awaiting NECO result, original seen" required>
                                <button class="btn btn-sm btn-soft-warning">Let the student register meanwhile</button>
                            @endif
                        </form>
                    @endif
                </div>
            </div>

            @if ($all->count() > 1)
                <div class="card"><div class="card-header"><h6 class="card-title mb-0">All of this student's sittings</h6></div>
                    <ul class="list-group list-group-flush">@foreach ($all as $o)<li class="list-group-item d-flex justify-content-between"><a href="{{ route('olevel.show', $o->id) }}">{{ $o->label() }}</a><span class="badge {{ S::BADGES[$o->status] }}">{{ S::STATUSES[$o->status] }}</span></li>@endforeach</ul></div>
            @endif
        </div>
    </div>
</div></div></div>

@push('scripts')
<script>
(function () {
    document.querySelectorAll('.uv-pick').forEach(function (b) {
        b.addEventListener('click', function () { var s = document.getElementById('g' + b.dataset.row); if (s && b.dataset.grade) { s.value = b.dataset.grade; document.getElementById('source').value = 'mixed'; } });
    });
    document.querySelectorAll('[data-fill]').forEach(function (b) {
        b.addEventListener('click', function () {
            var kind = b.dataset.fill;
            document.querySelectorAll('.uv-pick').forEach(function (p) {
                var col = p.closest('td').cellIndex; // 1 = student, 2 = upload
                if ((kind === 'student' && col === 1) || (kind === 'extracted' && col === 2)) { var s = document.getElementById('g' + p.dataset.row); if (s) s.value = p.dataset.grade || ''; }
            });
        });
    });
})();
</script>
@endpush
@endsection
