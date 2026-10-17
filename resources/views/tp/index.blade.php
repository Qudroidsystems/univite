@extends('layouts.master')

@section('content')
<div class="main-content"><div class="page-content"><div class="container-fluid">
    @include('partials.page-title', ['title' => 'Teaching practice', 'icon' => 'ri-presentation-line'])
    @include('academics.partials.alerts')
    <div class="card">
        <div class="card-header d-flex justify-content-between"><h6 class="card-title mb-0">Exercises</h6>
            <div class="d-flex gap-2"><a href="{{ route('tp.schools.index') }}" class="btn btn-sm btn-light">Schools</a>@can('Set up teaching practice')<a href="{{ route('tp.exercises.create') }}" class="btn btn-sm btn-primary"><i class="ri-add-line me-1"></i>New exercise</a>@endcan</div></div>
        <div class="table-responsive"><table class="table align-middle mb-0">
            <thead class="table-light"><tr><th>Exercise</th><th>Session</th><th>Dates</th><th class="text-center">Students</th><th class="text-center">Posted</th><th>Scores</th><th style="width:1%" class="text-nowrap">Last changed by</th></tr></thead><tbody>
            @forelse ($exercises as $e)
                <tr><td><a href="{{ route('tp.exercises.show', $e) }}" class="fw-semibold">{{ $e->name }}</a></td><td>{{ $e->session->name }}</td>
                    <td class="fs-13">{{ $e->starts_on?->format('j M Y') ?? '—' }} – {{ $e->ends_on?->format('j M Y') ?? '—' }}</td>
                    <td class="text-center">{{ $e->postings_count }}</td><td class="text-center">{{ $e->posted_count }}</td>
                    <td class="fs-13">{{ $e->scores_published_at ? 'Sent ' . $e->scores_published_at->format('j M Y') : '—' }}</td>
                    <td><x-last-change :record="$e" :among="$exercises" /></td></tr>
            @empty
                <tr><td colspan="7" class="text-center text-muted py-5">No exercises yet.</td></tr>
            @endforelse
        </tbody></table></div>
    </div>
</div></div></div>
@endsection
