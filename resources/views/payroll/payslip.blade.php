@extends('layouts.master')

@section('content')
@php
    $money = fn ($v) => number_format((float) $v, 2);
    $lines = $slip->lines->sortBy('sort_order');
    $account = $slip->bank_account_number ? ($mine ? $slip->bank_account_number : '******' . substr($slip->bank_account_number, -4)) : null;
@endphp
<div class="main-content"><div class="page-content"><div class="container-fluid" style="max-width:820px">
    <div class="d-flex justify-content-between align-items-center mb-3 d-print-none">
        <h4 class="mb-0">Payslip · {{ $slip->run->label() }}</h4>
        <div class="d-flex gap-2">
            <a href="{{ $mine ? route('my.payslips') : route('payroll.runs.show', $slip->run) }}" class="btn btn-light"><i class="ri-arrow-left-line me-1"></i>Back</a>
            <button class="btn btn-primary" onclick="window.print()"><i class="ri-printer-line me-1"></i>Print</button>
        </div>
    </div>
    @if ($slip->run->isDraft())<div class="alert alert-warning d-print-none">Draft: this payroll has not been approved, so these figures may still change.</div>@endif
    <div class="card"><div class="card-body">
        @include('partials.letterhead', ['subtitle' => 'Payslip for ' . $slip->run->label()])
        <table class="table table-sm mb-3"><tbody>
            <tr><th style="width:22%">Name</th><td>{{ $slip->name }}</td><th style="width:22%">Staff number</th><td>{{ $slip->staff_number ?? '—' }}</td></tr>
            <tr><th>Department / unit</th><td>{{ $slip->department ?? '—' }}</td><th>Designation</th><td>{{ $slip->designation ?? '—' }}</td></tr>
            <tr><th>Grade</th><td>{{ $slip->structure }} {{ $slip->grade_level }} step {{ $slip->step }}</td><th>Bank</th><td>{{ $slip->bank_name ?? '—' }} {{ $account }}</td></tr>
            <tr><th>PFA · RSA PIN</th><td>{{ $slip->pfa_code ? \App\Support\Lookups::label('pfa', $slip->pfa_code) : '—' }}{{ $slip->rsa_pin ? ' · ' . $slip->rsa_pin : '' }}</td><th>Tax ID</th><td>{{ $slip->tax_id ?? '—' }}</td></tr>
        </tbody></table>
        <div class="row">
            @foreach (['earning' => 'Earnings', 'deduction' => 'Deductions'] as $kind => $head)
                <div class="col-md-6"><table class="table table-sm table-bordered">
                    <thead class="table-light"><tr><th>{{ $head }}</th><th class="text-end">₦</th></tr></thead><tbody>
                    @forelse ($lines->where('kind', $kind) as $l)<tr><td>{{ $l->name }}</td><td class="text-end">{{ $money($l->amount) }}</td></tr>@empty<tr><td colspan="2" class="text-muted">None</td></tr>@endforelse
                    </tbody><tfoot><tr class="fw-semibold"><td>Total</td><td class="text-end">{{ $money($kind === 'earning' ? $slip->gross : $slip->total_deductions) }}</td></tr></tfoot>
                </table></div>
            @endforeach
        </div>
        <div class="d-flex justify-content-between align-items-center border rounded p-3 mb-3 bg-light">
            <span class="fw-semibold">Net pay</span><span class="fs-4 fw-bold">₦{{ $money($slip->net) }}</span>
        </div>
        @if ($lines->where('kind', 'employer')->isNotEmpty())
            <div class="fs-12 text-muted mb-2">Paid by the institution on your behalf (not taken from your pay):
                {{ $lines->where('kind', 'employer')->map(fn ($l) => $l->name . ' ₦' . $money($l->amount))->implode(', ') }}</div>
        @endif
        @if ($slip->factor < 1)<div class="fs-12 text-muted">Paid for {{ round($slip->factor * 100, 1) }}% of the month.</div>@endif
        @if (! $mine && $slip->notes)<div class="alert alert-warning d-print-none mt-2 mb-0">@foreach ($slip->notes as $n)<div>{{ $n }}</div>@endforeach</div>@endif
        @include('partials.signatory', ['role' => 'bursar'])
    </div></div>
</div></div></div>
@endsection
