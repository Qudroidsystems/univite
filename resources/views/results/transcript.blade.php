@extends('layouts.master')

@section('content')
<div class="main-content">
    <div class="page-content">
        <div class="container-fluid" style="max-width:880px">
            <div class="d-flex justify-content-between align-items-center mb-3 d-print-none">
                <h4 class="mb-0">{{ $mine ? 'My results' : 'Transcript' }}</h4>
                <div class="d-flex gap-2"><a href="{{ $mine ? route('dashboard') : route('students.show', $student->id) }}" class="btn btn-light">Back</a><button class="btn btn-primary" onclick="window.print()"><i class="ri-printer-line me-1"></i>Print</button></div>
            </div>
            <div class="card"><div class="card-body">
                @include('partials.letterhead', ['subtitle' => ($mine ? 'Statement of results' : 'Academic transcript')])
                @include('academics.partials.alerts')
                <table class="table table-sm">
                    <tr><th style="width:25%">Name</th><td>{{ $student->full_name }}</td><th style="width:20%">Matric number</th><td>{{ $student->matric_label }}</td></tr>
                    <tr><th>Programme</th><td>{{ $student->programme->name }}</td><th>Entry session</th><td>{{ $student->entrySession->name }}</td></tr>
                    @if ($student->graduated_at)
                        <tr><th>Graduated</th><td>{{ $student->graduated_at->format('j M Y') }}</td><th>Class of degree</th><td>{{ $student->degree_class ?? '—' }} (CGPA {{ number_format($student->final_cgpa, 2) }})</td></tr>
                    @endif
                </table>

                @if ($bf = $t->broughtForward)
                    <h6 class="mt-4">Brought forward from earlier records{{ $bf->asOf ? ' (to the end of ' . $bf->asOf . ')' : '' }}</h6>
                    <table class="table table-sm"><tbody>
                        <tr><th style="width:25%">Total units (TNU)</th><td>{{ $bf->tnu }}</td><th style="width:25%">Total credit points (TCP)</th><td>{{ rtrim(rtrim(number_format($bf->tcp, 2), '0'), '.') }}</td></tr>
                        <tr><th>CGPA at that point</th><td>{{ number_format($bf->cgpa, 2) }}</td><th>Units passed</th><td>{{ $bf->unitsPassed ?? '—' }}</td></tr>
                        @if ($bf->note)<tr><th>Note</th><td colspan="3">{{ $bf->note }}</td></tr>@endif
                    </tbody></table>
                @endif

                @forelse ($t->terms as $term)
                    <h6 class="mt-4">{{ $term->session->name }} · {{ $term->semester->name }}</h6>
                    <table class="table table-sm">
                        <thead class="table-light"><tr><th>Code</th><th>Title</th><th>Type and department</th><th class="text-end">Units</th><th class="text-end">Score</th><th>Grade</th><th class="text-end">Points</th>@if ($mine)<th class="d-print-none"></th>@endif</tr></thead>
                        <tbody>
                        @foreach ($term->results as $r)
                            <tr><td>{{ $r->course->code }}</td><td>{{ $r->course->title }}</td><td><x-course-tag :course="$r->course" /></td><td class="text-end">{{ $r->credit_units }}</td><td class="text-end">{{ $r->total }}</td><td>{{ $r->grade }}</td><td class="text-end">{{ rtrim(rtrim(number_format($r->points, 2), '0'), '.') }}</td>
                                @if ($mine)
                                    @php $q = $complaints->get($r->id)?->first(); @endphp
                                    <td class="d-print-none text-end">
                                        @if ($q)<span class="badge {{ \App\Models\ResultComplaint::BADGES[$q->status] }}">Query: {{ \App\Models\ResultComplaint::STATUSES[$q->status] }}</span>@endif
                                        @if ($canQuery($r))<button type="button" class="btn btn-sm btn-link p-0 ms-1" data-bs-toggle="collapse" data-bs-target="#query{{ $r->id }}">Query this result</button>@endif
                                    </td>
                                @endif
                            </tr>
                            @if ($mine && $q && $q->response)
                                <tr class="d-print-none"><td colspan="8" class="fs-12 text-muted border-0 pt-0">Answer to your query: {{ $q->response }}</td></tr>
                            @endif
                            @if ($mine && $canQuery($r))
                                <tr class="collapse d-print-none" id="query{{ $r->id }}"><td colspan="8">
                                    <form method="POST" action="{{ route('my.results.query', $r->id) }}" class="d-flex gap-2">
                                        @csrf
                                        <input name="reason" class="form-control form-control-sm" maxlength="2000" placeholder="What do you think is wrong with this result? (e.g. my CA score is missing)" required>
                                        <button class="btn btn-sm btn-primary text-nowrap" type="submit">Send query</button>
                                    </form>
                                </td></tr>
                            @endif
                        @endforeach
                        </tbody>
                        <tfoot><tr><th colspan="3" class="text-end">Semester GPA</th><th class="text-end">{{ $term->units }}</th><th colspan="3" class="text-end">{{ number_format($term->gpa, 2) }}</th>@if ($mine)<th class="d-print-none"></th>@endif</tr></tfoot>
                    </table>
                @empty
                    @unless ($t->broughtForward)<p class="text-center text-muted my-5">No results have been published yet.</p>@endunless
                @endforelse

                @if ($t->hasRecords())
                    <div class="border rounded p-3 bg-light d-flex flex-wrap gap-4">
                        <div><div class="text-muted fs-12">CGPA</div><div class="fs-4 fw-semibold">{{ number_format($t->cgpa, 2) }}</div></div>
                        <div><div class="text-muted fs-12">{{ $t->method === 'all' ? 'TNU (all attempts)' : 'Units attempted' }}</div><div class="fs-4 fw-semibold">{{ $t->units }}</div></div>
                        <div><div class="text-muted fs-12">TCP</div><div class="fs-4 fw-semibold">{{ rtrim(rtrim(number_format($t->tcp, 2), '0'), '.') }}</div></div>
                        <div><div class="text-muted fs-12">Units passed</div><div class="fs-4 fw-semibold">{{ $t->earned }}</div></div>
                        @if ($t->class)<div><div class="text-muted fs-12">Class</div><div class="fs-4 fw-semibold">{{ $t->class }}</div></div>@endif
                        @if ($t->standing)<div><div class="text-muted fs-12">Academic standing</div><div class="fs-4 fw-semibold text-danger">{{ $t->standing }}</div></div>@endif
                    </div>
                @endif
                @unless ($mine)@include('partials.signatory', ['role' => 'registrar'])@endunless
            </div></div>
        </div>
    </div>
</div>
@endsection
