@extends('layouts.master')

@section('content')
<div class="main-content"><div class="page-content"><div class="container-fluid">
    @include('partials.page-title', ['title' => 'Versions', 'icon' => 'ri-git-commit-line', 'subtitle' => $t->title . ' · version ' . $t->version, 'back' => [route('timetables.show', $t), 'Back to the timetable']])
    @include('academics.partials.alerts')
    <div class="card"><div class="card-header"><h6 class="card-title mb-0">Other timetables of {{ $t->session?->name }} · {{ $t->semesterType?->name }}</h6><div class="text-muted fs-12">Compare this one with an earlier version or another draft of the same semester, and copy its placement into this draft.</div></div>
        <div class="table-responsive"><table class="table align-middle mb-0"><thead class="table-light"><tr><th>Timetable</th><th>Version</th><th>Status</th><th>Placed</th><th>Last generated</th><th></th></tr></thead><tbody>
            @forelse ($others as $o)
                <tr class="{{ $other && $other->id === $o->id ? 'table-primary' : '' }}"><td><a href="{{ route('timetables.show', $o) }}">{{ $o->title }}</a></td><td>{{ $o->version }}</td>
                    <td><span class="badge {{ \App\Models\Timetable::BADGES[$o->status] }}">{{ \App\Models\Timetable::STATUSES[$o->status] }}</span></td>
                    <td>{{ ($o->summary['placed'] ?? 0) . ' / ' . ($o->summary['units'] ?? 0) }}</td><td class="fs-13">{{ $o->generated_at?->format('j M Y H:i') ?? '—' }}</td>
                    <td class="text-end text-nowrap"><a href="{{ route('timetables.versions', [$t, 'with' => $o->id]) }}" class="btn btn-sm btn-light">Compare</a>
                        @if ($canPrepare)<form method="POST" action="{{ route('timetables.versions.restore', [$t, $o]) }}" class="d-inline" onsubmit="return confirm('Replace this draft\'s placement with the one in {{ $o->title }}? Classes that are not in it are left unplaced.')">@csrf<button class="btn btn-sm btn-soft-warning">Restore its placement</button></form>@endif</td></tr>
            @empty
                <tr><td colspan="6" class="text-center text-muted py-4">No other timetables for this semester.</td></tr>
            @endforelse
        </tbody></table></div></div>
    @if ($diff)@include('timetables.partials.diff', ['diff' => $diff, 'aLabel' => $other->title, 'bLabel' => 'This timetable'])@endif
</div></div></div>
@endsection
