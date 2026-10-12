@extends('layouts.master')

@section('content')
<div class="main-content"><div class="page-content"><div class="container-fluid">
    @include('partials.page-title', ['title' => 'My teaching practice students', 'icon' => 'ri-presentation-line'])
    @include('academics.partials.alerts')
    @foreach ($postings->groupBy(fn ($p) => $p->exercise->name . ' · ' . $p->school->name) as $group => $rows)
        <div class="card"><div class="card-header"><h6 class="card-title mb-0">{{ $group }}</h6>
            @if ($rows->first()->school->address)<div class="fs-12 text-muted">{{ $rows->first()->school->address }}, {{ $rows->first()->school->place() }}</div>@endif</div>
            <div class="table-responsive"><table class="table align-middle mb-0"><tbody>
                @foreach ($rows as $p)
                    <tr><td>{{ $p->student->full_name }}<div class="fs-12 text-muted">{{ $p->student->matric_number }}{{ $p->subject ? ' · ' . $p->subject : '' }}</div></td>
                        <td>@for ($v = 1; $v <= $p->exercise->visits_required; $v++)
                                @php $a = $p->assessments->firstWhere('visit_no', $v); @endphp
                                <a href="{{ route('tp.assess', [$p, 'visit' => $v]) }}" class="btn btn-sm {{ $a ? 'btn-soft-success' : 'btn-light' }}">Visit {{ $v }}{{ $a ? ': ' . $a->percent . '%' : '' }}</a>
                            @endfor</td></tr>
                @endforeach
            </tbody></table></div>
        </div>
    @endforeach
    @if ($postings->isEmpty())<div class="alert alert-info">No students have been posted to your schools yet.</div>@endif
</div></div></div>
@endsection
