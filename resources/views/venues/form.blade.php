@extends('layouts.master')

@section('content')
@php $v = $venue; $old = fn ($k, $d = null) => old($k, $v->{$k} ?? $d); @endphp
<div class="main-content"><div class="page-content"><div class="container-fluid">
    @include('partials.page-title', ['title' => $v->exists ? 'Edit ' . $v->name : 'Add a venue', 'icon' => 'ri-building-2-line', 'back' => [$v->exists ? route('venues.show', $v) : route('venues.index'), $v->exists ? $v->name : 'Venues']])
    @include('academics.partials.alerts')

    <form method="POST" action="{{ $v->exists ? route('venues.update', $v) : route('venues.store') }}">
        @csrf @if ($v->exists) @method('PUT') @endif
        <div class="card"><div class="card-header"><h6 class="card-title mb-0">Identity and location</h6></div><div class="card-body row g-3">
            <div class="col-md-3"><label class="form-label">Code</label><input name="code" value="{{ $old('code') }}" class="form-control" maxlength="30" required placeholder="LT-04"><div class="form-text">Short and unique. Printed on seat numbers.</div></div>
            <div class="col-md-5"><label class="form-label">Name</label><input name="name" value="{{ $old('name') }}" class="form-control" required placeholder="Lecture Theatre 4"></div>
            <div class="col-md-4"><label class="form-label">Type</label><select name="type" class="form-select" required>@foreach ($types as $k => $t)<option value="{{ $k }}" @selected($old('type') === $k)>{{ $t }}</option>@endforeach</select></div>
            <div class="col-md-3"><label class="form-label">Campus</label><input name="campus" value="{{ $old('campus') }}" class="form-control"></div>
            <div class="col-md-3"><label class="form-label">Building</label><input name="building" value="{{ $old('building') }}" class="form-control"><div class="form-text">Used to avoid moving a class between buildings.</div></div>
            <div class="col-md-2"><label class="form-label">Floor</label><input name="floor" value="{{ $old('floor') }}" class="form-control"></div>
            <div class="col-md-2"><label class="form-label">Room number</label><input name="room_number" value="{{ $old('room_number') }}" class="form-control"></div>
            <div class="col-md-2"><label class="form-label">Hall entry</label><input name="entry_info" value="{{ $old('entry_info') }}" class="form-control" placeholder="North entrance"></div>
            <div class="col-md-6"><label class="form-label">Owning department <span class="text-muted">(optional)</span></label><select name="department_id" data-lookup="departments" data-lookup-active="1" class="form-select"><option value="">Shared by everyone</option>@foreach ($departments as $id => $n)<option value="{{ $id }}" @selected((int) $old('department_id') === $id)>{{ $n }}</option>@endforeach</select><div class="form-text">Timetables prefer giving a department its own rooms.</div></div>
            <div class="col-md-3"><label class="form-label">Status</label><select name="status" class="form-select">@foreach (\App\Models\Venue::STATUSES as $k => $s)<option value="{{ $k }}" @selected($old('status', 'available') === $k)>{{ $s }}</option>@endforeach</select></div>
            <div class="col-md-3 d-flex align-items-end"><div class="form-check form-switch"><input type="hidden" name="is_active" value="0"><input class="form-check-input" type="checkbox" name="is_active" value="1" id="active" @checked($old('is_active', true))><label class="form-check-label" for="active">In the register (active)</label></div></div>
        </div></div>

        <div class="card"><div class="card-header"><h6 class="card-title mb-0">Capacity and use</h6><div class="text-muted fs-12">Physical capacity is the installed seats. Usable capacities are what may actually be used after spacing, aisles, safety and accessibility.</div></div><div class="card-body row g-3">
            <div class="col-md-4"><label class="form-label">Installed seats</label><input type="number" min="0" name="capacity" value="{{ $old('capacity', 0) }}" class="form-control" required></div>
            <div class="col-md-4"><label class="form-label">Usable for lectures</label><input type="number" min="0" name="teaching_capacity" value="{{ $old('teaching_capacity', 0) }}" class="form-control" required></div>
            <div class="col-md-4"><label class="form-label">Usable for examinations</label><input type="number" min="0" name="exam_capacity" value="{{ $old('exam_capacity', 0) }}" class="form-control" required></div>
            <div class="col-md-6"><label class="form-label d-block">May be used for</label>
                @foreach (\App\Models\Venue::USES as $k => $u)<div class="form-check form-check-inline"><input class="form-check-input" type="checkbox" name="uses[]" value="{{ $k }}" id="use-{{ $k }}" @checked(in_array($k, old('uses', $v->uses ?? []), true))><label class="form-check-label" for="use-{{ $k }}">{{ $u }}</label></div>@endforeach</div>
            <div class="col-md-6"><label class="form-label d-block">Facilities</label>
                @foreach ($facilities as $k => $f)<div class="form-check form-check-inline"><input class="form-check-input" type="checkbox" name="facilities[]" value="{{ $k }}" id="fac-{{ $k }}" @checked(in_array($k, old('facilities', $v->facilities ?? []), true))><label class="form-check-label" for="fac-{{ $k }}">{{ $f }}</label></div>@endforeach
                <div class="form-text">Edit the list under Reference Lists → Venue facilities.</div></div>
            <div class="col-md-6"><div class="form-check form-switch"><input type="hidden" name="shared_exams" value="0"><input class="form-check-input" type="checkbox" name="shared_exams" value="1" id="shared" @checked($old('shared_exams', true))><label class="form-check-label" for="shared">Several papers may be written here at the same time<div class="text-muted fs-12">Candidates of different papers are interleaved so that candidates of the same paper do not sit together.</div></label></div></div>
            <div class="col-md-6"><div class="form-check form-switch"><input type="hidden" name="wheelchair_access" value="0"><input class="form-check-input" type="checkbox" name="wheelchair_access" value="1" id="wheel" @checked($old('wheelchair_access'))><label class="form-check-label" for="wheel">Wheelchair access</label></div></div>
        </div></div>

        <div class="card"><div class="card-header"><h6 class="card-title mb-0">Examination seat map <span class="text-muted fw-normal">(optional)</span></h6><div class="text-muted fs-12">Rows and seats per row as laid out for examinations. Seats are numbered R01-S01 (row 1, seat 1) from the front left. Without a map, rows of ten are assumed.</div></div><div class="card-body row g-3">
            <div class="col-md-3"><label class="form-label">Rows</label><input type="number" min="1" name="seat_rows" value="{{ $old('seat_rows') }}" class="form-control"></div>
            <div class="col-md-3"><label class="form-label">Seats per row</label><input type="number" min="1" name="seats_per_row" value="{{ $old('seats_per_row') }}" class="form-control"></div>
            <div class="col-md-6"><label class="form-label">Blocked seats</label><textarea name="blocked_seats" rows="2" class="form-control font-monospace" placeholder="R01-S05, R02-S05">{{ old('blocked_seats', implode(', ', $v->blocked_seats ?? [])) }}</textarea><div class="form-text">Never used: broken chairs, aisles, the invigilators' desk.</div></div>
            <div class="col-md-6"><label class="form-label">Accessible seats</label><textarea name="accessible_seats" rows="2" class="form-control font-monospace" placeholder="R01-S01, R01-S02">{{ old('accessible_seats', implode(', ', $v->accessible_seats ?? [])) }}</textarea><div class="form-text">Kept for candidates who need them (marked on the student's record).</div></div>
            <div class="col-md-6"><label class="form-label">Notes</label><textarea name="notes" rows="2" class="form-control">{{ $old('notes') }}</textarea></div>
        </div></div>

        <div class="d-flex gap-2 mb-4"><button class="btn btn-primary"><i class="ri-save-line me-1"></i>{{ $v->exists ? 'Save changes' : 'Add venue' }}</button><a href="{{ $v->exists ? route('venues.show', $v) : route('venues.index') }}" class="btn btn-light">Back</a></div>
    </form>
</div></div></div>
@endsection
