@extends('layouts.master')

@section('content')
<div class="main-content">
    <div class="page-content">
        <div class="container-fluid" style="max-width:780px">
            <div class="d-flex justify-content-between align-items-center mb-3 d-print-none">
                <h4 class="mb-0">Course registration form</h4>
                <div class="d-flex gap-2"><a href="{{ url()->previous() }}" class="btn btn-light">Back</a><button class="btn btn-primary" onclick="window.print()"><i class="ri-printer-line me-1"></i>Print</button></div>
            </div>
            <div class="card"><div class="card-body">
                <div class="text-center mb-4"><h4 class="mb-0">{{ config('app.name') }}</h4><div class="text-muted">Course registration form · {{ $form->session->name }} · {{ $form->semesterType->name }}</div></div>
                <table class="table table-sm">
                    <tr><th style="width:30%">Name</th><td>{{ $form->student->full_name }}</td></tr>
                    <tr><th>Matric number</th><td>{{ $form->student->matric_label }}</td></tr>
                    <tr><th>Programme</th><td>{{ $form->student->programme->name }}</td></tr>
                    <tr><th>Level</th><td>{{ $form->level }}</td></tr>
                </table>
                <table class="table table-sm">
                    <thead class="table-light"><tr><th>Code</th><th>Title</th><th>Status</th><th class="text-end">Units</th></tr></thead>
                    <tbody>
                        @foreach ($form->courses->sortBy('course.code') as $r)
                            <tr><td>{{ $r->course->code }}</td><td>{{ $r->course->title }}@if ($r->is_carryover) (carry-over)@endif</td><td>{{ \App\Support\Lookups::label('course_status', $r->course_status) }}</td><td class="text-end">{{ $r->credit_units }}</td></tr>
                        @endforeach
                    </tbody>
                    <tfoot><tr><th colspan="3" class="text-end">Total units</th><th class="text-end">{{ $form->total_units }}</th></tr></tfoot>
                </table>
                <p class="text-muted fs-12 mb-0">Approved {{ $form->decided_at?->format('j F Y') }}{{ $form->decider ? ' by ' . $form->decider->name : '' }}.</p>
            </div></div>
        </div>
    </div>
</div>
@endsection
