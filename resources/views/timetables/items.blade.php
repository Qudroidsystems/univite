@extends('layouts.master')

@section('content')
<div class="main-content"><div class="page-content"><div class="container-fluid">
    @include('partials.page-title', ['title' => 'What to schedule', 'icon' => 'ri-list-check', 'subtitle' => $t->title . ' · ' . $items->count() . ' item(s)', 'back' => [route('timetables.show', $t), 'Back to the timetable']])
    @include('academics.partials.alerts')
    <p class="text-muted">Class sizes come from course registrations, or from the curriculum where nobody has registered yet. {{ $t->isExam() ? 'Duration is the length of the paper in minutes.' : 'Each course meets the given number of times a week, each meeting lasting the given number of back-to-back periods.' }} Lecturers come from Define Courses; they are never put in two places at once.</p>
    @if ($editable)
        <div class="d-flex gap-2 mb-3">
            <form method="POST" action="{{ route('timetables.items.sync', $t) }}">@csrf<button class="btn btn-sm btn-light"><i class="ri-add-circle-line me-1"></i>Add courses not listed yet</button></form>
            <form method="POST" action="{{ route('timetables.items.sync', $t) }}">@csrf<input type="hidden" name="refresh" value="1"><button class="btn btn-sm btn-light"><i class="ri-refresh-line me-1"></i>Recount class sizes</button></form>
        </div>
    @endif

    <div class="card"><div class="table-responsive"><table class="table table-sm align-middle mb-0">
        <thead class="table-light"><tr><th>Course</th><th style="width:90px">{{ $t->isExam() ? 'Candidates' : 'Students' }}</th>@unless ($t->isExam())<th style="width:80px">Meetings / week</th>@endunless<th style="width:90px">{{ $t->isExam() ? 'Minutes' : 'Periods each' }}</th><th>Needs</th><th>Lecturers</th><th style="width:70px">On</th><th style="width:1%" class="text-nowrap">Last changed by</th><th></th></tr></thead>
        <tbody>
        @foreach ($items as $i)
            <tr class="{{ $i->is_active ? '' : 'opacity-50' }}">
                <td><strong>{{ $i->label }}</strong><div class="text-muted fs-12">{{ \Illuminate\Support\Str::limit($i->course?->title, 40) }} · {{ $i->course?->level }}L</div>@if ($i->course)<x-course-tag :course="$i->course" />@endif
                    @if ($editable)<input form="item-{{ $i->id }}" name="group_label" value="{{ $i->group_label }}" class="form-control form-control-sm mt-1" placeholder="Group (optional)" style="max-width:140px">@endif</td>
                <td><input type="number" form="item-{{ $i->id }}" name="students" min="0" value="{{ $i->students }}" class="form-control form-control-sm" @disabled(! $editable)></td>
                @unless ($t->isExam())<td><input type="number" form="item-{{ $i->id }}" name="meetings" min="1" max="10" value="{{ $i->meetings }}" class="form-control form-control-sm" @disabled(! $editable)></td>@endunless
                <td><input type="number" form="item-{{ $i->id }}" name="duration" min="1" value="{{ $i->duration }}" class="form-control form-control-sm" @disabled(! $editable)></td>
                <td style="min-width:200px"><select form="item-{{ $i->id }}" name="venue_type" class="form-select form-select-sm mb-1" @disabled(! $editable)><option value="">Any venue type</option>@foreach ($types as $k => $l)<option value="{{ $k }}" @selected($i->venue_type === $k)>{{ $l }}</option>@endforeach</select>
                    <select form="item-{{ $i->id }}" name="facilities[]" multiple class="form-select form-select-sm mb-1" size="2" title="Facilities needed" @disabled(! $editable)>@foreach ($facilities as $k => $l)<option value="{{ $k }}" @selected(in_array($k, $i->facilities ?? [], true))>{{ $l }}</option>@endforeach</select>
                    <select form="item-{{ $i->id }}" name="preferred_venue_id" class="form-select form-select-sm" @disabled(! $editable)><option value="">No preferred venue</option>@foreach ($venues as $v)<option value="{{ $v->id }}" @selected($i->preferred_venue_id === $v->id)>{{ $v->code }}</option>@endforeach</select></td>
                <td style="min-width:180px"><select form="item-{{ $i->id }}" name="staff[]" multiple size="3" class="form-select form-select-sm" @disabled(! $editable)>@foreach ($staff as $id => $n)<option value="{{ $id }}" @selected(in_array($id, $i->staff ?? []))>{{ $n }}</option>@endforeach</select></td>
                <td><input type="hidden" form="item-{{ $i->id }}" name="is_active" value="0"><input class="form-check-input" type="checkbox" form="item-{{ $i->id }}" name="is_active" value="1" @checked($i->is_active) @disabled(! $editable) title="Schedule this"></td>
                <td><x-last-change :record="$i" :among="$items" /></td>
                <td>@if ($editable)<form method="POST" action="{{ route('timetables.items.update', [$t, $i]) }}" id="item-{{ $i->id }}">@csrf @method('PUT')<button class="btn btn-sm btn-soft-primary">Save</button></form>@endif</td>
            </tr>
        @endforeach
        </tbody></table></div></div>

    @if ($editable)
        <div class="card"><div class="card-header"><h6 class="card-title mb-0">Add {{ $t->isExam() ? 'a paper' : 'a practical, tutorial or group' }}</h6><div class="text-muted fs-12">To split a large class, give the existing item a group name (Group A) and size, then add the same course again as Group B with its own size.</div></div>
            <div class="card-body"><form method="POST" action="{{ route('timetables.items.add', $t) }}" class="row g-2 align-items-end">@csrf
                <div class="col-md-3"><label class="form-label fs-12">Course</label><select name="course_id" data-lookup="courses" data-lookup-active="1" class="form-select form-select-sm" required>@foreach ($courses as $c)<option value="{{ $c->id }}">{{ $c->code }} · {{ \Illuminate\Support\Str::limit($c->title, 30) }}</option>@endforeach</select></div>
                <div class="col-md-2"><label class="form-label fs-12">Activity</label><select name="activity" class="form-select form-select-sm">@foreach (\App\Models\TimetableItem::ACTIVITIES as $k => $l)@if (($k === 'exam') === $t->isExam())<option value="{{ $k }}">{{ $l }}</option>@endif @endforeach</select></div>
                <div class="col-md-2"><label class="form-label fs-12">Group</label><input name="group_label" class="form-control form-control-sm" placeholder="Group B"></div>
                <div class="col-md-1"><label class="form-label fs-12">Size</label><input type="number" name="students" min="0" class="form-control form-control-sm" required></div>
                @unless ($t->isExam())<div class="col-md-1"><label class="form-label fs-12">Meetings</label><input type="number" name="meetings" min="1" value="1" class="form-control form-control-sm"></div>@endunless
                <div class="col-md-1"><label class="form-label fs-12">{{ $t->isExam() ? 'Minutes' : 'Periods' }}</label><input type="number" name="duration" min="1" value="{{ $t->isExam() ? 120 : 2 }}" class="form-control form-control-sm" required></div>
                <div class="col-md-2"><label class="form-label fs-12">Venue type</label><select name="venue_type" class="form-select form-select-sm"><option value="">Any</option>@foreach ($types as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></div>
                <div class="col-12"><input type="hidden" name="is_active" value="1"><button class="btn btn-sm btn-primary">Add</button></div>
            </form></div></div>
    @endif
</div></div></div>
@endsection
