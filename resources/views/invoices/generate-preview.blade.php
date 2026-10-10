@extends('layouts.master')

@section('content')
<div class="main-content">
    <div class="page-content">
        <div class="container-fluid" style="max-width:900px">
            <div class="page-title-box d-flex justify-content-between align-items-center"><h4 class="mb-0">Generate invoices: preview</h4>
                <a href="{{ route('invoices.index') }}" class="btn btn-sm btn-light"><i class="ri-arrow-left-line me-1"></i>Invoices</a></div>
            @include('academics.partials.alerts')
            <p class="text-muted">{{ $session->name }}{{ $semester ? ' · ' . $semester->name . ' fees' : ' · session fees' }} · {{ $programme?->name ?? 'All programmes' }} · {{ isset($data['level']) ? \App\Support\Levels::label((int) $data['level']) : 'All levels' }}. Nothing has been created yet.</p>
            <div class="row g-3 mb-3">
                <div class="col-sm-3"><div class="card mb-0"><div class="card-body"><div class="text-muted fs-12">Invoices to create</div><div class="fs-3 fw-semibold text-primary">{{ $preview['create'] }}</div></div></div></div>
                <div class="col-sm-3"><div class="card mb-0"><div class="card-body"><div class="text-muted fs-12">Total billed</div><div class="fs-5 fw-semibold">₦{{ number_format($preview['amount'], 2) }}</div></div></div></div>
                <div class="col-sm-3"><div class="card mb-0"><div class="card-body"><div class="text-muted fs-12">Skipped: already invoiced</div><div class="fs-3 fw-semibold">{{ $preview['existing'] }}</div></div></div></div>
                <div class="col-sm-3"><div class="card mb-0"><div class="card-body"><div class="text-muted fs-12">Skipped: no matching bills</div><div class="fs-3 fw-semibold text-warning">{{ $preview['no_bills'] }}</div></div></div></div>
            </div>
            @if ($preview['no_bills'])<div class="alert alert-warning">{{ $preview['no_bills'] }} active student(s) have no school bills that match them (programme, level, nationality, fresh or returning). Add the bills first if they should be invoiced.</div>@endif
            @if ($preview['sample'])
                <div class="card"><div class="card-header"><h6 class="card-title mb-0">First {{ count($preview['sample']) }} of {{ $preview['create'] }}</h6></div>
                    <div class="table-responsive"><table class="table table-sm mb-0"><thead class="table-light"><tr><th>Student</th><th>Matric number</th><th class="text-end">Amount</th></tr></thead><tbody>
                        @foreach ($preview['sample'] as [$name, $matric, $amount])<tr><td>{{ $name }}</td><td>{{ $matric }}</td><td class="text-end">₦{{ number_format($amount, 2) }}</td></tr>@endforeach
                    </tbody></table></div></div>
            @endif
            @if ($preview['create'])
                <form method="POST" action="{{ route('invoices.generate') }}" class="card"><div class="card-body d-flex justify-content-between align-items-center gap-3">
                    @csrf
                    @foreach ($data as $k => $v)<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endforeach
                    <div class="text-muted fs-13">The run is recorded as one operation. While nobody has paid, it can be reversed from the operation history and the invoices are cancelled (kept on record).</div>
                    <button class="btn btn-primary text-nowrap" type="submit">Create {{ $preview['create'] }} invoice(s)</button>
                </div></form>
            @else
                <div class="alert alert-secondary">There is nothing to create.</div>
            @endif
        </div>
    </div>
</div>
@endsection
