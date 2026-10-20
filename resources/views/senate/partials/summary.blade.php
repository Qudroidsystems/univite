{{-- Per faculty, department and programme: how many are eligible, not, or need a decision; classes; commonest reasons. --}}
@php
    $row = function ($label, $x, $level) {
        return compact('label', 'x', 'level');
    };
    $lines = [];
    foreach ($summary['faculties'] as $f => $fac) {
        $lines[] = $row($f, $fac['total'], 0);
        foreach ($fac['departments'] as $d => $dep) {
            $lines[] = $row($d, $dep['total'], 1);
            foreach ($dep['programmes'] as $p => $x) {
                $lines[] = $row($p, $x, 2);
            }
        }
    }
    $all = $summary['all'];
@endphp
<div class="row">
    @foreach ([['Candidates', $all['candidates'], 'primary'], ['Eligible', $all['eligible'], 'success'], ['Not eligible', $all['not_eligible'], 'danger'], ['Needs decision', $all['needs_decision'], 'warning'], ['Approved by Senate', $all['approved'], 'info']] as [$l, $n, $c])
        <div class="col"><div class="card"><div class="card-body"><div class="text-muted fs-12 text-uppercase fw-semibold">{{ $l }}</div><div class="fs-3 fw-semibold text-{{ $c }}">{{ $n }}</div></div></div></div>
    @endforeach
</div>
<div class="card"><div class="table-responsive"><table class="table table-sm align-middle mb-0 uv-senate-summary">
    <thead class="table-light"><tr><th>Faculty · department · programme</th><th class="text-center">Candidates</th><th class="text-center">Eligible</th><th class="text-center">Not eligible</th><th class="text-center">Needs decision</th><th class="text-center">Approved</th><th class="text-center">Avg CGPA</th><th class="text-center">Course pass rate</th><th>Classes of degree</th><th>Commonest reasons</th></tr></thead>
    <tbody>
    @forelse ($lines as $l)
        @php $x = $l['x']; @endphp
        <tr class="{{ $l['level'] === 0 ? 'table-primary fw-semibold' : ($l['level'] === 1 ? 'fw-medium' : '') }}">
            <td style="padding-left:{{ 8 + $l['level'] * 18 }}px">{{ $l['label'] }}</td>
            <td class="text-center">{{ $x['candidates'] }}</td><td class="text-center text-success">{{ $x['eligible'] }}</td><td class="text-center text-danger">{{ $x['not_eligible'] }}</td>
            <td class="text-center text-warning">{{ $x['needs_decision'] }}</td><td class="text-center">{{ $x['approved'] }}</td><td class="text-center">{{ $x['average_cgpa'] !== null ? number_format($x['average_cgpa'], 2) : '—' }}</td>
            <td class="text-center">{{ $x['pass_rate'] !== null ? $x['pass_rate'] . '%' : '—' }}</td>
            <td class="fs-12">@foreach ($x['classes'] as $c => $n)<span class="badge bg-light text-body border me-1">{{ $c }}: {{ $n }}</span>@endforeach</td>
            <td class="fs-12">@foreach ($x['reasons'] as $r => $n)<div>{{ $r }} <span class="text-muted">({{ $n }})</span></div>@endforeach</td>
        </tr>
    @empty
        <tr><td colspan="10" class="text-center text-muted py-5">No students at the Senate levels in this view.</td></tr>
    @endforelse
    </tbody>
</table></div></div>
