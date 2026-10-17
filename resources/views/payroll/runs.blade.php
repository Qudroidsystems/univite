@extends('layouts.master')

@section('content')
@php $money = fn ($v) => '₦' . number_format((float) $v, 2); @endphp
<div class="main-content"><div class="page-content"><div class="container-fluid">
    @include('partials.page-title', ['title' => 'Payroll', 'icon' => 'ri-money-dollar-box-line', 'subtitle' => 'Prepare a month, check it, approve it (staff are then told their payslips are ready), and mark it paid once the bank has paid.'])
    @include('academics.partials.alerts')
    @can('Prepare payroll')
        <div class="card"><div class="card-body">
            <form method="POST" action="{{ route('payroll.runs.store') }}" class="d-flex gap-2 align-items-end flex-wrap">@csrf
                <div><label class="form-label">Month</label><input type="month" name="period" value="{{ $next }}" class="form-control" required></div>
                <button class="btn btn-primary"><i class="ri-calculator-line me-1"></i>Prepare payroll</button>
                <span class="text-muted fs-13">Preparing a draft month again rebuilds every payslip from the current records.</span>
            </form>
        </div></div>
    @endcan
    <div class="card"><div class="table-responsive"><table class="table align-middle mb-0">
        <thead class="table-light"><tr><th>Month</th><th>Status</th><th class="text-end">Staff</th><th class="text-end">Gross</th><th class="text-end">Deductions</th><th class="text-end">Net pay</th><th class="text-end">Cost to the institution</th><th>Approved</th><th style="width:1%" class="text-nowrap">Last changed by</th></tr></thead><tbody>
        @forelse ($runs as $r)
            <tr><td><a href="{{ route('payroll.runs.show', $r) }}" class="fw-semibold">{{ $r->label() }}</a>@if ($r->problems)<i class="ri-error-warning-line text-warning ms-1" title="{{ count($r->problems) }} member(s) of staff left out"></i>@endif</td>
                <td><span class="badge {{ \App\Models\PayrollRun::BADGES[$r->status] }}">{{ ucfirst($r->status) }}</span></td>
                <td class="text-end">{{ number_format($r->staff_count) }}</td><td class="text-end">{{ $money($r->gross) }}</td><td class="text-end">{{ $money($r->deductions) }}</td>
                <td class="text-end fw-semibold">{{ $money($r->net) }}</td><td class="text-end">{{ $money($r->employer_cost) }}</td>
                <td class="fs-12">{{ $r->approver?->name }}@if ($r->approved_at)<div class="text-muted">{{ $r->approved_at->format('j M Y') }}</div>@endif</td>
                <td><x-last-change :record="$r" :among="$runs" /></td></tr>
        @empty
            <tr><td colspan="9" class="text-center text-muted py-5">No payroll yet. Set up the salary scales and each member of staff's grade and step first.</td></tr>
        @endforelse
    </tbody></table></div>
    @if ($runs->hasPages())<div class="card-footer">{{ $runs->links() }}</div>@endif</div>
</div></div></div>
@endsection
