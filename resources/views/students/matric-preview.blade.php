@extends('layouts.master')

@section('content')
<div class="main-content">
    <div class="page-content">
        <div class="container-fluid" style="max-width:900px">
            <div class="page-title-box d-flex justify-content-between align-items-center"><h4 class="mb-0">Generate matric numbers: preview</h4>
                <a href="{{ route('matric.index') }}" class="btn btn-sm btn-light"><i class="ri-arrow-left-line me-1"></i>Matric numbers</a></div>
            @include('academics.partials.alerts')
            <div class="alert alert-info mb-3"><strong>{{ $preview->total() }}</strong> student(s) will get a number in the format <code>{{ $pattern }}</code>, in the order below. Nothing has been issued yet.
                If the format is wrong, <a href="{{ route('students.settings') }}">change it first</a>.</div>
            <div class="card">
                <div class="table-responsive" style="max-height:520px"><table class="table table-sm align-middle mb-0">
                    <thead class="table-light"><tr><th>#</th><th>Student</th><th>Programme</th><th>Entry session</th><th>Number</th></tr></thead>
                    <tbody>
                    @foreach ($preview->rows as $i => [$s, $number])
                        <tr><td class="text-muted">{{ $i + 1 }}</td><td>{{ $s->full_name }}</td><td>{{ $s->programme?->name }}</td><td>{{ $s->entrySession?->name }}</td><td><code>{{ $number }}</code></td></tr>
                    @endforeach
                    </tbody>
                </table></div>
            </div>
            <form method="POST" action="{{ route('matric.generate') }}" class="card"><div class="card-body d-flex justify-content-between align-items-center gap-3">
                @csrf
                @foreach ($preview->rows as [$s])<input type="hidden" name="student_ids[]" value="{{ $s->id }}">@endforeach
                <div class="text-muted fs-13">The numbers are issued as one batch. Each student is emailed their number. The batch can be undone, or corrected if the numbers are already in use, from Recent batches.</div>
                <button class="btn btn-primary text-nowrap" type="submit">Issue {{ $preview->total() }} number(s)</button>
            </div></form>
        </div>
    </div>
</div>
@endsection
