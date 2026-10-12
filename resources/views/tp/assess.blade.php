@extends('layouts.master')

@section('content')
@php $ex = $posting->exercise; $a = $posting->assessments->firstWhere('visit_no', $visit); @endphp
<div class="main-content"><div class="page-content"><div class="container-fluid" style="max-width:800px">
    @include('partials.page-title', ['title' => $posting->student->full_name, 'icon' => 'ri-presentation-line', 'subtitle' => $posting->student->matric_number . ' · ' . $posting->school->name . ' · ' . $ex->name, 'back' => [route('tp.supervise'), 'My students']])
    @include('academics.partials.alerts')
    @if ($errors->any())<div class="alert alert-danger">{{ $errors->first() }}</div>@endif
    <form method="POST" action="{{ route('tp.assess.store', $posting) }}" class="card">@csrf
        <div class="card-body row g-3">
            <div class="col-md-3"><label class="form-label">Visit</label><select name="visit_no" class="form-select" onchange="location.search = '?visit=' + this.value">@for ($v = 1; $v <= $ex->visits_required; $v++)<option value="{{ $v }}" @selected($v === $visit)>Visit {{ $v }}</option>@endfor</select></div>
            <div class="col-md-3"><label class="form-label">Date</label><input name="visited_on" type="date" value="{{ old('visited_on', $a?->visited_on?->toDateString() ?? today()->toDateString()) }}" max="{{ today()->toDateString() }}" class="form-control" required></div>
            <div class="col-md-6"><label class="form-label">Lesson topic</label><input name="lesson_topic" value="{{ old('lesson_topic', $a?->lesson_topic) }}" class="form-control"></div>
            @foreach ($ex->criteria as $c)
                <div class="col-md-9 d-flex align-items-center">{{ $c['label'] }}</div>
                <div class="col-md-3"><div class="input-group input-group-sm"><input name="marks[{{ $c['key'] }}]" type="number" step="0.5" min="0" max="{{ $c['max'] }}" value="{{ old('marks.' . $c['key'], $a?->scores[$c['key']] ?? '') }}" class="form-control" required><span class="input-group-text">/ {{ $c['max'] }}</span></div></div>
            @endforeach
            <div class="col-12"><label class="form-label">Remarks</label><textarea name="remarks" rows="3" class="form-control">{{ old('remarks', $a?->remarks) }}</textarea></div>
            @if ($a)<div class="col-12 fs-12 text-muted">Last saved by {{ $a->supervisor?->name }} on {{ $a->updated_at->format('j M Y') }} ({{ $a->percent }}%).</div>@endif
        </div>
        <div class="card-footer text-end"><button class="btn btn-primary">Save marks</button></div>
    </form>
</div></div></div>
@endsection
