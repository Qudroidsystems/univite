@extends('layouts.master')

@section('content')
@php $first = $rows->first(); @endphp
<div class="main-content"><div class="page-content"><div class="container-fluid" style="max-width:1000px">
    @include('partials.page-title', ['title' => $u['code'] . ($u['meeting'] > 1 || ! $t->isExam() ? ' · meeting ' . $u['meeting'] : ''), 'icon' => 'ri-drag-move-2-line', 'subtitle' => ($u['title'] ?? '') . ' · ' . $u['size'] . ($t->isExam() ? ' candidates' : ' students'), 'back' => [route('timetables.show', $t), $t->title]])
    @include('academics.partials.alerts')

    <div class="card"><div class="card-body">
        @if ($first)
            <div class="d-flex justify-content-between flex-wrap gap-2"><div><div class="text-muted fs-12">Now</div><div class="fs-5 fw-semibold">{{ $first->when }}</div>
                <div>{{ $rows->map(fn ($r) => ($r->venue?->name ?? 'No venue') . ($rows->count() > 1 ? " ({$r->seats})" : ''))->implode(' + ') }}</div></div>
                @if ($rows->contains('locked', true))<div><span class="badge bg-warning-subtle text-warning"><i class="ri-lock-line"></i> Placed by hand</span>
                    @if ($editable)<form method="POST" action="{{ route('timetables.unlock', [$t, $unit]) }}" class="d-inline">@csrf<button class="btn btn-sm btn-light ms-1">Unlock</button></form>@endif</div>@endif</div>
        @else
            <div class="text-warning fw-semibold">Not placed.</div>
        @endif
        @foreach ($problems as $m)<div class="alert alert-danger py-2 mt-2 mb-0 fs-13">{{ $m }}</div>@endforeach
    </div></div>

    @if ($editable)
        <div class="card"><div class="card-header"><h6 class="card-title mb-0">Where it could go instead</h6><div class="text-muted fs-12">Every option keeps all hard rules with everything else where it is. Cheapest first (fewest broken preferences).</div></div>
            <div class="table-responsive"><table class="table table-sm align-middle mb-0"><tbody>
                @forelse ($alternatives as $i => $a)
                    <tr><td>{{ $a['label'] }} @if ($i === 0)<span class="badge bg-success-subtle text-success">best</span>@endif</td><td class="text-muted fs-12">score {{ $a['cost'] }}</td>
                        <td class="text-end"><form method="POST" action="{{ route('timetables.move', [$t, $unit]) }}" class="d-flex gap-1 justify-content-end">@csrf
                            <input type="hidden" name="slots" value="{{ implode(',', $a['slots']) }}"><input type="hidden" name="venues" value="{{ json_encode($a['venues']) }}">
                            <input name="reason" class="form-control form-control-sm" placeholder="Reason (optional)" style="width:180px"><button class="btn btn-sm btn-soft-primary">Move here</button></form></td></tr>
                @empty
                    <tr><td class="text-muted">There is no other place that keeps every hard rule. Consider what-if: an extra day, another venue, or splitting the class.</td></tr>
                @endforelse
            </tbody></table></div></div>

        <div class="card"><div class="card-header"><h6 class="card-title mb-0">Put it somewhere specific</h6><div class="text-muted fs-12">The move is checked first. If it would break a hard rule it is refused and you are told why.</div></div>
            <div class="card-body"><form method="POST" action="{{ route('timetables.move', [$t, $unit]) }}" class="row g-2 align-items-end">@csrf
                <div class="col-md-5"><label class="form-label fs-12">{{ $t->isExam() ? 'Sitting' : 'Starting period' }}</label><select name="slots" class="form-select form-select-sm">@foreach ($slots as $k => $s)<option value="{{ $k }}" @selected($first && ($first->slots[0] ?? null) === $k)>{{ $s['date'] ? \Carbon\Carbon::parse($s['date'])->format('D j M') : \App\Models\Timetable::DAYS[$s['day']] }} {{ $s['start'] }}–{{ $s['end'] }}</option>@endforeach</select></div>
                <div class="col-md-4"><label class="form-label fs-12">Venue</label><select name="venues" class="form-select form-select-sm">@foreach ($venues as $v)<option value="{{ $v['id'] }}" @selected($first?->venue_id === $v['id'])>{{ $v['code'] }} · {{ $v['name'] }} ({{ $v['cap'] }})</option>@endforeach</select></div>
                <div class="col-md-3"><input name="reason" class="form-control form-control-sm mb-1" placeholder="Reason (optional)"><button class="btn btn-sm btn-primary w-100">Check and move</button></div>
            </form></div></div>
    @endif
</div></div></div>
@endsection
