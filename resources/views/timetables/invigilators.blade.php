@extends('layouts.master')

@section('content')
@php $plan = $t->summary['invigilation'] ?? null; @endphp
<div class="main-content"><div class="page-content"><div class="container-fluid">
    @include('partials.page-title', ['title' => 'Invigilation duties', 'icon' => 'ri-team-line', 'subtitle' => $t->title, 'back' => [route('timetables.show', $t), $t->title]])
    @include('academics.partials.alerts')
    <div class="card"><div class="card-body d-flex flex-wrap gap-3 align-items-center justify-content-between">
        <div>@if ($plan){{ $plan['assigned'] }} of {{ $plan['needed'] }} duties assigned from a pool of {{ $plan['pool'] }}.@if ($plan['advice']) <span class="text-danger">{{ $plan['advice'] }}</span>@endif @else Invigilators are assigned when the timetable is generated.@endif</div>
        @if ($t->isEditable())@can('Create timetables')<form method="POST" action="{{ route('timetables.replan', $t) }}">@csrf<button class="btn btn-sm btn-soft-primary"><i class="ri-refresh-line me-1"></i>Assign again</button></form>@endcan @endif
    </div></div>
    <div class="row">
        <div class="col-lg-8">
            @forelse ($duties as $slot => $rows)
                <div class="card"><div class="card-header py-2"><strong>{{ $rows->first()->date->format('l j M Y') }}, {{ substr($rows->first()->starts_at, 0, 5) }}–{{ substr($rows->first()->ends_at, 0, 5) }}</strong></div>
                    <div class="table-responsive"><table class="table table-sm mb-0"><tbody>
                        @foreach ($rows->groupBy('venue_id') as $vid => $people)
                            <tr><td style="width:30%"><strong>{{ $people->first()->venue?->name }}</strong><div class="text-muted fs-12">{{ ($papers[$slot . '|' . $vid] ?? collect())->map(fn ($e) => $e->course?->code . ' (' . $e->seats . ')')->implode(', ') }}</div></td>
                                <td>{{ $people->sortBy(fn ($p) => $p->role === 'chief' ? 0 : 1)->map(fn ($p) => $p->user?->name . ($p->role === 'chief' ? ' (chief)' : ''))->implode(', ') }}</td></tr>
                        @endforeach
                    </tbody></table></div></div>
            @empty
                <div class="card"><div class="card-body text-muted">No duties yet. Set the invigilator pool under Rules, then generate.</div></div>
            @endforelse
        </div>
        <div class="col-lg-4"><div class="card"><div class="card-header"><h6 class="card-title mb-0">Duties per person</h6></div>
            <ul class="list-group list-group-flush">@foreach ($load as [$user, $n])<li class="list-group-item d-flex justify-content-between"><span>{{ $user?->name }}</span><span class="badge bg-light text-body border">{{ $n }}</span></li>@endforeach</ul></div></div>
    </div>
</div></div></div>
@endsection
