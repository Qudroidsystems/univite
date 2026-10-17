@extends('layouts.master')

@section('content')
<div class="main-content">
    <div class="page-content">
        <div class="container-fluid">
            <div class="page-title-box"><h4 class="mb-0">Carry-over waivers</h4></div>
            @include('academics.partials.alerts')
            <p class="text-muted">A waived course stays on the student's transcript and in their CGPA, but it no longer has to be registered again and does not stop them graduating. Waive a course from the <a href="{{ route('results.summary') }}">result summary</a>, next to the student's outstanding courses.</p>

            <form method="GET" class="card"><div class="card-body d-flex flex-wrap gap-2 align-items-end">
                <div><label class="form-label mb-1">Department</label><select name="department_id" class="form-select"><option value="">All my departments</option>@foreach ($departments as $id => $n)<option value="{{ $id }}" @selected((string) $department === (string) $id)>{{ $n }}</option>@endforeach</select></div>
                <div><label class="form-label mb-1">Show</label><select name="show" class="form-select">@foreach (['active' => 'In force', 'withdrawn' => 'Withdrawn', 'all' => 'All'] as $k => $l)<option value="{{ $k }}" @selected($show === $k)>{{ $l }}</option>@endforeach</select></div>
                <button class="btn btn-primary" type="submit">Show</button>
            </div></form>

            <div class="card"><div class="table-responsive"><table class="table table-sm align-middle mb-0">
                <thead class="table-light"><tr><th>Student</th><th>Programme</th><th>Course</th><th>Reason</th><th>Granted</th><th>Status</th><th style="width:1%" class="text-nowrap">Last changed by</th><th></th></tr></thead>
                <tbody>
                @forelse ($waivers as $w)
                    <tr><td>{{ $w->student->full_name }}<div class="text-muted fs-12">{{ $w->student->matric_label }}</div></td>
                        <td>{{ $w->student->programme?->name }}</td>
                        <td>{{ $w->course->code }}<div class="text-muted fs-12">{{ $w->course->title }}</div></td>
                        <td class="fs-13">{{ $w->reason }}</td>
                        <td class="fs-13">{{ $w->created_at->format('j M Y') }}<div class="text-muted fs-12">{{ $w->granter?->name }}</div></td>
                        <td>@if ($w->isActive())<span class="badge bg-success-subtle text-success">In force</span>@else<span class="badge bg-secondary-subtle text-secondary">Withdrawn {{ $w->revoked_at->format('j M Y') }}</span><div class="text-muted fs-12">{{ $w->revoke_reason }}</div>@endif</td>
                        <td><x-last-change :record="$w" :among="$waivers" /></td>
                        <td>@if ($w->isActive())
                            <form method="POST" action="{{ route('results.waivers.withdraw', $w->id) }}" class="d-flex gap-1" style="min-width:260px">@csrf
                                <input name="reason" class="form-control form-control-sm" placeholder="Why withdraw it?" required>
                                <button class="btn btn-sm btn-soft-danger" type="submit">Withdraw</button></form>
                        @endif</td></tr>
                @empty
                    <tr><td colspan="8" class="text-center text-muted py-4">No waivers.</td></tr>
                @endforelse
                </tbody>
            </table></div>
            @if ($waivers->hasPages())<div class="card-footer">{{ $waivers->links() }}</div>@endif
            </div>
        </div>
    </div>
</div>
@endsection
