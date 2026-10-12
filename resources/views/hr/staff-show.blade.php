@extends('layouts.master')

@section('content')
@php
    $L = \App\Support\Lookups::class;
    $money = fn ($v) => '₦' . number_format((float) $v, 2);
    $u = auth()->user();
@endphp
<div class="main-content"><div class="page-content"><div class="container-fluid">
    @include('partials.page-title', ['title' => $p->displayName(), 'icon' => 'ri-id-card-line', 'subtitle' => collect([$p->staff_number, $p->designation, $p->workplace()])->filter()->implode(' · '), 'back' => [route('staff.index'), 'Staff records']])
    @include('academics.partials.alerts')
    @if ($errors->any())<div class="alert alert-danger">{{ $errors->first() }}</div>@endif

    <div class="row">
        <div class="col-lg-5">
            <div class="card"><div class="card-header"><h6 class="card-title mb-0">Employment</h6></div><div class="card-body p-0">
                <table class="table table-sm mb-0"><tbody>
                    <tr><th class="ps-3" style="width:45%">Status</th><td>{{ $L::label('employment_status', $p->employment_status) }}</td></tr>
                    <tr><th class="ps-3">Department / unit</th><td>{{ $p->workplace() ?? '—' }}</td></tr>
                    <tr><th class="ps-3">Category</th><td>{{ $p->category ? $L::label('staff_category', $p->category) : '—' }}</td></tr>
                    <tr><th class="ps-3">Grade</th><td>{{ $p->payGrade() ?? '—' }}</td></tr>
                    <tr><th class="ps-3">Appointment</th><td>{{ $p->appointment_type ? $L::label('appointment_type', $p->appointment_type) : '—' }}</td></tr>
                    <tr><th class="ps-3">First appointment</th><td>{{ $p->first_appointment_date?->format('j M Y') ?? '—' }}@if ($p->first_appointment_date)<span class="text-muted"> · {{ intdiv($p->serviceMonths(), 12) }} yr {{ $p->serviceMonths() % 12 }} mo</span>@endif</td></tr>
                    <tr><th class="ps-3">Confirmed</th><td>{{ $p->confirmation_date?->format('j M Y') ?? '—' }}</td></tr>
                    <tr><th class="ps-3">Date of birth</th><td>{{ $p->user?->date_of_birth?->format('j M Y') ?? '—' }}</td></tr>
                    <tr><th class="ps-3">Retires</th><td>@if ($retirement){{ $retirement->date->format('j M Y') }} <span class="text-muted">({{ $retirement->reason }})</span>@if ($retirement->isPast())<span class="badge bg-danger-subtle text-danger ms-1">Due</span>@endif @else<span class="text-muted">Needs date of birth or first appointment</span>@endif</td></tr>
                    <tr><th class="ps-3">Supervisor</th><td>{{ $p->supervisor?->name ?? '—' }}</td></tr>
                    @if ($p->exit_date)<tr><th class="ps-3">Left</th><td>{{ $p->exit_date->format('j M Y') }} · {{ $p->exit_reason }}</td></tr>@endif
                    <tr><th class="ps-3">Email · phone</th><td>{{ $p->user?->email }}@if ($p->user?->phone_number) · {{ $p->user->phone_number }}@endif</td></tr>
                </tbody></table>
            </div></div>
            @if ($leaveTaken->isNotEmpty())
                <div class="card"><div class="card-header"><h6 class="card-title mb-0">Recent leave</h6></div><div class="card-body p-0"><table class="table table-sm mb-0"><tbody>
                    @foreach ($leaveTaken as $l)<tr><td class="ps-3">{{ $l->type->name }}</td><td>{{ $l->start_date->format('j M Y') }} – {{ $l->end_date->format('j M Y') }}</td><td class="text-end pe-3">{{ $l->days }} day(s)</td></tr>@endforeach
                </tbody></table></div></div>
            @endif
        </div>

        <div class="col-lg-7">
            @if ($seesPay)
                <div class="card"><div class="card-header"><h6 class="card-title mb-0">Pay, pension and tax</h6></div><div class="card-body">
                    <form method="POST" action="{{ route('staff.pay', $p) }}">@csrf @method('PUT')
                        @php $ro = ! $u->can('Edit staff pay details'); @endphp
                        <fieldset @disabled($ro)>
                        <div class="row g-2">
                            <div class="col-md-4"><label class="form-label">Salary structure</label><select name="salary_structure_id" data-lookup="salary-structures" data-lookup-active="1" class="form-select"><option value="">Not on payroll</option>@foreach ($structures as $id => $code)<option value="{{ $id }}" @selected($p->salary_structure_id == $id)>{{ $code }}</option>@endforeach</select></div>
                            <div class="col-md-4"><label class="form-label">Grade level</label><input type="number" name="grade_level" min="1" max="20" value="{{ $p->grade_level }}" class="form-control"></div>
                            <div class="col-md-4"><label class="form-label">Step</label><input type="number" name="step" min="1" max="20" value="{{ $p->step }}" class="form-control"></div>
                            @if ($banks)
                                <div class="col-md-6"><label class="form-label">Bank</label><select name="bank_code" class="form-select"><option value="">Not set</option>@foreach ($banks as $code => $name)<option value="{{ $code }}" @selected($p->bank_code === (string) $code)>{{ $name }}</option>@endforeach</select></div>
                            @else
                                <div class="col-md-4"><label class="form-label">Bank</label><input name="bank_name" value="{{ $p->bank_name }}" class="form-control"></div>
                                <div class="col-md-2"><label class="form-label">Bank code</label><input name="bank_code" value="{{ $p->bank_code }}" class="form-control"></div>
                            @endif
                            <div class="col-md-6"><label class="form-label">Account number</label><input name="bank_account_number" value="{{ $p->bank_account_number }}" class="form-control font-monospace" maxlength="10" inputmode="numeric"></div>
                            <div class="col-md-12"><label class="form-label">Account name</label><input name="bank_account_name" value="{{ $p->bank_account_name }}" class="form-control"></div>
                            <div class="col-md-6"><label class="form-label">Pension fund administrator</label>
                                @if ($pfas)<select name="pfa_code" class="form-select"><option value="">Not set</option>@foreach ($pfas as $code => $name)<option value="{{ $code }}" @selected($p->pfa_code === (string) $code)>{{ $name }}</option>@endforeach</select>
                                @else<input name="pfa_code" value="{{ $p->pfa_code }}" class="form-control"><div class="form-text">Add the PFAs under Reference Lists to choose from a list.</div>@endif</div>
                            <div class="col-md-6"><label class="form-label">RSA PIN</label><input name="rsa_pin" value="{{ $p->rsa_pin }}" class="form-control font-monospace" placeholder="PEN100000000000"></div>
                            <div class="col-md-4"><label class="form-label">Tax ID</label><input name="tax_id" value="{{ $p->tax_id }}" class="form-control"></div>
                            <div class="col-md-4"><label class="form-label">State for tax</label><select name="tax_state" class="form-select"><option value="">Not set</option>@foreach ($states as $code => $name)<option value="{{ $code }}" @selected($p->tax_state === (string) $code)>{{ $name }}</option>@endforeach</select></div>
                            <div class="col-md-4"><label class="form-label">NHF number</label><input name="nhf_number" value="{{ $p->nhf_number }}" class="form-control"></div>
                        </div>
                        @unless ($ro)<button class="btn btn-primary mt-3">Save pay details</button>@endunless
                        </fieldset>
                    </form>
                </div></div>

                <div class="card"><div class="card-header d-flex justify-content-between align-items-center"><h6 class="card-title mb-0">Personal earnings and deductions</h6>
                    @can('Change staff pay')<button class="btn btn-sm btn-soft-primary" data-bs-toggle="collapse" data-bs-target="#new-item">Add</button>@endcan</div>
                    @can('Change staff pay')
                    <div class="collapse border-bottom" id="new-item"><div class="card-body">
                        <form method="POST" action="{{ route('staff.items.store', $p) }}" class="row g-2">@csrf
                            <div class="col-md-3"><select name="kind" class="form-select"><option value="deduction">Deduction</option><option value="earning">Earning</option></select></div>
                            <div class="col-md-5"><input name="name" class="form-control" placeholder="Staff co-operative, car loan …" required></div>
                            <div class="col-md-4"><input name="amount" type="number" step="0.01" min="0.01" class="form-control" placeholder="Amount a month" required></div>
                            <div class="col-md-4"><input name="balance" type="number" step="0.01" min="0" class="form-control" placeholder="Loan balance (optional)"></div>
                            <div class="col-md-4"><input name="start_month" type="month" value="{{ now()->format('Y-m') }}" class="form-control" required></div>
                            <div class="col-md-4"><input name="end_month" type="month" class="form-control" title="Last month (optional)"></div>
                            <div class="col-md-8"><input name="reference" class="form-control" placeholder="Reference (optional)"></div>
                            <div class="col-md-4"><div class="form-check mt-2"><input type="hidden" name="taxable" value="0"><input class="form-check-input" type="checkbox" name="taxable" value="1" id="tx" checked><label class="form-check-label" for="tx">Taxable (earnings)</label></div></div>
                            <div class="col-12"><div class="form-text">A deduction with a balance is a loan: it stops by itself once repaid.</div><button class="btn btn-primary btn-sm mt-1">Add</button></div>
                        </form>
                    </div></div>
                    @endcan
                    <div class="table-responsive"><table class="table table-sm align-middle mb-0">
                        <thead class="table-light"><tr><th class="ps-3">Item</th><th class="text-end">A month</th><th class="text-end">Balance</th><th>Months</th><th></th></tr></thead><tbody>
                        @forelse ($items as $i)
                            <tr class="{{ $i->is_active ? '' : 'text-muted' }}"><td class="ps-3">{{ $i->name }} <span class="badge {{ $i->kind === 'earning' ? 'bg-success-subtle text-success' : 'bg-danger-subtle text-danger' }}">{{ $i->kind }}</span>@if ($i->reference)<div class="fs-12 text-muted">{{ $i->reference }}</div>@endif</td>
                                <td class="text-end">{{ $money($i->amount) }}</td><td class="text-end">{{ $i->isLoan() ? $money($i->balance) : '—' }}</td>
                                <td class="fs-12">{{ $i->start_month }} → {{ $i->end_month ?? 'open' }}@unless ($i->is_active)<div>Stopped</div>@endunless</td>
                                <td class="text-end pe-3">@if ($i->is_active)@can('Change staff pay')<form method="POST" action="{{ route('staff.items.update', $i) }}" data-confirm="Stop {{ $i->name }}?">@csrf @method('PUT')<input type="hidden" name="stop" value="1"><button class="btn btn-sm btn-soft-danger">Stop</button></form>@endcan @endif</td></tr>
                        @empty
                            <tr><td colspan="5" class="text-center text-muted py-3">None.</td></tr>
                        @endforelse
                    </tbody></table></div>
                </div>

                @if ($payslips->isNotEmpty())
                    <div class="card"><div class="card-header"><h6 class="card-title mb-0">Payslips</h6></div><div class="table-responsive"><table class="table table-sm mb-0"><tbody>
                        @foreach ($payslips as $s)<tr><td class="ps-3"><a href="{{ route('payroll.payslips.show', $s) }}">{{ $s->run->label() }}</a></td><td class="text-end">{{ $money($s->gross) }}</td><td class="text-end pe-3 fw-semibold">{{ $money($s->net) }}</td></tr>@endforeach
                    </tbody></table></div></div>
                @endif
            @endif
        </div>
    </div>
</div></div></div>
@endsection
