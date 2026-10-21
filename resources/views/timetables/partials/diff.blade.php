{{-- What changes between two placements (TimetableRuns::diff). $diff, $aLabel, $bLabel --}}
<div class="card"><div class="card-header"><h6 class="card-title mb-0">{{ $aLabel }} → {{ $bLabel }}</h6>
    <div class="text-muted fs-12">{{ $diff['same'] }} unchanged · {{ count($diff['moved']) }} moved to another time · {{ count($diff['venue']) }} only changed venue · {{ count($diff['only_a']) }} only in {{ $aLabel }} · {{ count($diff['only_b']) }} only in {{ $bLabel }}</div></div>
    @if (! $diff['moved'] && ! $diff['venue'] && ! $diff['only_a'] && ! $diff['only_b'])
        <div class="card-body text-muted">The two are the same.</div>
    @else
        <div class="table-responsive" style="max-height:480px"><table class="table table-sm mb-0"><thead class="table-light sticky-top"><tr><th>Class meeting</th><th>Change</th><th>{{ $aLabel }}</th><th>{{ $bLabel }}</th></tr></thead><tbody>
            @foreach ($diff['moved'] as $r)<tr><td class="fw-semibold">{{ $r['code'] }}</td><td><span class="badge bg-primary-subtle text-primary">Moved</span></td><td class="fs-13">{{ $r['a'] }}</td><td class="fs-13">{{ $r['b'] }}</td></tr>@endforeach
            @foreach ($diff['venue'] as $r)<tr><td class="fw-semibold">{{ $r['code'] }}</td><td><span class="badge bg-info-subtle text-info">Venue</span></td><td class="fs-13">{{ $r['a'] }}</td><td class="fs-13">{{ $r['b'] }}</td></tr>@endforeach
            @foreach ($diff['only_a'] as $r)<tr><td class="fw-semibold">{{ $r['code'] }}</td><td><span class="badge bg-warning-subtle text-warning">Not in {{ $bLabel }}</span></td><td class="fs-13">{{ $r['a'] }}</td><td>—</td></tr>@endforeach
            @foreach ($diff['only_b'] as $r)<tr><td class="fw-semibold">{{ $r['code'] }}</td><td><span class="badge bg-success-subtle text-success">Added</span></td><td>—</td><td class="fs-13">{{ $r['b'] }}</td></tr>@endforeach
        </tbody></table></div>
    @endif
</div>
