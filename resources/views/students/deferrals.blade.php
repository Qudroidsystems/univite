@extends('layouts.master')

@section('content')
<div class="main-content"><div class="page-content"><div class="container-fluid">
    @include('partials.page-title', ['title' => 'Deferred admissions', 'icon' => 'ri-time-line', 'subtitle' => 'Students who put off their studies to a later session. Defer a student from their profile; bring them back here when their session comes.'])
    @include('academics.partials.alerts')
    <ul class="nav nav-pills mb-3">
        <li class="nav-item"><a class="nav-link {{ $status === 'deferred' ? 'active' : '' }}" href="{{ route('students.deferrals') }}">Deferred</a></li>
        <li class="nav-item"><a class="nav-link {{ $status === 'resumed' ? 'active' : '' }}" href="{{ route('students.deferrals', ['status' => 'resumed']) }}">Resumed</a></li>
    </ul>
    <div class="card"><div class="table-responsive"><table class="table align-middle mb-0">
        <thead class="table-light"><tr><th>Student</th><th>Programme</th><th>From</th><th>Returns in</th><th>Reason</th><th>By</th><th></th></tr></thead>
        <tbody>
        @forelse ($rows as $d)
            <tr>
                <td><a href="{{ route('students.show', $d->student_id) }}" class="fw-semibold">{{ $d->student->full_name }}</a><div class="text-muted fs-12">{{ $d->student->matric_label }}</div></td>
                <td>{{ $d->student->programme?->name }}</td>
                <td>{{ $d->fromSession?->name }}</td>
                <td>{{ $d->toSession?->name }}</td>
                <td class="fs-13">{{ $d->reason }}</td>
                <td class="fs-13">{{ $d->by?->name }}<div class="text-muted fs-12">{{ $d->created_at->format('d M Y') }}</div></td>
                <td class="text-end">
                    @if ($d->status === 'deferred')
                        @can('Manage deferred admissions')
                            <form method="POST" action="{{ route('students.deferrals.resume', $d) }}" onsubmit="return confirm('Bring {{ addslashes($d->student->full_name) }} back now?')">@csrf<button class="btn btn-sm btn-soft-success">Resume</button></form>
                        @endcan
                    @else
                        <span class="text-muted fs-12">Resumed {{ $d->resumed_at?->format('d M Y') }}</span>
                    @endif
                </td>
            </tr>
        @empty
            <tr><td colspan="7" class="text-center text-muted py-4">No {{ $status }} students.</td></tr>
        @endforelse
        </tbody>
    </table></div></div>
    {{ $rows->links() }}
</div></div></div>
@endsection
