@extends('layouts.master')

@section('content')
@php
    $blockedText = collect($c['blocked'] ?? [])->map(fn ($b) => (! empty($b['date']) ? $b['date'] : ['', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'][$b['day'] ?? 0]) . (! empty($b['start']) ? " {$b['start']}-{$b['end']}" : '') . ' ' . ($b['label'] ?? ''))->implode("\n");
    $ranges = fn ($list) => collect($list ?? [])->map(fn ($p) => $p[0] . '-' . $p[1])->implode(', ');
@endphp
<div class="main-content"><div class="page-content"><div class="container-fluid">
    @include('partials.page-title', ['title' => 'Rules and priorities', 'icon' => 'ri-settings-3-line', 'subtitle' => $t->title, 'back' => [route('timetables.show', $t), 'Back to the timetable']])
    @include('academics.partials.alerts')
    <form method="POST" action="{{ route('timetables.settings.update', $t) }}">@csrf @method('PUT')
        <div class="card"><div class="card-header"><h6 class="card-title mb-0">{{ $t->isExam() ? 'Examination period' : 'Teaching week' }}</h6></div><div class="card-body row g-3">
            <div class="col-md-6"><label class="form-label">Name</label><input name="name" value="{{ old('name', $t->name) }}" class="form-control" required></div>
            <div class="col-md-6"><label class="form-label d-block">{{ $t->isExam() ? 'Examination days of the week' : 'Teaching days' }}</label>@foreach (\App\Models\Timetable::DAYS as $d => $n)<div class="form-check form-check-inline"><input class="form-check-input" type="checkbox" name="days[]" value="{{ $d }}" id="d{{ $d }}" @checked(in_array($d, $c['days'] ?? []))><label class="form-check-label" for="d{{ $d }}">{{ substr($n, 0, 3) }}</label></div>@endforeach</div>
            @if ($t->isExam())
                <div class="col-md-3"><label class="form-label">First day</label><input type="date" name="start_date" value="{{ $c['start_date'] ?? '' }}" class="form-control"></div>
                <div class="col-md-3"><label class="form-label">Last day</label><input type="date" name="end_date" value="{{ $c['end_date'] ?? '' }}" class="form-control"></div>
                <div class="col-md-6"><label class="form-label">Sittings each day</label><input name="sittings" value="{{ $ranges($c['sittings'] ?? []) }}" class="form-control font-monospace"><div class="form-text">For example 09:00-12:00, 13:00-16:00. A paper must fit inside its sitting.</div></div>
                <div class="col-md-12"><label class="form-label">Dates with no examinations</label><input name="excluded_dates" value="{{ implode(', ', $c['excluded_dates'] ?? []) }}" class="form-control font-monospace" placeholder="2026-11-18, 2026-11-19"><div class="form-text">Public holidays from the holiday calendar are skipped automatically.</div></div>
            @else
                <div class="col-md-9"><label class="form-label">Periods each day</label><input name="periods" value="{{ $ranges($c['periods'] ?? []) }}" class="form-control font-monospace"><div class="form-text">For example 08:00-09:00, 09:00-10:00 … Leave out a break (for example 13:00-14:00) and no class runs across it.</div></div>
                <div class="col-md-3"><label class="form-label">Most back-to-back periods</label><input type="number" name="max_consecutive" min="1" max="10" value="{{ $c['max_consecutive'] ?? 3 }}" class="form-control"></div>
            @endif
            <div class="col-12"><label class="form-label">Protected periods (never used)</label><textarea name="blocked" rows="3" class="form-control font-monospace" placeholder="Fri 13:00-14:00 Jumat prayers&#10;Wed 16:00-18:00 Sports&#10;2026-11-20 Convocation">{{ old('blocked', $blockedText) }}</textarea><div class="form-text">One per line: a weekday (Mon … Sun) or a date (YYYY-MM-DD), optional times, then a label. Without times the whole day is protected.</div></div>
        </div></div>

        @if ($t->isExam())
            <div class="card"><div class="card-header"><h6 class="card-title mb-0">Halls and invigilators</h6></div><div class="card-body row g-3">
                <div class="col-md-3"><label class="form-label">Most halls for one paper</label><input type="number" name="max_split" min="1" max="10" value="{{ $c['max_split'] ?? 4 }}" class="form-control"></div>
                <div class="col-md-3"><label class="form-label">Candidates per invigilator</label><input type="number" name="invigilator_ratio" min="5" value="{{ $c['invigilator_ratio'] ?? 50 }}" class="form-control"></div>
                <div class="col-md-3"><label class="form-label">At least … per hall</label><input type="number" name="min_invigilators" min="1" max="10" value="{{ $c['min_invigilators'] ?? 2 }}" class="form-control"></div>
                <div class="col-md-3"><label class="form-label">Duties a day, at most</label><input type="number" name="max_duties_per_day" min="1" max="4" value="{{ $c['max_duties_per_day'] ?? 2 }}" class="form-control"></div>
                <div class="col-md-4"><label class="form-label">Everyone with this role invigilates</label><select name="invigilator_role" class="form-select"><option value="">No role</option>@foreach ($roles as $r)<option @selected(($c['invigilators']['role'] ?? '') === $r)>{{ $r }}</option>@endforeach</select></div>
                <div class="col-md-8"><label class="form-label">And these people</label><select name="invigilators[]" multiple size="6" class="form-select">@foreach ($staff as $id => $n)<option value="{{ $id }}" @selected(in_array($id, $c['invigilators']['users'] ?? []))>{{ $n }}</option>@endforeach</select><div class="form-text">Staff on approved leave or marked unavailable are never given a duty then.</div></div>
            </div></div>
        @endif

        <div class="card"><div class="card-header"><h6 class="card-title mb-0">Priorities</h6><div class="text-muted fs-12">Hard rules are never broken (no student or lecturer in two places, no double-booked or overfull venue, required facilities, durations, protected periods, closed venues). These preferences are weighed after that: 0 ignores one, 5 makes it very important.</div></div><div class="card-body row g-3">
            @foreach (\App\Models\Timetable::PREFERENCES as $k => [$label, $applies, $default])
                @if ($applies === 'both' || $applies === $t->kind)
                    <div class="col-md-6"><label class="form-label fs-13 d-flex justify-content-between"><span>{{ $label }}</span><output class="fw-semibold" id="o{{ $k }}">{{ $t->weight($k) }}</output></label><input type="range" class="form-range" min="0" max="5" name="weights[{{ $k }}]" value="{{ $t->weight($k) }}" oninput="document.getElementById('o{{ $k }}').value=this.value"></div>
                @endif
            @endforeach
            <div class="col-md-3"><label class="form-label fs-13">Variation</label><input type="number" name="seed" min="1" value="{{ $c['seed'] ?? 1 }}" class="form-control form-control-sm"><div class="form-text">Change it to get a different equally valid arrangement.</div></div>
        </div></div>

        <div class="card"><div class="card-header"><h6 class="card-title mb-0">Scope and committee</h6></div><div class="card-body row g-3">
            <div class="col-md-4"><label class="form-label">Departments (none = all)</label><select name="departments[]" data-lookup="departments" data-lookup-active="1" multiple size="6" class="form-select">@foreach ($departments as $id => $n)<option value="{{ $id }}" @selected(in_array($id, $c['scope']['departments'] ?? []))>{{ $n }}</option>@endforeach</select></div>
            <div class="col-md-3"><label class="form-label">Levels (none = all)</label><select name="levels[]" multiple size="6" class="form-select">@foreach ($levels as $l)<option value="{{ $l }}" @selected(in_array($l, $c['scope']['levels'] ?? []))>{{ $l }}</option>@endforeach</select><div class="form-text">Scope changes apply when you add new courses on "What to schedule".</div></div>
            <div class="col-md-5"><label class="form-label">Committee</label><select name="committee[]" multiple size="6" class="form-select">@foreach ($staff as $id => $n)<option value="{{ $id }}" @selected(in_array($id, $t->committee ?? []))>{{ $n }}</option>@endforeach</select></div>
        </div></div>
        <button class="btn btn-primary mb-4"><i class="ri-save-line me-1"></i>Save rules</button>
    </form>
</div></div></div>
@endsection
