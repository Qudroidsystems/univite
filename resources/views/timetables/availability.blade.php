@extends('layouts.master')

@section('content')
<div class="main-content"><div class="page-content"><div class="container-fluid">
    @include('partials.page-title', ['title' => 'Staff availability', 'icon' => 'ri-user-unfollow-line', 'subtitle' => 'Times lecturers and invigilators must not be scheduled. Approved leave is taken into account automatically.'])
    @include('academics.partials.alerts')
    <div class="card"><div class="card-body"><form method="POST" action="{{ route('staff-availability.store') }}" class="row g-2 align-items-end">@csrf
        @if ($all)<div class="col-md-3"><label class="form-label fs-12">Who</label><select name="user_id" class="form-select form-select-sm">@foreach ($staff as $id => $n)<option value="{{ $id }}">{{ $n }}</option>@endforeach</select></div>@endif
        <div class="col-md-2"><label class="form-label fs-12">When</label><select name="kind" class="form-select form-select-sm" onchange="this.form.querySelectorAll('[data-k]').forEach(e => e.classList.toggle('d-none', e.dataset.k !== this.value))"><option value="weekly">Every week</option><option value="dates">Dates</option></select></div>
        <div class="col-md-2" data-k="weekly"><label class="form-label fs-12">Day</label><select name="day" class="form-select form-select-sm">@foreach (\App\Models\Timetable::DAYS as $d => $n)<option value="{{ $d }}">{{ $n }}</option>@endforeach</select></div>
        <div class="col-md-2 d-none" data-k="dates"><label class="form-label fs-12">From</label><input type="date" name="date_from" class="form-control form-control-sm"></div>
        <div class="col-md-2 d-none" data-k="dates"><label class="form-label fs-12">To</label><input type="date" name="date_to" class="form-control form-control-sm"></div>
        <div class="col-md-1"><label class="form-label fs-12">From</label><input type="time" name="starts_at" class="form-control form-control-sm"></div>
        <div class="col-md-1"><label class="form-label fs-12">To</label><input type="time" name="ends_at" class="form-control form-control-sm"></div>
        <div class="col-md-2"><label class="form-label fs-12">Reason</label><input name="reason" class="form-control form-control-sm"></div>
        <div class="col-md-1"><button class="btn btn-sm btn-primary w-100">Add</button></div>
        <div class="col-12 form-text">Leave the times empty for the whole day.</div>
    </form></div></div>
    <div class="card"><div class="table-responsive"><table class="table table-sm align-middle mb-0">
        <thead class="table-light"><tr><th>Who</th><th>When</th><th>Times</th><th>Reason</th><th></th></tr></thead><tbody>
        @forelse ($rows as $r)
            <tr><td>{{ $r->user?->name }}</td><td>{{ $r->day ? 'Every ' . \App\Models\Timetable::DAYS[$r->day] : $r->date_from?->format('j M Y') . ($r->date_to && ! $r->date_to->eq($r->date_from) ? ' – ' . $r->date_to->format('j M Y') : '') }}</td>
                <td>{{ $r->starts_at ? substr($r->starts_at, 0, 5) . '–' . substr($r->ends_at, 0, 5) : 'All day' }}</td><td class="text-muted">{{ $r->reason }}</td>
                <td class="text-end"><form method="POST" action="{{ route('staff-availability.destroy', $r) }}">@csrf @method('DELETE')<button class="btn btn-sm btn-light">Remove</button></form></td></tr>
        @empty
            <tr><td colspan="5" class="text-center text-muted py-3">Nothing recorded.</td></tr>
        @endforelse
        </tbody></table></div>@if ($rows->hasPages())<div class="card-body">{{ $rows->links() }}</div>@endif</div>
</div></div></div>
@endsection
