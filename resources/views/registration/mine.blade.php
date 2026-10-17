@extends('layouts.master')

@section('content')
@php
    $editable = $form && $form->isEditable() && ! $blockers;
    // a fresh form starts with the compulsory courses and carry-overs ticked
    $fresh = ! $form || ($form->courses->isEmpty() && $form->status === 'draft');
    $chosen = old('courses', $fresh ? collect($offered)->filter(fn ($o) => ! $o['blocked'] && ($o['carryover'] || $o['status'] === \App\Support\CourseType::COMPULSORY))->map(fn ($o) => $o['course']->id)->values()->all() : $form->courses->pluck('course_id')->all());
@endphp
<div class="main-content">
    <div class="page-content">
        <div class="container-fluid">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h4 class="mb-0">Course registration</h4>
                <a href="{{ route('dashboard') }}" class="btn btn-light">Back</a>
            </div>
            @include('academics.partials.alerts')

            @if (! $ctx)
                <div class="alert alert-info">No semester is running right now. Registration opens when the university starts a semester.</div>
            @else
                <p class="text-muted">{{ $ctx->session->name }} · {{ $ctx->type->name }} · {{ $student->current_level }} level · {{ $student->programme->name }}</p>
            @endif

            @foreach ($blockers as $b)
                <div class="alert alert-warning mb-2">{{ $b }}</div>
            @endforeach

            @if ($form && $form->status !== 'draft')
                <div class="alert {{ $form->status === 'approved' ? 'alert-success' : ($form->status === 'rejected' ? 'alert-danger' : 'alert-info') }} d-flex justify-content-between align-items-center">
                    <span>
                        <strong>{{ \App\Models\RegistrationForm::STATUSES[$form->status] }}.</strong>
                        @if ($form->status === 'submitted') Your registration is with the approving officer. @endif
                        @if ($form->status === 'rejected') Your form was returned: {{ $form->note }} Change your courses and submit again. @endif
                    </span>
                    @if ($form->status === 'approved')<a href="{{ route('my.registration.print', $form->id) }}" class="btn btn-sm btn-success"><i class="ri-printer-line me-1"></i>Print form</a>@endif
                </div>
            @endif

            @if ($form && ($editable || $form->status !== 'draft' || $form->courses->isNotEmpty()))
            <form method="POST" action="{{ route('my.registration.save') }}" id="reg-form">
                @csrf
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h6 class="card-title mb-0">Courses for this semester</h6>
                        <span class="badge bg-primary-subtle text-primary fs-13"><span id="units">0</span> units · allowed {{ $limits->min }}–{{ $limits->max }}@if ($limits->probation) <span class="text-danger">(reduced: you are on probation)</span>@endif @if ($limits->carryoversExempt) <span class="text-muted">(carry-overs not counted in the maximum)</span>@endif</span>
                    </div>
                    <div class="table-responsive">
                        <table class="table align-middle mb-0">
                            <thead class="table-light"><tr><th style="width:1%"></th><th>Course</th><th>Type and department</th><th class="text-end">Units</th></tr></thead>
                            <tbody>
                            @forelse ($offered as $o)
                                @php $c = $o['course']; $on = in_array($c->id, $chosen); @endphp
                                <tr class="{{ $o['blocked'] ? 'text-muted' : '' }}">
                                    <td><input type="checkbox" class="form-check-input course-box" name="courses[]" value="{{ $c->id }}" data-units="{{ $c->credit_units }}" data-carry="{{ $o['carryover'] ? 1 : 0 }}" @checked($on) @disabled(! $editable || $o['blocked'])></td>
                                    <td><span class="fw-semibold">{{ $c->code }}</span> {{ $c->title }}
                                        @if ($o['carryover'])<span class="badge bg-danger-subtle text-danger ms-1">Carry-over</span>@endif
                                        @if ($o['blocked'])<div class="fs-12 text-danger">{{ $o['blocked'] }}</div>@endif</td>
                                    <td><x-course-tag :course="$c" :type="$o['status']" full /></td>
                                    <td class="text-end">{{ $c->credit_units }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="text-center text-muted py-4">No courses are set up for your programme at this level and semester yet.</td></tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                    @if ($editable && collect($offered)->isNotEmpty())
                        <div class="px-3 pt-2 text-muted fs-12">Compulsory courses must be taken and passed.@if (collect($offered)->contains('status', \App\Support\CourseType::COMPULSORY_ELECTIVE)) From the compulsory electives, choose {{ $ceRule }}.@endif Electives are optional.</div>
                    @endif
                    @if ($editable)
                        @foreach ($problems as $p)<div class="px-3 pt-2 text-danger fs-13">{{ $p }}</div>@endforeach
                        <div class="card-footer d-flex gap-2 justify-content-end">
                            <button class="btn btn-light" type="submit"><i class="ri-save-line me-1"></i>Save selection</button>
                            <button class="btn btn-primary" type="submit" name="submit" value="1" data-confirm="Submit your course registration? You will not be able to change it unless it is returned to you."><i class="ri-send-plane-line me-1"></i>Submit registration</button>
                        </div>
                    @endif
                </div>
            </form>
            @endif

            @if ($history->count() > 1)
                <div class="card"><div class="card-header"><h6 class="card-title mb-0">Earlier registrations</h6></div>
                    <ul class="list-group list-group-flush">
                        @foreach ($history as $h) @continue($form && $h->id === $form->id)
                            <li class="list-group-item d-flex justify-content-between"><span>{{ $h->session->name }} · {{ $h->semesterType->name }} · {{ $h->total_units }} units</span>
                                <span>@if ($h->status === 'approved')<a href="{{ route('my.registration.print', $h->id) }}">Print</a>@else<span class="badge {{ \App\Models\RegistrationForm::BADGES[$h->status] }}">{{ \App\Models\RegistrationForm::STATUSES[$h->status] }}</span>@endif</span></li>
                        @endforeach
                    </ul></div>
            @endif
        </div>
    </div>
</div>

@push('scripts')
<script>
(function () {
    function tally() {
        var t = 0;
        document.querySelectorAll('.course-box:checked').forEach(function (b) { t += parseInt(b.dataset.units, 10) || 0; });
        var el = document.getElementById('units'); if (el) el.textContent = t;
    }
    document.querySelectorAll('.course-box').forEach(function (b) { b.addEventListener('change', tally); });
    tally();
})();
</script>
@endpush
@endsection
