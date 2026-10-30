@extends('layouts.master')

@push('styles')
<style>
    .uv-steps { display: flex; flex-wrap: wrap; gap: .25rem; counter-reset: step; }
    .uv-steps button { border: 1px solid var(--tb-border-color-translucent, #dfe3ea); background: var(--tb-body-bg, #fff); border-radius: 2rem; padding: .3rem .8rem .3rem .35rem; font-size: 0.8125rem; color: inherit; display: flex; align-items: center; gap: .4rem; }
    .uv-steps button::before { counter-increment: step; content: counter(step); width: 1.5rem; height: 1.5rem; border-radius: 50%; display: inline-grid; place-items: center; background: var(--tb-light); font-weight: 600; font-size: 0.75rem; }
    .uv-steps button.active { border-color: var(--tb-primary); color: var(--tb-primary); }
    .uv-steps button.active::before { background: var(--tb-primary); color: #fff; }
    .uv-steps button.done::before { background: var(--tb-success); color: #fff; content: '✓'; }
    .uv-step { display: none; } .uv-step.active { display: block; }
    .uv-day { display: flex; gap: 2px; flex-wrap: wrap; }
    .uv-day span { font-size: 0.6875rem; padding: .2rem .4rem; border-radius: .25rem; background: var(--tb-primary-bg-subtle); color: var(--tb-primary); font-family: var(--tb-font-monospace); }
    .uv-day span.brk { background: var(--tb-warning-bg-subtle); color: var(--tb-warning-text-emphasis); }
    #courseTable td, #courseTable th { white-space: nowrap; font-size: 0.8125rem; }
    #courseTable input.form-control-sm, #courseTable select.form-select-sm { width: 4.5rem; padding: .15rem .3rem; }
    #courseTable select.uv-activity { width: 7rem; }
    #courseTable tr.excluded { opacity: .45; }
</style>
@endpush

@section('content')
@php
    $old = fn ($k, $d = null) => old($k, $s[$k] ?? $d);
    $days = \App\Models\Timetable::DAYS;
    $rules = $old('unit_rules', \App\Services\Timetable\TimetableWizard::UNIT_RULES);
    $weights = $old('weights', []);
    $rows = fn ($k, $min) => array_pad(array_values($old($k, []) ?: []), $min, []);
@endphp
<div class="main-content"><div class="page-content"><div class="container-fluid">
    @include('partials.page-title', ['title' => 'Timetable generation wizard', 'icon' => 'ri-magic-line', 'subtitle' => 'Set the requirements once and generate the lecture timetable for the whole school (or the faculties, departments and levels you choose) in one go.', 'back' => [route('timetables.index'), 'Timetables']])
    @include('academics.partials.alerts')

    <div class="card"><div class="card-body d-flex flex-wrap gap-2 align-items-center justify-content-between">
        <div class="uv-steps" id="stepNav">
            @foreach (['Scope', 'Resources', 'Day structure', 'Rules', 'Course requirements', 'Priorities', 'Algorithm', 'Check', 'Generate'] as $i => $label)
                <button type="button" data-go="{{ $i }}" class="{{ $i === 0 ? 'active' : '' }}">{{ $label }}</button>
            @endforeach
        </div>
        <div class="d-flex gap-2 align-items-center">
            <form method="GET" class="d-flex gap-2"><select name="template" class="form-select form-select-sm" style="width:auto" onchange="this.form.submit()"><option value="">Start from a template…</option>@foreach ($templates as $tpl)<option value="{{ $tpl->id }}" @selected((int) request('template') === $tpl->id)>{{ $tpl->name }}</option>@endforeach</select></form>
            <form method="GET" class="d-flex gap-2"><select name="from" class="form-select form-select-sm" style="width:auto" onchange="this.form.submit()"><option value="">…or an earlier timetable</option>@foreach ($earlier as $e)<option value="{{ $e->id }}" @selected((int) request('from') === $e->id)>{{ $e->title }}</option>@endforeach</select></form>
        </div>
    </div></div>

    <form method="POST" action="{{ route('timetables.wizard.store') }}" id="wizard" novalidate>@csrf
        @if (! empty($s['copy_from']))<input type="hidden" name="copy_from" value="{{ $s['copy_from'] }}">@endif

        {{-- 1. Scope --}}
        <div class="uv-step active card"><div class="card-header"><h6 class="card-title mb-0">1 · What to schedule</h6><div class="text-muted fs-12">Leave faculties, departments, programmes and levels empty to timetable the whole school.</div></div><div class="card-body row g-3">
            <div class="col-md-6"><label class="form-label">Name</label><input name="name" value="{{ old('name', ($current ? $current->name . ' ' : '') . 'lecture timetable') }}" class="form-control" required maxlength="150"></div>
            <div class="col-md-3"><label class="form-label">Session</label><select name="academic_session_id" class="form-select">@foreach ($sessions as $id => $n)<option value="{{ $id }}" @selected((int) old('academic_session_id', $current?->id) === $id)>{{ $n }}</option>@endforeach</select></div>
            <div class="col-md-3"><label class="form-label">Semester</label><select name="semester_type_id" class="form-select">@foreach ($semesterTypes as $id => $n)<option value="{{ $id }}" @selected((int) old('semester_type_id') === $id)>{{ $n }}</option>@endforeach</select></div>
            <div class="col-md-3"><label class="form-label">Faculties</label><select name="faculties[]" multiple size="7" class="form-select">@foreach ($faculties as $id => $n)<option value="{{ $id }}" @selected(in_array($id, $old('faculties', [])))>{{ $n }}</option>@endforeach</select><div class="form-text">A faculty brings in all its departments.</div></div>
            <div class="col-md-3"><label class="form-label">Departments</label><select name="departments[]" multiple size="7" class="form-select">@foreach ($departments->groupBy(fn ($d) => $d->faculty?->name ?? 'Other') as $fac => $list)<optgroup label="{{ $fac }}">@foreach ($list as $d)<option value="{{ $d->id }}" @selected(in_array($d->id, $old('departments', [])))>{{ $d->name }}</option>@endforeach</optgroup>@endforeach</select></div>
            <div class="col-md-3"><label class="form-label">Programmes</label><select name="programmes[]" multiple size="7" class="form-select">@foreach ($programmes as $id => $n)<option value="{{ $id }}" @selected(in_array($id, $old('programmes', [])))>{{ $n }}</option>@endforeach</select><div class="form-text">Only their curriculum and registered courses.</div></div>
            <div class="col-md-3"><label class="form-label">Levels</label><select name="levels[]" multiple size="7" class="form-select">@foreach ($levels as $l)<option value="{{ $l }}" @selected(in_array($l, $old('levels', [])))>{{ $l }} Level</option>@endforeach</select></div>
            <div class="col-md-12"><label class="form-label">Timetable committee</label><select name="committee[]" multiple size="5" class="form-select">@foreach ($staff as $id => $n)<option value="{{ $id }}" @selected(in_array($id, old('committee', [])))>{{ $n }}</option>@endforeach</select><div class="form-text">They can edit, regenerate and submit the draft. You are added automatically.</div></div>
            @if (! empty($s['copy_from']))<div class="col-12"><div class="alert alert-info mb-0 fs-13"><i class="ri-file-copy-line me-1"></i>Course meetings, lengths, venue types and lecturers will be copied from <strong>{{ $earlier->firstWhere('id', $s['copy_from'])?->title ?? 'the earlier timetable' }}</strong> where the course appears there.</div></div>@endif
        </div></div>

        {{-- 2. Resources --}}
        <div class="uv-step card"><div class="card-header d-flex justify-content-between align-items-center"><div><h6 class="card-title mb-0">2 · Courses, lecturers, classes and venues</h6><div class="text-muted fs-12">What the timetable will be built from, and anything missing that will stop a class from being placed. Fix it in the records, then check again.</div></div>
            <button type="button" class="btn btn-sm btn-soft-primary" data-load-courses><i class="ri-refresh-line me-1"></i>Check again</button></div>
            <div class="card-body" id="resources"><div class="text-muted">Loading…</div></div></div>

        {{-- 3. Day structure --}}
        <div class="uv-step card"><div class="card-header"><h6 class="card-title mb-0">3 · The teaching day</h6></div><div class="card-body row g-3">
            <div class="col-md-12"><label class="form-label d-block">Teaching days</label>@foreach ($days as $d => $n)<div class="form-check form-check-inline"><input class="form-check-input" type="checkbox" name="days[]" value="{{ $d }}" id="wd{{ $d }}" @checked(in_array($d, $old('days', [])))><label class="form-check-label" for="wd{{ $d }}">{{ $n }}</label></div>@endforeach</div>
            <div class="col-md-2"><label class="form-label">Day starts</label><input type="time" name="day_start" value="{{ $old('day_start') }}" class="form-control" data-day></div>
            <div class="col-md-2"><label class="form-label">Day ends</label><input type="time" name="day_end" value="{{ $old('day_end') }}" class="form-control" data-day></div>
            <div class="col-md-2"><label class="form-label">Period (minutes)</label><input type="number" name="period_minutes" min="20" max="240" value="{{ $old('period_minutes') }}" class="form-control" data-day></div>
            <div class="col-md-2"><label class="form-label">At most … periods</label><input type="number" name="max_periods" min="1" max="20" value="{{ $old('max_periods') }}" class="form-control" placeholder="No limit" data-day></div>
            <div class="col-md-12"><label class="form-label">Breaks</label>
                <table class="table table-sm align-middle mb-1" style="max-width:640px"><thead><tr><th>After period</th><th>Minutes</th><th>Name</th></tr></thead><tbody>
                    @foreach ($rows('breaks', 3) as $i => $b)<tr><td><input type="number" min="1" max="20" name="breaks[{{ $i }}][after]" value="{{ $b['after'] ?? '' }}" class="form-control form-control-sm" data-day></td><td><input type="number" min="5" max="240" name="breaks[{{ $i }}][minutes]" value="{{ $b['minutes'] ?? '' }}" class="form-control form-control-sm" data-day></td><td><input name="breaks[{{ $i }}][label]" value="{{ $b['label'] ?? '' }}" class="form-control form-control-sm" placeholder="Short break, Lunch" data-day></td></tr>@endforeach
                </tbody></table>
                <div class="uv-day mt-2" id="dayPreview"></div><div class="form-text">No class ever runs across a break.</div></div>
            <div class="col-lg-5"><label class="form-label">Half days</label>
                <table class="table table-sm align-middle mb-0"><thead><tr><th>Day</th><th>Classes end at</th><th>Why</th></tr></thead><tbody>
                    @foreach ($rows('half_days', 2) as $i => $h)<tr><td><select name="half_days[{{ $i }}][day]" class="form-select form-select-sm"><option value=""></option>@foreach ($days as $d => $n)<option value="{{ $d }}" @selected((int) ($h['day'] ?? 0) === $d)>{{ $n }}</option>@endforeach</select></td><td><input type="time" name="half_days[{{ $i }}][ends_at]" value="{{ $h['ends_at'] ?? '' }}" class="form-control form-control-sm"></td><td><input name="half_days[{{ $i }}][label]" value="{{ $h['label'] ?? '' }}" class="form-control form-control-sm" placeholder="Sports afternoon"></td></tr>@endforeach
                </tbody></table></div>
            <div class="col-lg-7"><label class="form-label">Events no class may take</label>
                <table class="table table-sm align-middle mb-0"><thead><tr><th>Day</th><th>From</th><th>To</th><th>Event</th></tr></thead><tbody>
                    @foreach ($rows('events', 4) as $i => $e)<tr><td><select name="events[{{ $i }}][day]" class="form-select form-select-sm"><option value=""></option>@foreach ($days as $d => $n)<option value="{{ $d }}" @selected((int) ($e['day'] ?? 0) === $d)>{{ $n }}</option>@endforeach</select></td><td><input type="time" name="events[{{ $i }}][start]" value="{{ $e['start'] ?? '' }}" class="form-control form-control-sm"></td><td><input type="time" name="events[{{ $i }}][end]" value="{{ $e['end'] ?? '' }}" class="form-control form-control-sm"></td><td><input name="events[{{ $i }}][label]" value="{{ $e['label'] ?? '' }}" class="form-control form-control-sm" placeholder="Assembly, Jumat prayers, Fellowship"></td></tr>@endforeach
                </tbody></table></div>
        </div></div>

        {{-- 4. Rules --}}
        <div class="uv-step card"><div class="card-header"><h6 class="card-title mb-0">4 · Rules</h6><div class="text-muted fs-12">Never broken: no student class, lecturer or venue in two places at once; venues big enough and with the facilities needed; lecturers' unavailable times, closed venues, breaks, half days and events respected.</div></div><div class="card-body row g-3">
            <div class="col-md-3"><label class="form-label">Most periods in a row</label><input type="number" name="max_consecutive" min="1" max="10" value="{{ $old('max_consecutive') }}" class="form-control"><div class="form-text">For a class or a lecturer, before a free period.</div></div>
            <div class="col-md-3"><label class="form-label">Most periods a class has in a day</label><input type="number" name="max_per_day" min="0" max="16" value="{{ $old('max_per_day') }}" class="form-control"><div class="form-text">0 for no limit. Kept to wherever possible.</div></div>
            <div class="col-md-6">
                <div class="form-check form-switch mb-2"><input type="hidden" name="avoid_breaks" value="0"><input class="form-check-input" type="checkbox" name="avoid_breaks" value="1" id="ab" @checked($old('avoid_breaks'))><label class="form-check-label" for="ab">Keep classes off the periods right next to a break where possible</label></div>
                <div class="form-check form-switch"><input type="hidden" name="strict_rooms" value="0"><input class="form-check-input" type="checkbox" name="strict_rooms" value="1" id="sr" @checked($old('strict_rooms'))><label class="form-check-label" for="sr">Lectures use only their own department's rooms (and shared rooms)</label></div>
            </div>
        </div></div>

        {{-- 5. Course requirements --}}
        <div class="uv-step card"><div class="card-header"><h6 class="card-title mb-0">5 · How often and how long each course meets</h6></div><div class="card-body">
            <div class="row g-3">
                <div class="col-lg-4"><h6 class="fs-13">By credit units</h6>
                    <table class="table table-sm align-middle"><thead><tr><th>Units</th><th>Meetings a week</th><th>Periods each</th></tr></thead><tbody>
                        @foreach ($rules as $u => $r)<tr><td class="fw-semibold">{{ $u }}</td><td><input type="number" min="1" max="10" name="unit_rules[{{ $u }}][0]" value="{{ $r[0] ?? 1 }}" class="form-control form-control-sm"></td><td><input type="number" min="1" max="4" name="unit_rules[{{ $u }}][1]" value="{{ $r[1] ?? 1 }}" class="form-control form-control-sm"></td></tr>@endforeach
                    </tbody></table><div class="form-text">A 3-unit course meeting 3 times for 1 period, a 2-unit course once as a double period, and so on.</div></div>
                <div class="col-lg-8"><h6 class="fs-13 d-flex justify-content-between">Course by course <span class="text-muted fw-normal">Only the courses you change are sent.</span></h6>
                    <div class="d-flex gap-2 mb-2"><input class="form-control form-control-sm" placeholder="Find a course or department" id="courseFilter"><label class="form-check fs-13 text-nowrap mb-0 mt-1"><input type="checkbox" class="form-check-input" id="warnOnly"> Only with problems</label></div>
                    <div class="table-responsive" style="max-height:520px"><table class="table table-sm align-middle mb-0" id="courseTable"><thead class="table-light sticky-top"><tr><th></th><th>Course</th><th>Students</th><th>Meetings</th><th>Periods</th><th>Kind</th><th>Venue type</th><th>Priority</th></tr></thead><tbody><tr><td colspan="8" class="text-muted">Open step 2 to load the courses.</td></tr></tbody></table></div>
                </div>
            </div>
        </div></div>

        {{-- 6. Priorities --}}
        <div class="uv-step card"><div class="card-header"><h6 class="card-title mb-0">6 · Priorities and preferences</h6><div class="text-muted fs-12">Weighed after the rules: 0 ignores a preference, 5 makes it very important.</div></div><div class="card-body row g-3">
            <div class="col-md-6">
                <div class="form-check form-switch mb-2"><input type="hidden" name="apply_priorities" value="0"><input class="form-check-input" type="checkbox" name="apply_priorities" value="1" id="ap" @checked($old('apply_priorities'))><label class="form-check-label" for="ap">High-priority courses are placed first and get the better periods</label></div>
                <div class="form-check form-switch"><input type="hidden" name="compulsory_priority" value="0"><input class="form-check-input" type="checkbox" name="compulsory_priority" value="1" id="cp" @checked($old('compulsory_priority'))><label class="form-check-label" for="cp">Compulsory courses start at high priority (4 of 5)</label></div>
            </div>
            <div class="col-md-6"><label class="form-label">Variation</label><input type="number" name="seed" min="1" max="99999" value="{{ $old('seed', 1) }}" class="form-control" style="max-width:10rem"><div class="form-text">Change it for a different, equally valid arrangement.</div></div>
            @foreach ($preferences as $k => [$label, , $default])
                @php $w = (int) ($weights[$k] ?? $default); @endphp
                <div class="col-md-6"><label class="form-label fs-13 d-flex justify-content-between"><span>{{ $label }}</span><output class="fw-semibold" id="w{{ $k }}">{{ $w }}</output></label><input type="range" class="form-range" min="0" max="5" name="weights[{{ $k }}]" value="{{ $w }}" oninput="document.getElementById('w{{ $k }}').value=this.value"></div>
            @endforeach
        </div></div>

        {{-- 7. Algorithm --}}
        <div class="uv-step card"><div class="card-header"><h6 class="card-title mb-0">7 · How to generate</h6></div><div class="card-body row g-3">
            @foreach ($algorithms as $k => [$label, $about])
                <div class="col-md-4"><label class="card h-100 mb-0 border {{ $old('algorithm') === $k ? 'border-primary' : '' }}" style="cursor:pointer"><div class="card-body"><div class="form-check"><input class="form-check-input" type="radio" name="algorithm" value="{{ $k }}" @checked($old('algorithm', 'quick') === $k) onchange="document.querySelectorAll('[name=algorithm]').forEach(r=>r.closest('.card').classList.toggle('border-primary', r.checked))"><span class="form-check-label fw-semibold">{{ $label }}</span></div><p class="text-muted fs-13 mb-0 mt-2">{{ $about }}</p></div></label></div>
            @endforeach
            <div class="col-md-3"><label class="form-label">Candidates to generate</label><input type="number" name="candidates" min="1" max="5" value="{{ $old('candidates', 3) }}" class="form-control"><div class="form-text">Each is a complete timetable with its own score and statistics, to compare before choosing one.</div></div>
            <div class="col-md-9 d-flex align-items-center"><div class="form-check form-switch"><input type="hidden" name="vary_algorithms" value="0"><input class="form-check-input" type="checkbox" name="vary_algorithms" value="1" id="va" @checked($old('vary_algorithms', true))><label class="form-check-label" for="va">Use a different method for each candidate (quick, thorough, hybrid…), rather than only the one chosen</label></div></div>
        </div></div>

        {{-- 8. Check --}}
        <div class="uv-step card"><div class="card-header d-flex justify-content-between align-items-center"><div><h6 class="card-title mb-0">8 · Check before generating</h6><div class="text-muted fs-12">A full generation with the chosen method that saves nothing: see how much fits, department by department, and why anything does not.</div></div>
            <button type="button" class="btn btn-sm btn-primary" id="runPreview"><i class="ri-play-line me-1"></i>Run the check</button></div>
            <div class="card-body" id="preview"><div class="text-muted">Run the check to see what would be placed.</div></div></div>

        {{-- 9. Generate --}}
        <div class="uv-step card"><div class="card-header"><h6 class="card-title mb-0">9 · Generate</h6></div><div class="card-body">
            <p>This creates a <strong>draft</strong> lecture timetable and generates the candidates in the background. You then compare them, use the best one, adjust it by hand if needed, and send it for approval. Nothing is published until it is approved.</p>
            <div class="d-flex flex-wrap gap-2 align-items-end">
                <button class="btn btn-success" id="generateBtn"><i class="ri-magic-line me-1"></i>Create the draft and generate</button>
                <div class="ms-lg-auto d-flex gap-2 align-items-end">
                    <div><label class="form-label fs-12 mb-1">Save these answers as a template</label><input name="template_name" class="form-control form-control-sm" placeholder="Template name, e.g. Standard week" value="{{ request('template') ? $templates->firstWhere('id', (int) request('template'))?->name : '' }}"></div>
                    <button type="button" class="btn btn-sm btn-soft-secondary" id="saveTemplate"><i class="ri-save-line me-1"></i>Save template</button>
                </div>
            </div>
            <div id="templateMsg" class="fs-13 mt-2"></div>
            @if ($templates->isNotEmpty())
                <h6 class="fs-13 mt-4">Saved templates</h6>
                <ul class="list-group">@foreach ($templates as $tpl)<li class="list-group-item d-flex justify-content-between align-items-center"><span><a href="{{ route('timetables.wizard', ['template' => $tpl->id]) }}">{{ $tpl->name }}</a> <span class="text-muted fs-12">{{ $tpl->updated_at->format('j M Y') }}</span></span>
                    <button form="delTpl{{ $tpl->id }}" class="btn btn-sm btn-link text-danger">Delete</button></li>@endforeach</ul>
            @endif
        </div></div>

        <div class="d-flex justify-content-between mb-4">
            <button type="button" class="btn btn-light" id="prevBtn" disabled><i class="ri-arrow-left-line me-1"></i>Back</button>
            <button type="button" class="btn btn-primary" id="nextBtn">Next<i class="ri-arrow-right-line ms-1"></i></button>
        </div>
    </form>
    @foreach ($templates as $tpl)<form id="delTpl{{ $tpl->id }}" method="POST" action="{{ route('timetables.wizard.templates.destroy', $tpl) }}" onsubmit="return confirm('Delete this template?')">@csrf @method('DELETE')</form>@endforeach
</div></div></div>
@endsection

@push('scripts')
<script>
(() => {
    const form = document.getElementById('wizard'), steps = [...form.querySelectorAll('.uv-step')], nav = [...document.querySelectorAll('#stepNav button')];
    const csrf = form.querySelector('[name=_token]').value, DAYS = @json($days), ACTIVITIES = @json($activities), VENUES = @json($venueTypes);
    const changed = @json((object) old('courses', []));
    let at = 0, loaded = false;
    const esc = s => String(s ?? '').replace(/[&<>"]/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;'}[c]));

    function go(i) {
        at = Math.max(0, Math.min(steps.length - 1, i));
        steps.forEach((s, k) => s.classList.toggle('active', k === at));
        nav.forEach((b, k) => { b.classList.toggle('active', k === at); b.classList.toggle('done', k < at); });
        document.getElementById('prevBtn').disabled = at === 0;
        document.getElementById('nextBtn').classList.toggle('d-none', at === steps.length - 1);
        if ((at === 1 || at === 4) && !loaded) loadCourses();
        window.scrollTo({top: 0, behavior: 'smooth'});
    }
    nav.forEach(b => b.addEventListener('click', () => go(+b.dataset.go)));
    document.getElementById('prevBtn').onclick = () => go(at - 1);
    document.getElementById('nextBtn').onclick = () => go(at + 1);

    // Only the courses the committee changed are submitted (a whole school is thousands of fields).
    function body(extra = {}) {
        const fd = new FormData(form);
        for (const [k, v] of Object.entries(extra)) fd.set(k, v);
        return fd;
    }
    async function post(url, fd) {
        const r = await fetch(url, {method: 'POST', body: fd, headers: {'Accept': 'application/json', 'X-CSRF-TOKEN': csrf}});
        const j = await r.json().catch(() => ({}));
        if (!r.ok) throw new Error(j.message || (j.errors ? Object.values(j.errors).flat().join(' ') : 'Something went wrong (' + r.status + ').'));
        return j;
    }

    // Day preview, the same arithmetic as the server.
    function dayPreview() {
        const v = n => form.querySelector(`[name="${n}"]`)?.value;
        const toM = t => { const [h, m] = (t || '0:0').split(':').map(Number); return h * 60 + m; }, toT = m => String(Math.floor(m / 60)).padStart(2, '0') + ':' + String(m % 60).padStart(2, '0');
        let at = toM(v('day_start')), stop = toM(v('day_end')), len = Math.max(10, +v('period_minutes') || 60), max = +v('max_periods') || 99, n = 0, out = [];
        const brk = {};
        for (let i = 0; i < 3; i++) { const a = +v(`breaks[${i}][after]`), m = +v(`breaks[${i}][minutes]`); if (a && m) brk[a] = [m, v(`breaks[${i}][label]`) || 'Break']; }
        while (at + len <= stop && n < max && n < 24) {
            out.push(`<span>${++n}: ${toT(at)}–${toT(at + len)}</span>`); at += len;
            if (brk[n]) { out.push(`<span class="brk">${esc(brk[n][1])} ${toT(at)}–${toT(Math.min(stop, at + brk[n][0]))}</span>`); at += brk[n][0]; }
        }
        document.getElementById('dayPreview').innerHTML = out.join('') || '<span class="brk">No period fits in the day.</span>';
    }
    form.querySelectorAll('[data-day]').forEach(i => i.addEventListener('input', dayPreview));
    dayPreview();

    async function loadCourses() {
        const box = document.getElementById('resources');
        box.innerHTML = '<div class="text-muted"><span class="spinner-border spinner-border-sm me-2"></span>Gathering courses, lecturers, classes and venues…</div>';
        try {
            const j = await post(@json(route('timetables.wizard.courses')), body());
            loaded = true;
            const t = j.totals, fill = t.venue_slots ? Math.round(t.periods_needed / t.venue_slots * 100) : 0;
            const tile = (n, l, c = '') => `<div class="col-6 col-md-3 col-xl"><div class="border rounded p-2 h-100"><div class="fs-4 fw-semibold ${c}">${n}</div><div class="text-muted fs-12">${l}</div></div></div>`;
            box.innerHTML = `<div class="row g-2 mb-3">${tile(t.courses, 'Courses')}${tile(t.classes, 'Student classes')}${tile(t.lecturers, 'Lecturers')}${tile(t.venues, 'Venues')}${tile(t.slots, 'Periods a week')}${tile(t.periods_needed, 'Periods needed')}${tile(fill + '%', 'Of venue time needed', fill > 85 ? 'text-danger' : '')}${tile(t.with_warnings, 'Courses with problems', t.with_warnings ? 'text-warning' : 'text-success')}</div>`
                + (t.courses === 0 ? '<div class="alert alert-warning mb-0">No courses fall in this scope for the chosen semester. Check the scope, or that the courses are on the curriculum or registered.</div>' : '')
                + (t.no_lecturer ? `<div class="alert alert-warning fs-13">${t.no_lecturer} course(s) have no lecturer for this semester. They will still be placed, but no lecturer clash can be checked for them. Assign lecturers under course allocation first if you can.</div>` : '')
                + (fill > 85 ? '<div class="alert alert-danger fs-13">The courses need most of the venue time available. Expect some classes not to fit: add teaching days or periods, or venues.</div>' : '')
                + problems(j.courses);
            courseTable(j.courses);
        } catch (e) { box.innerHTML = `<div class="alert alert-danger mb-0">${esc(e.message)}</div>`; }
    }
    document.querySelector('[data-load-courses]').onclick = loadCourses;

    function problems(courses) {
        const bad = courses.filter(c => c.warnings.length);
        if (!bad.length) return '<div class="text-success"><i class="ri-checkbox-circle-line me-1"></i>Every course has what it needs.</div>';
        return `<h6 class="fs-13">Courses with problems</h6><div class="table-responsive" style="max-height:360px"><table class="table table-sm mb-0"><thead class="table-light"><tr><th>Course</th><th>Department</th><th>Students</th><th>Problem</th></tr></thead><tbody>`
            + bad.map(c => `<tr><td><strong>${esc(c.code)}</strong> ${esc(c.title)}</td><td>${esc(c.department)}</td><td>${c.students}</td><td class="text-warning">${c.warnings.map(esc).join('<br>')}</td></tr>`).join('') + '</tbody></table></div>';
    }

    function courseTable(courses) {
        const tb = document.querySelector('#courseTable tbody');
        const sel = (name, opts, v, cls = '') => `<select data-k="${name}" class="form-select form-select-sm ${cls}">${Object.entries(opts).map(([k, l]) => `<option value="${esc(k)}" ${String(v ?? '') === k ? 'selected' : ''}>${esc(l)}</option>`).join('')}</select>`;
        let dept = null;
        tb.innerHTML = courses.map(c => {
            const x = Object.assign({}, c, changed[c.course_id] || {});
            const include = x.include === true || x.include === '1' || x.include === 1 || (x.include !== false && x.include !== '0' && x.include !== 0 && c.include);
            const head = c.department !== dept ? `<tr class="table-light" data-dept><td colspan="8" class="fw-semibold">${esc(dept = c.department)} <span class="text-muted fw-normal">· ${esc(c.faculty)}</span></td></tr>` : '';
            return head + `<tr data-id="${c.course_id}" data-search="${esc((c.code + ' ' + c.title + ' ' + c.department).toLowerCase())}" data-warn="${c.warnings.length ? 1 : 0}" class="${include ? '' : 'excluded'}">
                <td><input type="checkbox" data-k="include" class="form-check-input" ${include ? 'checked' : ''}></td>
                <td><strong>${esc(c.code)}</strong> <span class="text-muted">${c.units}u · ${c.level ?? ''}</span>${c.warnings.length ? ` <i class="ri-error-warning-line text-warning" title="${esc(c.warnings.join('; '))}"></i>` : ''}<div class="fs-12 text-muted text-truncate" style="max-width:16rem">${esc(c.title)}${c.lecturers.length ? ' · ' + esc(c.lecturers.join(', ')) : ''}</div></td>
                <td>${c.students}</td>
                <td><input type="number" data-k="meetings" min="1" max="10" value="${x.meetings}" class="form-control form-control-sm"></td>
                <td><input type="number" data-k="duration" min="1" max="4" value="${x.duration}" class="form-control form-control-sm"></td>
                <td>${sel('activity', ACTIVITIES, x.activity, 'uv-activity')}</td>
                <td>${sel('venue_type', Object.assign({'': 'Any'}, VENUES), x.venue_type, 'uv-activity')}</td>
                <td>${sel('priority', {1: '1 low', 2: '2', 3: '3', 4: '4', 5: '5 high'}, x.priority)}</td></tr>`;
        }).join('') || '<tr><td colspan="8" class="text-muted">No courses.</td></tr>';
        Object.keys(changed).forEach(id => { const tr = tb.querySelector(`tr[data-id="${id}"]`); tr && name(tr); });
    }
    // A changed row gets real field names so it is submitted; the rest stay out of the request.
    function name(tr) {
        const id = tr.dataset.id;
        tr.querySelectorAll('[data-k]').forEach(i => {
            i.name = `courses[${id}][${i.dataset.k}]`;
            if (i.type === 'checkbox') { i.value = '1'; let h = tr.querySelector(`input[type=hidden][data-h]`); if (!h) { h = document.createElement('input'); h.type = 'hidden'; h.dataset.h = 1; h.name = i.name; h.value = '0'; i.before(h); } }
        });
        tr.classList.toggle('excluded', !tr.querySelector('[data-k=include]').checked);
    }
    document.querySelector('#courseTable').addEventListener('change', e => { const tr = e.target.closest('tr[data-id]'); tr && name(tr); });
    function filter() {
        const q = document.getElementById('courseFilter').value.toLowerCase(), w = document.getElementById('warnOnly').checked;
        document.querySelectorAll('#courseTable tr[data-id]').forEach(tr => tr.classList.toggle('d-none', (q && !tr.dataset.search.includes(q)) || (w && tr.dataset.warn !== '1')));
    }
    document.getElementById('courseFilter').addEventListener('input', filter);
    document.getElementById('warnOnly').addEventListener('change', filter);

    document.getElementById('runPreview').onclick = async function () {
        const box = document.getElementById('preview');
        this.disabled = true;
        box.innerHTML = '<div class="text-muted"><span class="spinner-border spinner-border-sm me-2"></span>Generating a trial timetable. For a whole school this can take a minute or two…</div>';
        try {
            const r = await post(@json(route('timetables.wizard.preview')), body());
            const pct = r.units ? Math.round(r.placed / r.units * 100) : 0;
            box.innerHTML = `<div class="row g-2 mb-3">
                <div class="col-md-3"><div class="border rounded p-2"><div class="fs-3 fw-semibold ${pct === 100 ? 'text-success' : 'text-warning'}">${pct}%</div><div class="text-muted fs-12">${r.placed} of ${r.units} class meetings placed</div></div></div>
                <div class="col-md-3"><div class="border rounded p-2"><div class="fs-3 fw-semibold ${r.violations ? 'text-danger' : 'text-success'}">${r.violations}</div><div class="text-muted fs-12">Hard rules broken</div></div></div>
                <div class="col-md-3"><div class="border rounded p-2"><div class="fs-3 fw-semibold">${r.courses}</div><div class="text-muted fs-12">Courses</div></div></div>
                <div class="col-md-3"><div class="border rounded p-2"><div class="fs-3 fw-semibold">${r.score}</div><div class="text-muted fs-12">Preference score (lower is better)</div></div></div></div>
                <div class="row g-3"><div class="col-lg-5"><h6 class="fs-13">By department</h6><table class="table table-sm"><thead><tr><th>Department</th><th>Courses</th><th>Placed</th></tr></thead><tbody>
                ${r.departments.map(d => `<tr><td>${esc(d.department)}</td><td>${d.courses}</td><td class="${d.placed < d.units ? 'text-warning' : 'text-success'}">${d.placed} / ${d.units}</td></tr>`).join('')}</tbody></table>
                <h6 class="fs-13">Periods used each day</h6><div class="d-flex gap-2 flex-wrap">${Object.entries(r.per_day).map(([d, n]) => `<span class="badge bg-light text-body">${esc((DAYS[d] || d).slice(0, 3))} ${n}</span>`).join('')}</div></div>
                <div class="col-lg-7">${r.unplaced ? `<h6 class="fs-13 text-warning">Not placed (${r.unplaced})</h6><div style="max-height:380px;overflow:auto"><table class="table table-sm"><tbody>${r.unplaced_list.map(u => `<tr><td><strong>${esc(u.code)}</strong> <span class="text-muted fs-12">meeting ${u.meeting} · ${u.students} students · ${esc(u.department || '')}</span><div class="fs-12">${u.reasons.map(esc).join('<br>')}</div>${u.suggestions.length ? `<div class="fs-12 text-success">${u.suggestions.map(esc).join('<br>')}</div>` : ''}</td></tr>`).join('')}</tbody></table></div>` : '<div class="alert alert-success">Everything fits. Generate to create the draft and the candidates.</div>'}
                ${r.problems.length ? `<h6 class="fs-13 text-danger">Rule breaks</h6><ul class="fs-13">${r.problems.map(p => `<li>${esc(p)}</li>`).join('')}</ul>` : ''}</div></div>`;
        } catch (e) { box.innerHTML = `<div class="alert alert-danger mb-0">${esc(e.message)}</div>`; }
        this.disabled = false;
    };

    document.getElementById('saveTemplate').onclick = async () => {
        const msg = document.getElementById('templateMsg');
        try { const j = await post(@json(route('timetables.wizard.templates.store')), body()); msg.innerHTML = `<span class="text-success">${esc(j.message)}</span>`; }
        catch (e) { msg.innerHTML = `<span class="text-danger">${esc(e.message)}</span>`; }
    };
    form.addEventListener('submit', () => { const b = document.getElementById('generateBtn'); b.disabled = true; b.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Creating…'; });
    @if ($errors->any()) go(0); @endif
})();
</script>
@endpush
