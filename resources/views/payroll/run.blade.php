@extends('layouts.master')

@section('content')
@php $money = fn ($v) => '₦' . number_format((float) $v, 2); $u = auth()->user(); @endphp
<div class="main-content"><div class="page-content"><div class="container-fluid">
    @include('partials.page-title', ['title' => 'Payroll for ' . $run->label(), 'icon' => 'ri-money-dollar-box-line', 'back' => [route('payroll.runs.index'), 'Payroll'],
        'subtitle' => 'Prepared by ' . ($run->creator?->name ?? '—') . ' on ' . $run->updated_at->format('j M Y, H:i') . ($run->approved_at ? ' · approved by ' . ($run->approver?->name ?? '—') . ' on ' . $run->approved_at->format('j M Y') : '') . ($run->paid_at ? ' · paid ' . $run->paid_at->format('j M Y') : '')])
    @include('academics.partials.alerts')

    @if ($changed)<div class="alert alert-warning"><i class="ri-alert-line me-1"></i>{{ ucfirst($changed) }} changed after this payroll was prepared. Prepare it again before approving.</div>@endif
    @if ($run->problems)
        <div class="alert alert-danger"><div class="fw-semibold mb-1">{{ count($run->problems) }} member(s) of staff were left out:</div><ul class="mb-0">@foreach ($run->problems as $p)<li>{{ $p }}</li>@endforeach</ul></div>
    @endif

    <div class="row">
        @foreach ([['Status', ucfirst($run->status)], ['Staff paid', number_format($run->staff_count)], ['Gross', $money($run->gross)], ['Deductions', $money($run->deductions)], ['Net pay', $money($run->net)], ['Cost to the institution', $money($run->employer_cost)]] as [$k, $v])
            <div class="col-6 col-md-4 col-xl-2"><div class="card card-animate"><div class="card-body"><p class="text-muted mb-1 fs-12 text-uppercase">{{ $k }}</p><h5 class="mb-0">{{ $v }}</h5></div></div></div>
        @endforeach
    </div>

    <div class="card"><div class="card-body d-flex flex-wrap gap-2 align-items-center">
        @if ($run->isDraft())
            @can('Prepare payroll')<form method="POST" action="{{ route('payroll.runs.store') }}">@csrf<input type="hidden" name="period" value="{{ $run->period }}"><button class="btn btn-soft-primary"><i class="ri-refresh-line me-1"></i>Prepare again</button></form>@endcan
            @can('Approve payroll')<form method="POST" action="{{ route('payroll.runs.approve', $run) }}" data-confirm="Approve the payroll for {{ $run->label() }}? Payslips are frozen, loan balances go down, and staff are told their payslips are ready.">@csrf<button class="btn btn-success" @disabled($changed || ! $run->staff_count)><i class="ri-check-double-line me-1"></i>Approve</button></form>@endcan
            @can('Prepare payroll')<form method="POST" action="{{ route('payroll.runs.destroy', $run) }}" data-confirm="Delete this draft payroll?">@csrf @method('DELETE')<button class="btn btn-soft-danger"><i class="ri-delete-bin-line me-1"></i>Delete draft</button></form>@endcan
        @elseif ($run->status === 'approved')
            @can('Mark payroll paid')<form method="POST" action="{{ route('payroll.runs.paid', $run) }}" data-confirm="Mark {{ $run->label() }} as paid by the bank? It can no longer be reopened after this.">@csrf<button class="btn btn-success"><i class="ri-bank-line me-1"></i>Mark paid</button></form>@endcan
            @if ($origin && $u->can('Approve payroll'))<a href="{{ route('operations.reverse', $origin) }}" class="btn btn-soft-warning"><i class="ri-arrow-go-back-line me-1"></i>Reopen as draft</a>@endif
        @endif
        <div class="ms-auto d-flex flex-wrap gap-2">
            <a href="{{ route('payroll.runs.schedule', [$run, 'bank']) }}" class="btn btn-light btn-sm"><i class="ri-bank-line me-1"></i>Bank schedule</a>
            <a href="{{ route('payroll.runs.schedule', [$run, 'tax']) }}" class="btn btn-light btn-sm"><i class="ri-government-line me-1"></i>Tax schedule</a>
            <div class="dropdown"><button class="btn btn-light btn-sm dropdown-toggle" data-bs-toggle="dropdown">Pension schedule</button><ul class="dropdown-menu">
                <li><a class="dropdown-item" href="{{ route('payroll.runs.schedule', [$run, 'pension']) }}">All PFAs</a></li>
                @foreach ($pfas as $code => $name)<li><a class="dropdown-item" href="{{ route('payroll.runs.schedule', [$run, 'pension', 'pfa' => $code]) }}">{{ $name }}</a></li>@endforeach
            </ul></div>
            @if ($deductions)<div class="dropdown"><button class="btn btn-light btn-sm dropdown-toggle" data-bs-toggle="dropdown">Deduction schedule</button><ul class="dropdown-menu dropdown-menu-end">
                @foreach ($deductions as $key => $name)<li><a class="dropdown-item" href="{{ route('payroll.runs.schedule', [$run, 'deduction', 'item' => $key]) }}">{{ $name }}</a></li>@endforeach
            </ul></div>@endif
        </div>
    </div></div>

    <div class="card">
        <div class="card-header"><form method="GET" class="d-flex gap-2 flex-wrap align-items-center">
            <input name="q" value="{{ request('q') }}" class="form-control" style="max-width:280px" placeholder="Name, staff number or department">
            <div class="form-check"><input class="form-check-input" type="checkbox" name="flagged" value="1" id="fl" @checked(request('flagged')) onchange="this.form.submit()"><label class="form-check-label" for="fl">Only payslips with warnings ({{ $flagged }})</label></div>
            <button class="btn btn-light">Search</button></form></div>
        <div class="table-responsive"><table class="table table-sm align-middle mb-0">
            <thead class="table-light"><tr><th class="ps-3">Staff</th><th>Grade</th><th class="text-end">Basic</th><th class="text-end">Gross</th><th class="text-end">Tax</th><th class="text-end">Pension</th><th class="text-end">Deductions</th><th class="text-end">Net</th><th>Bank</th></tr></thead><tbody>
            @forelse ($slips as $s)
                <tr><td class="ps-3"><a href="{{ route('payroll.payslips.show', $s) }}" class="fw-semibold">{{ $s->name }}</a><div class="fs-12 text-muted">{{ $s->staff_number }} · {{ $s->department }}</div>
                    @foreach ($s->notes ?? [] as $n)<div class="fs-12 text-warning"><i class="ri-error-warning-line"></i> {{ $n }}</div>@endforeach</td>
                    <td class="fs-12">{{ $s->structure }} {{ $s->grade_level }}/{{ $s->step }}@if ($s->factor < 1)<div class="text-muted">{{ round($s->factor * 100, 1) }}%</div>@endif</td>
                    <td class="text-end">{{ $money($s->basic) }}</td><td class="text-end">{{ $money($s->gross) }}</td><td class="text-end">{{ $money($s->tax) }}</td>
                    <td class="text-end">{{ $money($s->pension_employee) }}</td><td class="text-end">{{ $money($s->total_deductions) }}</td><td class="text-end fw-semibold">{{ $money($s->net) }}</td>
                    <td class="fs-12">{{ $s->bank_name }}@if ($s->bank_account_number)<div class="font-monospace">{{ $s->bank_account_number }}</div>@endif</td></tr>
            @empty
                <tr><td colspan="9" class="text-center text-muted py-4">No payslips.</td></tr>
            @endforelse
        </tbody></table></div>
        @if ($slips->hasPages())<div class="card-footer">{{ $slips->links() }}</div>@endif
    </div>
</div></div></div>
@endsection
