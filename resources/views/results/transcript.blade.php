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
                <div class="text-center mb-3"><h4 class="mb-0">{{ config('app.name') }}</h4><div class="text-muted">{{ $mine ? 'Statement of results' : 'Academic transcript' }}</div></div>
                <table class="table table-sm">
                    <tr><th style="width:25%">Name</th><td>{{ $student->full_name }}</td><th style="width:20%">Matric number</th><td>{{ $student->matric_label }}</td></tr>
                    <tr><th>Programme</th><td>{{ $student->programme->name }}</td><th>Entry session</th><td>{{ $student->entrySession->name }}</td></tr>
                </table>

                @forelse ($t['terms'] as $term)
                    <h6 class="mt-4">{{ $term['session']->name }} · {{ $term['semester']->name }}</h6>
                    <table class="table table-sm">
                        <thead class="table-light"><tr><th>Code</th><th>Title</th><th class="text-end">Units</th><th class="text-end">Score</th><th>Grade</th><th class="text-end">Points</th></tr></thead>
                        <tbody>
                        @foreach ($term['results'] as $r)
                            <tr><td>{{ $r->course->code }}</td><td>{{ $r->course->title }}</td><td class="text-end">{{ $r->credit_units }}</td><td class="text-end">{{ $r->total }}</td><td>{{ $r->grade }}</td><td class="text-end">{{ rtrim(rtrim(number_format($r->points, 2), '0'), '.') }}</td></tr>
                        @endforeach
                        </tbody>
                        <tfoot><tr><th colspan="2" class="text-end">Semester GPA</th><th class="text-end">{{ $term['units'] }}</th><th colspan="3" class="text-end">{{ number_format($term['gpa'], 2) }}</th></tr></tfoot>
                    </table>
                @empty
                    <p class="text-center text-muted my-5">No results have been published yet.</p>
                @endforelse

                @if ($t['terms']->isNotEmpty())
                    <div class="border rounded p-3 bg-light d-flex flex-wrap gap-4">
                        <div><div class="text-muted fs-12">CGPA</div><div class="fs-4 fw-semibold">{{ number_format($t['cgpa'], 2) }}</div></div>
                        <div><div class="text-muted fs-12">Units attempted</div><div class="fs-4 fw-semibold">{{ $t['units'] }}</div></div>
                        <div><div class="text-muted fs-12">Units passed</div><div class="fs-4 fw-semibold">{{ $t['earned'] }}</div></div>
                        @if ($t['class'])<div><div class="text-muted fs-12">Standing</div><div class="fs-4 fw-semibold">{{ $t['class'] }}</div></div>@endif
                    </div>
                @endif
            </div></div>
        </div>
    </div>
</div>
@endsection
