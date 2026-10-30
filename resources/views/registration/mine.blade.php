@extends('layouts.master')

@section('content')
@php
    use App\Support\CourseType;
    $editable = $form && $form->isEditable() && ! $blockers;
    // a fresh form starts with the compulsory courses and carry-overs ticked
    $fresh = ! $form || ($form->courses->isEmpty() && $form->status === 'draft');
    $chosen = old('courses', $fresh ? collect($offered)->filter(fn ($o) => ! $o['blocked'] && ($o['carryover'] || $o['status'] === CourseType::COMPULSORY))->map(fn ($o) => $o['course']->id)->values()->all() : $form->courses->pluck('course_id')->all());
    $carry = collect($offered)->where('carryover', true)->values();
    $current = collect($offered)->where('carryover', false)->values();
    $notOffered = collect($outstanding)->reject(fn ($o) => $o['offered_now'])->values();
    $outUnits = (int) collect($outstanding)->sum(fn ($o) => $o['course']->credit_units);
@endphp
<div class="main-content">
    <div class="page-content">
        <div class="container-fluid">
            <x-owing-notice />
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

            @if (collect($outstanding)->isNotEmpty())
                <div class="alert alert-danger d-flex gap-3 align-items-start uv-outstanding">
                    <i class="ri-error-warning-line fs-3 lh-1"></i>
                    <div>
                        <strong>You have {{ count($outstanding) }} outstanding {{ \Illuminate\Support\Str::plural('course', count($outstanding)) }} ({{ $outUnits }} units) to pass:</strong>
                        {{ collect($outstanding)->map(fn ($o) => $o['course']->code)->implode(', ') }}.
                        @if ($forceCarry) <div class="fs-13">Register the ones on offer this semester first; this semester's own courses unlock once they are ticked.</div>@endif
                        @if ($notOffered->isNotEmpty())
                            <div class="fs-13 text-body">Not on offer this semester: @foreach ($notOffered as $o){{ $o['course']->code }}{{ $o['semester'] ? ' (' . $o['semester'] . ')' : '' }}{{ $loop->last ? '.' : ', ' }}@endforeach</div>
                        @endif
                    </div>
                </div>
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
            <form method="POST" action="{{ route('my.registration.save') }}" id="reg-form" data-min="{{ $limits->min }}" data-max="{{ $limits->max }}" data-exempt="{{ $limits->carryoversExempt ? 1 : 0 }}" data-force="{{ $forceCarry ? 1 : 0 }}" data-editable="{{ $editable ? 1 : 0 }}">
                @csrf
                <div class="card uv-load sticky-top" style="top:70px;z-index:5">
                    <div class="card-body py-2">
                        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                            <div>
                                <span class="fs-4 fw-semibold" id="units">0</span><span class="text-muted"> units chosen</span>
                                <span class="text-muted fs-13 ms-2" id="units-detail"></span>
                            </div>
                            <div class="fs-13 text-end">Allowed <strong>{{ $limits->min }}–{{ $limits->max }}</strong> units
                                @if ($limits->probation)<span class="text-danger">(reduced: you are on probation)</span>@endif
                                @if ($limits->carryoversExempt)<div class="text-muted fs-12">Carry-overs are not counted in the maximum.</div>@endif
                            </div>
                        </div>
                        <div class="progress mt-2" style="height:8px"><div class="progress-bar" id="units-bar" role="progressbar" style="width:0%"></div></div>
                        <div class="fs-12 mt-1" id="units-msg"></div>
                    </div>
                </div>

                @if ($carry->isNotEmpty())
                <div class="card border-danger-subtle">
                    <div class="card-header bg-danger-subtle"><h6 class="card-title mb-0 text-danger"><span class="badge bg-danger me-2">1</span>Outstanding (carry-over) courses</h6>
                        <div class="fs-12 text-muted">Failed courses you still have to pass. @if ($forceCarry)They must be registered before this semester's courses.@endif</div></div>
                    @include('registration.partials.course-rows', ['rows' => $carry, 'section' => 'carry'])
                </div>
                @endif

                <div class="card" id="current-card">
                    <div class="card-header"><h6 class="card-title mb-0">@if ($carry->isNotEmpty())<span class="badge bg-primary me-2">2</span>@endif This semester's courses</h6>
                        <div class="fs-12 text-muted" id="current-lock" @if ($carry->isEmpty()) hidden @endif></div></div>
                    @include('registration.partials.course-rows', ['rows' => $current, 'section' => 'current'])
                    @if ($editable && collect($offered)->isNotEmpty())
                        <div class="px-3 pt-2 text-muted fs-12">Compulsory courses must be taken and passed.@if (collect($offered)->contains('status', CourseType::COMPULSORY_ELECTIVE)) From the compulsory electives, choose {{ $ceRule }}.@endif Electives are optional.</div>
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
    var form = document.getElementById('reg-form');
    if (!form) return;
    var min = +form.dataset.min, max = +form.dataset.max, exempt = form.dataset.exempt === '1', force = form.dataset.force === '1', editable = form.dataset.editable === '1';
    var boxes = Array.prototype.slice.call(form.querySelectorAll('.course-box'));
    function units(b) { return parseInt(b.dataset.units, 10) || 0; }
    function tally() {
        var total = 0, carry = 0, counted = 0;
        boxes.forEach(function (b) { if (!b.checked) return; total += units(b); if (b.dataset.carry === '1') carry += units(b); });
        counted = exempt ? total - carry : total;
        // Carry-overs first: this semester's courses stay locked until every required carry-over is ticked.
        var missing = boxes.filter(function (b) { return b.dataset.required === '1' && !b.checked; });
        var locked = force && missing.length > 0;
        boxes.forEach(function (b) {
            if (!editable || b.dataset.blocked === '1') return;
            var over = !b.checked && (exempt && b.dataset.carry === '1' ? false : counted + units(b) > max);
            var lock = locked && b.dataset.carry !== '1' && !b.checked;
            b.disabled = over || lock;
            var row = b.closest('tr');
            row.classList.toggle('opacity-50', b.disabled);
            var note = row.querySelector('.uv-why');
            if (note) note.textContent = lock ? 'Register your carry-over courses first.' : (over ? 'Would take you above ' + max + ' units.' : '');
        });
        var lockEl = document.getElementById('current-lock');
        if (lockEl) {
            lockEl.hidden = false;
            lockEl.innerHTML = locked ? '<span class="text-danger"><i class="ri-lock-line"></i> Locked until you tick: ' + missing.map(function (b) { return b.dataset.code; }).join(', ') + '</span>' : '<span class="text-success"><i class="ri-lock-unlock-line"></i> Carry-overs registered. Choose this semester\'s courses.</span>';
        }
        document.getElementById('units').textContent = total;
        document.getElementById('units-detail').textContent = carry ? '(' + carry + ' carry-over' + (exempt ? ', ' + counted + ' counted' : '') + ')' : '';
        var bar = document.getElementById('units-bar');
        bar.style.width = Math.min(100, max ? counted / max * 100 : 0) + '%';
        bar.className = 'progress-bar ' + (counted > max ? 'bg-danger' : (counted < min ? 'bg-warning' : 'bg-success'));
        var msg = document.getElementById('units-msg');
        msg.className = 'fs-12 mt-1 ' + (counted > max ? 'text-danger' : (counted < min ? 'text-warning' : 'text-success'));
        msg.textContent = counted > max ? 'You are ' + (counted - max) + ' units over the maximum. Untick a course.'
            : (counted < min ? (min - counted) + ' more units needed to reach the minimum of ' + min + '.' : 'Within the limit. ' + (max - counted) + ' units left before the maximum.');
    }
    boxes.forEach(function (b) { b.addEventListener('change', tally); });
    tally();
})();
</script>
@endpush
@endsection
