@extends('layouts.master')

@section('content')
@php $canGenerate = auth()->user()->can('Generate invoices'); @endphp
<div class="main-content">
    <div class="page-content">
        <div class="container-fluid">

            <div class="row">
                <div class="col-12">
                    <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                        <h4 class="mb-sm-0"><i class="ri-wallet-3-line me-1 align-middle"></i>Invoices &amp; Payments</h4>
                        <div class="page-title-right">
                            <ol class="breadcrumb m-0">
                                <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Dashboard</a></li>
                                <li class="breadcrumb-item active">Invoices</li>
                            </ol>
                        </div>
                    </div>
                </div>
            </div>

            @include('academics.partials.alerts')

            <p class="text-muted">Each student's fees for a session are built from the school bills for their programme and level. Generate invoices for a session, then record payments as the bursary receives them.</p>

            <div class="row">
                <div class="col-md-4"><div class="card"><div class="card-body"><p class="text-uppercase fw-medium text-muted fs-12 mb-2">Billed</p><h4 class="mb-0">₦{{ number_format($billed, 2) }}</h4></div></div></div>
                <div class="col-md-4"><div class="card"><div class="card-body"><p class="text-uppercase fw-medium text-muted fs-12 mb-2">Paid</p><h4 class="mb-0 text-success">₦{{ number_format($paid, 2) }}</h4></div></div></div>
                <div class="col-md-4"><div class="card"><div class="card-body"><p class="text-uppercase fw-medium text-muted fs-12 mb-2">Outstanding</p><h4 class="mb-0 text-danger">₦{{ number_format($billed - $paid, 2) }}</h4></div></div></div>
            </div>

            <div class="card">
                <div class="card-header d-flex flex-wrap gap-2 align-items-center justify-content-between">
                    <form method="GET" class="d-flex flex-wrap gap-2 align-items-center">
                        <input type="search" name="q" value="{{ request('q') }}" class="form-control" style="width:240px" placeholder="Reference, matric number or name">
                        <select name="academic_session_id" class="form-select" style="width:auto" onchange="this.form.submit()">
                            <option value="">All sessions</option>
                            @foreach ($sessions as $id => $name)<option value="{{ $id }}" @selected((string) request('academic_session_id') === (string) $id)>{{ $name }}</option>@endforeach
                        </select>
                        <select name="status" class="form-select" style="width:auto" onchange="this.form.submit()">
                            <option value="">All statuses</option>
                            @foreach (\App\Models\Invoice::STATUSES as $v => $l)<option value="{{ $v }}" @selected(request('status') === $v)>{{ $l }}</option>@endforeach
                        </select>
                        <button class="btn btn-soft-primary" type="submit"><i class="ri-search-line"></i></button>
                        @if (request()->query())<a href="{{ url()->current() }}" class="btn btn-light">Clear</a>@endif
                    </form>
                    @if ($canGenerate)
                        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#generate-modal"><i class="ri-magic-line me-1"></i>Generate invoices</button>
                    @endif
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light"><tr><th>Reference</th><th>Student</th><th>Session</th><th class="text-end">Total</th><th class="text-end">Paid</th><th class="text-end">Balance</th><th>Status</th></tr></thead>
                            <tbody>
                                @forelse ($invoices as $invoice)
                                    <tr>
                                        <td><a href="{{ route('invoices.show', $invoice->id) }}" class="fw-semibold">{{ $invoice->reference }}</a></td>
                                        <td>{{ $invoice->payable?->full_name }}<div class="text-muted fs-12">{{ $invoice->payable?->identifier }}</div></td>
                                        <td>{{ $invoice->session?->name ?? '—' }}</td>
                                        <td class="text-end">₦{{ number_format((float) $invoice->total, 2) }}</td>
                                        <td class="text-end">₦{{ number_format((float) $invoice->amount_paid, 2) }}</td>
                                        <td class="text-end fw-semibold">₦{{ number_format($invoice->outstanding, 2) }}</td>
                                        <td><span class="badge {{ \App\Models\Invoice::BADGES[$invoice->status] }}">{{ \App\Models\Invoice::STATUSES[$invoice->status] }}</span></td>
                                    </tr>
                                @empty
                                    <tr><td colspan="7" class="text-center text-muted py-5"><i class="ri-wallet-3-line fs-1 d-block mb-2"></i>No invoices found.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
                @if ($invoices->hasPages())<div class="card-footer">{{ $invoices->links() }}</div>@endif
            </div>
        </div>
    </div>
</div>

@if ($canGenerate)
<div class="modal fade" id="generate-modal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="GET" action="{{ route('invoices.generate.preview') }}" class="modal-content">
            <div class="modal-header bg-light"><h5 class="modal-title">Generate invoices</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <p class="text-muted">Creates an invoice for every <strong>active</strong> student who does not have one yet for the session. Amounts come from the school bills for each student's programme and level; students who entered in that session are billed as fresh, the rest as returning.</p>
                <div class="mb-3">
                    <label class="form-label">Session <span class="text-danger">*</span></label>
                    <select name="academic_session_id" class="form-select" required>
                        @foreach ($sessions as $id => $name)<option value="{{ $id }}" @selected(optional(\App\Models\AcademicSession::current())->id === $id)>{{ $name }}</option>@endforeach
                    </select>
                </div>
                <div class="row g-3">
                    <div class="col-7"><label class="form-label">Programme (optional)</label>
                        <select name="programme_id" class="form-select"><option value="">All programmes</option>@foreach ($programmes as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select></div>
                    <div class="col-5"><label class="form-label">Level (optional)</label>
                        <select name="level" class="form-select"><option value="">All levels</option>@foreach (\App\Support\Levels::options() as $v => $l)<option value="{{ $v }}">{{ $l }}</option>@endforeach</select></div>
                </div>
                <div class="mt-3"><label class="form-label">Bill for (optional)</label>
                    <select name="semester_type_id" class="form-select"><option value="">The whole session (session fees)</option>@foreach (\App\Models\SemesterType::options() as $v => $n)<option value="{{ $v }}">{{ $n }} fees only</option>@endforeach</select>
                    <div class="form-text">Choose a semester to invoice the fees you set for that semester alone.</div></div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary">Preview</button></div>
        </form>
    </div>
</div>
@endif
@endsection
