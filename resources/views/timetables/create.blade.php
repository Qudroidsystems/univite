@extends('layouts.master')

@section('content')
<div class="main-content"><div class="page-content"><div class="container-fluid">
    @include('partials.page-title', ['title' => 'New timetable', 'icon' => 'ri-calendar-2-line', 'back' => [route('timetables.index'), 'Timetables']])
    @include('academics.partials.alerts')
    <form method="POST" action="{{ route('timetables.store') }}" class="card"><div class="card-body row g-3">@csrf
        <div class="col-md-6"><label class="form-label">Kind</label>
            <div>@foreach (\App\Models\Timetable::KINDS as $k => $l)<div class="form-check form-check-inline"><input class="form-check-input" type="radio" name="kind" value="{{ $k }}" id="k{{ $k }}" @checked(old('kind', 'lecture') === $k) onchange="document.getElementById('exam-dates').classList.toggle('d-none', this.value !== 'exam')"><label class="form-check-label" for="k{{ $k }}">{{ $l }}</label></div>@endforeach</div></div>
        <div class="col-md-6"><label class="form-label">Name</label><input name="name" value="{{ old('name') }}" class="form-control" required placeholder="First semester lectures, Faculty of Science"></div>
        <div class="col-md-6"><label class="form-label">Session</label><select name="academic_session_id" data-lookup="sessions" class="form-select">@foreach ($sessions as $id => $n)<option value="{{ $id }}" @selected((int) old('academic_session_id', $current?->id) === $id)>{{ $n }}</option>@endforeach</select></div>
        <div class="col-md-6"><label class="form-label">Semester</label><select name="semester_type_id" data-lookup="semester-types" data-lookup-active="1" class="form-select">@foreach ($semesterTypes as $id => $n)<option value="{{ $id }}" @selected((int) old('semester_type_id') === $id)>{{ $n }}</option>@endforeach</select></div>
        <div id="exam-dates" class="col-12 row g-3 m-0 p-0 {{ old('kind') === 'exam' ? '' : 'd-none' }}">
            <div class="col-md-6"><label class="form-label">Examinations start</label><input type="date" name="start_date" value="{{ old('start_date') }}" class="form-control"></div>
            <div class="col-md-6"><label class="form-label">Examinations end</label><input type="date" name="end_date" value="{{ old('end_date') }}" class="form-control"></div>
        </div>
        <div class="col-md-6"><label class="form-label">Departments <span class="text-muted">(none = all)</span></label><select name="departments[]" data-lookup="departments" data-lookup-active="1" multiple size="6" class="form-select">@foreach ($departments as $id => $n)<option value="{{ $id }}" @selected(in_array($id, old('departments', [])))>{{ $n }}</option>@endforeach</select></div>
        <div class="col-md-6"><label class="form-label">Levels <span class="text-muted">(none = all)</span></label><select name="levels[]" multiple size="6" class="form-select">@foreach ($levels as $l)<option value="{{ $l }}" @selected(in_array($l, old('levels', [])))>{{ $l }} level</option>@endforeach</select></div>
        <div class="col-12"><label class="form-label">Committee <span class="text-muted">(optional)</span></label><select name="committee[]" multiple size="6" class="form-select">@foreach ($staff as $id => $n)<option value="{{ $id }}" @selected(in_array($id, old('committee', [])))>{{ $n }}</option>@endforeach</select>
            <div class="form-text">When named, only these members (and approvers) can change this timetable. They also need the "Create timetables" permission.</div></div>
        <div class="col-12 text-muted fs-13">The courses to schedule are taken from course registrations for this semester, and from the curriculum where students have not registered yet. You can change class sizes, lecturers and needs on the next page.</div>
        <div class="col-12"><button class="btn btn-primary">Create</button></div>
    </div></form>
</div></div></div>
@endsection
