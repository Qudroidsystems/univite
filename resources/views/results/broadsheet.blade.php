@extends('layouts.master')

@section('content')
<div class="main-content">
    <div class="page-content">
        <div class="container-fluid">
            <div class="page-title-box d-print-none"><h4 class="mb-0">Broadsheet</h4></div>
            <form method="GET" class="card d-print-none"><div class="card-body d-flex flex-wrap gap-2 align-items-end">
                <div><label class="form-label mb-1">Programme</label><select name="programme_id" class="form-select" required><option value="">Select…</option>@foreach ($programmes as $id => $n)<option value="{{ $id }}" @selected((string) $f['programme'] === (string) $id)>{{ $n }}</option>@endforeach</select></div>
                <div><label class="form-label mb-1">Level</label><select name="level" class="form-select" required><option value="">Select…</option>@foreach ($levels as $v => $l)<option value="{{ $v }}" @selected((string) $f['level'] === (string) $v)>{{ $l }}</option>@endforeach</select></div>
                <div><label class="form-label mb-1">Session</label><select name="session_id" class="form-select">@foreach ($sessions as $id => $n)<option value="{{ $id }}" @selected((string) $f['session'] === (string) $id)>{{ $n }}</option>@endforeach</select></div>
                <div><label class="form-label mb-1">Semester</label><select name="semester_type_id" class="form-select">@foreach ($types as $id => $n)<option value="{{ $id }}" @selected((string) $f['type'] === (string) $id)>{{ $n }}</option>@endforeach</select></div>
                <button class="btn btn-primary" type="submit">Show</button>
                @if ($sheet)<button type="button" class="btn btn-light" onclick="window.print()"><i class="ri-printer-line me-1"></i>Print</button>@endif
            </div></form>

            @if ($sheet)
                <div class="card"><div class="table-responsive">
                    <table class="table table-sm table-bordered align-middle mb-0 text-center">
                        <thead class="table-light"><tr><th class="text-start">Matric</th><th class="text-start">Name</th>
                            @foreach ($sheet['courses'] as $c)<th title="{{ $c->title }}">{{ $c->code }}<div class="fw-normal fs-11">{{ $c->credit_units }}u</div></th>@endforeach
                            <th>Units</th><th>GPA</th><th>CGPA</th><th>Remark</th></tr></thead>
                        <tbody>
                        @forelse ($sheet['rows'] as $r)
                            <tr><td class="text-start">{{ $r['student']->matric_label }}</td><td class="text-start">{{ $r['student']->full_name }}</td>
                                @foreach ($sheet['courses'] as $c)
                                    @php $x = $r['results']->get($c->id); @endphp
                                    <td class="{{ $x && ! $x->is_pass ? 'text-danger fw-semibold' : '' }}">{{ $x ? rtrim(rtrim(number_format($x->total, 2), '0'), '.') . ' ' . $x->grade : '—' }}</td>
                                @endforeach
                                <td>{{ $r['units'] }}</td><td>{{ number_format($r['gpa'], 2) }}</td><td class="fw-semibold">{{ number_format($r['cgpa'], 2) }}</td><td class="{{ $r['standing'] ? 'text-danger' : '' }}">{{ $r['standing'] ?? 'Good standing' }}</td></tr>
                        @empty
                            <tr><td colspan="{{ 6 + $sheet['courses']->count() }}" class="text-muted py-4">No registered students for this selection.</td></tr>
                        @endforelse
                        </tbody>
                    </table></div></div>
            @endif
        </div>
    </div>
</div>
@endsection
