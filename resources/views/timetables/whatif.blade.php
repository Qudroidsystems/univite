@extends('layouts.master')

@section('content')
<div class="main-content"><div class="page-content"><div class="container-fluid" style="max-width:1000px">
    @include('partials.page-title', ['title' => 'What if…', 'icon' => 'ri-flask-line', 'subtitle' => 'Test a change before making it: close a hall, change its capacity, or add days. Nothing is saved.', 'back' => [route('timetables.show', $t), $t->title]])
    <form method="GET" class="card"><div class="card-body row g-3"><input type="hidden" name="run" value="1">
        <div class="col-md-6"><label class="form-label">Close these venues</label><select name="close_venues[]" multiple size="8" class="form-select">@foreach ($venues as $v)<option value="{{ $v->id }}" @selected(in_array($v->id, $q['close_venues'] ?? []))>{{ $v->code }} · {{ $v->name }}</option>@endforeach</select></div>
        <div class="col-md-6"><label class="form-label">Change capacities</label><div style="max-height:200px;overflow:auto" class="border rounded p-2">@foreach ($venues as $v)<div class="d-flex gap-2 align-items-center mb-1"><span class="fs-13 flex-grow-1">{{ $v->code }} <span class="text-muted">(now {{ $t->isExam() ? $v->exam_capacity : $v->teaching_capacity }})</span></span><input type="number" min="0" name="capacity[{{ $v->id }}]" value="{{ $q['capacity'][$v->id] ?? '' }}" class="form-control form-control-sm" style="width:90px"></div>@endforeach</div></div>
        @if ($t->isExam())
            <div class="col-md-6"><label class="form-label">Add examination days</label><input name="extra_dates" value="{{ $q['extra_dates'] ?? '' }}" class="form-control font-monospace" placeholder="2026-11-23, 2026-11-24"></div>
        @else
            <div class="col-md-6"><label class="form-label d-block">Add teaching days</label>@foreach (\App\Models\Timetable::DAYS as $d => $n)@unless (in_array($d, $t->config['days'] ?? []))<div class="form-check form-check-inline"><input class="form-check-input" type="checkbox" name="extra_days[]" value="{{ $d }}" id="x{{ $d }}" @checked(in_array($d, $q['extra_days'] ?? []))><label class="form-check-label" for="x{{ $d }}">{{ $n }}</label></div>@endunless @endforeach</div>
        @endif
        <div class="col-12"><button class="btn btn-primary" onclick="this.innerText='Running…'">Run the test</button></div>
    </div></form>

    @if ($result)
        <div class="card"><div class="card-header"><h6 class="card-title mb-0">Result</h6></div><div class="table-responsive"><table class="table mb-0">
            <thead class="table-light"><tr><th></th><th>Now</th><th>With the change</th></tr></thead>
            <tbody>@foreach (['placed' => 'Placed', 'unplaced' => 'Could not be placed', 'violations' => 'Hard rules broken', 'score' => 'Soft score (lower is better)'] as $k => $l)
                <tr><th class="fw-normal">{{ $l }}</th><td>{{ $result['before'][$k] ?? '—' }}</td><td class="fw-semibold">{{ $result['after'][$k] }}</td></tr>@endforeach</tbody></table></div>
            @if ($result['unplaced'])<div class="card-body border-top"><h6>What could not be placed, and why</h6>
                @foreach ($result['unplaced'] as $x)<div class="mb-2"><strong>{{ $x['code'] }}</strong><ul class="fs-13 mb-0">@foreach ($x['why']['reasons'] as $r)<li>{{ $r }}</li>@endforeach</ul>@if ($x['why']['suggestions'])<div class="fs-13 text-success">{{ implode(' ', $x['why']['suggestions']) }}</div>@endif</div>@endforeach</div>@endif
        </div>
    @endif
</div></div></div>
@endsection
