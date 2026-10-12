@extends('layouts.master')

@section('content')
<div class="main-content"><div class="page-content"><div class="container-fluid" style="max-width:1100px">
    @include('partials.page-title', ['title' => $pagetitle, 'icon' => 'ri-presentation-line', 'back' => [$exercise->exists ? route('tp.exercises.show', $exercise) : route('tp.exercises.index'), 'Teaching practice']])
    @include('academics.partials.alerts')
    @if ($errors->any())<div class="alert alert-danger">{{ $errors->first() }}</div>@endif
    <form method="POST" action="{{ $exercise->exists ? route('tp.exercises.update', $exercise) : route('tp.exercises.store') }}" class="card">@csrf @if ($exercise->exists) @method('PUT') @endif
        <div class="card-body row g-3">
            <div class="col-md-6"><label class="form-label">Name</label><input name="name" value="{{ old('name', $exercise->name) }}" class="form-control" placeholder="e.g. Teaching Practice 2026/2027 (400 level)" required></div>
            <div class="col-md-3"><label class="form-label">Session</label><select name="academic_session_id" class="form-select">@foreach ($sessions as $s)<option value="{{ $s->id }}" @selected(old('academic_session_id', $exercise->academic_session_id) == $s->id)>{{ $s->name }}</option>@endforeach</select></div>
            <div class="col-md-3"><label class="form-label">Assessed visits per student</label><input name="visits_required" type="number" min="1" max="10" value="{{ old('visits_required', $exercise->visits_required) }}" class="form-control"></div>
            <div class="col-md-3"><label class="form-label">Starts</label><input name="starts_on" type="date" value="{{ old('starts_on', $exercise->starts_on?->toDateString()) }}" class="form-control"></div>
            <div class="col-md-3"><label class="form-label">Ends</label><input name="ends_on" type="date" value="{{ old('ends_on', $exercise->ends_on?->toDateString()) }}" class="form-control"></div>
            <div class="col-md-3"><label class="form-label">Graded under course</label><select name="course_id" class="form-select"><option value="">Not graded on the portal</option>@foreach ($courses as $c)<option value="{{ $c->id }}" @selected(old('course_id', $exercise->course_id) == $c->id)>{{ $c->code }} {{ $c->title }}</option>@endforeach</select></div>
            <div class="col-md-3"><label class="form-label">Semester</label><select name="semester_type_id" class="form-select"><option value="">—</option>@foreach ($semesters as $s)<option value="{{ $s->id }}" @selected(old('semester_type_id', $exercise->semester_type_id) == $s->id)>{{ $s->name }}</option>@endforeach</select></div>
            <div class="col-12"><div class="fw-semibold mb-1">Students on this exercise</div>@include('partials.audience', ['audience' => $exercise->audienceData(), 'who' => ['students']])</div>
            <div class="col-md-6"><div class="form-check mt-2"><input type="hidden" name="choices_open" value="0"><input type="checkbox" name="choices_open" value="1" id="co" class="form-check-input" @checked(old('choices_open', $exercise->choices_open))><label for="co" class="form-check-label">Students may choose schools on the portal</label></div></div>
            <div class="col-md-3"><label class="form-label">Choices allowed</label><input name="max_choices" type="number" min="1" max="10" value="{{ old('max_choices', $exercise->max_choices) }}" class="form-control"></div>
            <div class="col-12">
                <div class="fw-semibold mb-1">Marking guide for each observed lesson</div>
                <div class="row g-2">
                    @foreach (array_pad(old('criteria', $exercise->criteria ?? []), max(8, count($exercise->criteria ?? []) + 2), ['label' => '', 'max' => '']) as $i => $c)
                        <div class="col-md-6 d-flex gap-2"><input name="criteria[{{ $i }}][label]" value="{{ $c['label'] }}" class="form-control form-control-sm" placeholder="Criterion"><input name="criteria[{{ $i }}][max]" value="{{ $c['max'] }}" type="number" min="1" max="100" class="form-control form-control-sm" style="width:90px" placeholder="Marks"></div>
                    @endforeach
                </div>
                <div class="form-text">The final score is the average of the visits, as a percentage of the total marks. Leave a row empty to drop it.</div>
            </div>
            <div class="col-12"><label class="form-label">Extra paragraph for the posting letter</label><textarea name="letter_text" rows="3" class="form-control" placeholder="For example reporting date, dress code, or what to bring">{{ old('letter_text', $exercise->letter_text) }}</textarea></div>
        </div>
        <div class="card-footer text-end"><button class="btn btn-primary">Save</button></div>
    </form>
</div></div></div>
@endsection
