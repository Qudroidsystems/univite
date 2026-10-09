@extends('layouts.master')

@section('content')
<div class="main-content">
    <div class="page-content">
        <div class="container-fluid" style="max-width:900px">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h4 class="mb-0">{{ $form->student->full_name }} <span class="badge {{ \App\Models\RegistrationForm::BADGES[$form->status] }} fs-12 align-middle">{{ \App\Models\RegistrationForm::STATUSES[$form->status] }}</span></h4>
                <a href="{{ route('registrations.index') }}" class="btn btn-light">Back</a>
            </div>
            @include('academics.partials.alerts')
            <div class="card">
                <div class="card-body">
                    <p class="mb-2 text-muted">{{ $form->student->matric_label }} · {{ $form->student->programme->name }} · {{ $form->level }} level · {{ $form->session->name }} {{ $form->semesterType->name }}</p>
                    <table class="table table-sm mb-0">
                        <thead class="table-light"><tr><th>Code</th><th>Title</th><th>Status</th><th class="text-end">Units</th></tr></thead>
                        <tbody>
                        @foreach ($form->courses->sortBy('course.code') as $r)
                            <tr><td>{{ $r->course->code }}</td><td>{{ $r->course->title }}@if ($r->is_carryover) <span class="badge bg-danger-subtle text-danger">Carry-over</span>@endif</td><td>{{ \App\Support\Lookups::label('course_status', $r->course_status) }}</td><td class="text-end">{{ $r->credit_units }}</td></tr>
                        @endforeach
                        </tbody>
                        <tfoot><tr><th colspan="3" class="text-end">Total</th><th class="text-end">{{ $form->total_units }}</th></tr></tfoot>
                    </table>
                    @if ($form->note)<div class="alert alert-light border mt-3 mb-0">Note: {{ $form->note }}</div>@endif
                </div>
            </div>
            @can('Approve course registrations')
                @if ($form->status === 'submitted')
                    <div class="card"><form method="POST" action="{{ route('registrations.decide', $form->id) }}" class="card-body d-flex flex-wrap gap-2 align-items-start">
                        @csrf
                        <input name="note" class="form-control" style="flex:1;min-width:240px" placeholder="Reason (needed when returning the form)">
                        <button class="btn btn-success" name="decision" value="approve"><i class="ri-check-line me-1"></i>Approve</button>
                        <button class="btn btn-danger" name="decision" value="return"><i class="ri-arrow-go-back-line me-1"></i>Return to student</button>
                    </form></div>
                @elseif (in_array($form->status, ['approved', 'rejected']))
                    <form method="POST" action="{{ route('registrations.reopen', $form->id) }}" data-confirm="Let the student change this form again?">@csrf<button class="btn btn-soft-warning"><i class="ri-edit-line me-1"></i>Reopen for the student</button></form>
                @endif
            @endcan
        </div>
    </div>
</div>
@endsection
