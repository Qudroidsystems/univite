@extends('layouts.master')

@section('content')
<div class="main-content">
    <div class="page-content">
        <div class="container-fluid" style="max-width:720px">
            <div class="d-flex justify-content-between align-items-center mb-3 d-print-none">
                <h4 class="mb-0">Clearance slip</h4>
                <div class="d-flex gap-2"><a href="{{ ($mine ?? false) ? route('my.clearance') : route('clearance.show', $student->id) }}" class="btn btn-light">Back</a><button class="btn btn-primary" onclick="window.print()"><i class="ri-printer-line me-1"></i>Print</button></div>
            </div>
            <div class="card">
                <div class="card-body">
                    @include('partials.letterhead', ['subtitle' => 'Clearance slip'])
                    <table class="table table-sm">
                        <tr><th style="width:35%">Name</th><td>{{ $student->full_name }}</td></tr>
                        <tr><th>Matric number</th><td>{{ $student->matric_label }}</td></tr>
                        <tr><th>Programme</th><td>{{ $student->programme->name }}</td></tr>
                        <tr><th>Entry session</th><td>{{ $student->entrySession->name }}</td></tr>
                        <tr><th>Cleared on</th><td>{{ $student->cleared_at->format('j F Y') }}</td></tr>
                    </table>
                    <table class="table table-sm mb-0">
                        <thead class="table-light"><tr><th>Item</th><th>Status</th><th>Signed by</th><th>Date</th></tr></thead>
                        <tbody>
                            @foreach ($rows as $r)
                                <tr><td>{{ $r->item->name }}</td><td>{{ \App\Models\StudentClearance::STATUSES[$r->status] }}</td><td>{{ $r->decider?->name ?? ($r->item->isAuto() ? 'Automatic check' : '—') }}</td><td>{{ $r->decided_at?->format('j M Y') }}</td></tr>
                            @endforeach
                        </tbody>
                    </table>
                    @include('partials.signatory', ['role' => 'registrar'])
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
