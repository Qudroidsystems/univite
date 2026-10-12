@extends('layouts.master')

@section('content')
@php $bandRows = old('bands', collect($bands)->map(fn ($b) => ['upto' => $b->upto, 'rate' => $b->rate])->all()); $bandRows = array_pad($bandRows, max(count($bandRows) + 2, 8), ['upto' => null, 'rate' => null]); @endphp
<div class="main-content"><div class="page-content"><div class="container-fluid">
    @include('partials.page-title', ['title' => 'Payroll settings', 'icon' => 'ri-settings-4-line'])
    @include('academics.partials.alerts')
    @if ($errors->any())<div class="alert alert-danger">{{ $errors->first() }}</div>@endif
    <form method="POST" action="{{ route('payroll.settings.update') }}">@csrf @method('PUT')
    <fieldset @disabled(! auth()->user()->can('Set payroll policy'))>
    <div class="row">
        <div class="col-lg-6">
            <div class="card"><div class="card-header"><h6 class="card-title mb-0">Income tax (PAYE) bands</h6></div><div class="card-body">
                <div class="alert alert-warning fs-13">These start from the bands in the Nigeria Tax Act 2025. Check them against the current law and your state's revenue service before the first payroll. Tax is worked out on the yearly taxable pay (after pension and NHF) and divided by twelve.</div>
                <table class="table table-sm align-middle"><thead><tr><th>#</th><th>Annual income up to (₦)</th><th>Rate %</th></tr></thead><tbody>
                    @foreach ($bandRows as $i => $b)
                        <tr><td class="text-muted">{{ $i + 1 }}</td><td><input name="bands[{{ $i }}][upto]" value="{{ $b['upto'] }}" type="number" step="0.01" min="0" class="form-control form-control-sm" placeholder="{{ $i === 0 ? '' : 'blank for the top band' }}"></td>
                            <td><input name="bands[{{ $i }}][rate]" value="{{ $b['rate'] }}" type="number" step="0.01" min="0" max="100" class="form-control form-control-sm"></td></tr>
                    @endforeach
                </tbody></table>
                <div class="form-text">Each band covers income from the end of the band above it up to its own limit. Leave "up to" blank on the top band only. Rows without a rate are ignored.</div>
                <details class="mt-2"><summary class="fs-13">Default bands</summary><ul class="fs-13 mb-0">@foreach ($defaults as $b)<li>{{ $b['upto'] === null ? 'Above' : 'Up to ₦' . number_format($b['upto']) }}: {{ $b['rate'] }}%</li>@endforeach</ul></details>
            </div></div>
        </div>
        <div class="col-lg-6">
            <div class="card"><div class="card-header"><h6 class="card-title mb-0">Who is paid</h6></div><div class="card-body">
                <p class="text-muted fs-13">Staff with these employment statuses are included in the payroll. Unpaid leave is taken off automatically.</p>
                @foreach ($statuses as $code => $label)
                    <div class="form-check"><input class="form-check-input" type="checkbox" name="paid[]" value="{{ $code }}" id="ps-{{ $code }}" @checked(in_array($code, old('paid', $paid), true))><label class="form-check-label" for="ps-{{ $code }}">{{ $label }}</label></div>
                @endforeach
                <hr>
                <div class="form-check form-switch"><input type="hidden" name="email_payslips" value="0"><input class="form-check-input" type="checkbox" name="email_payslips" value="1" id="ep" @checked(old('email_payslips', $email))><label class="form-check-label" for="ep">Email each payslip when the payroll is approved</label></div>
                <div class="form-text">Staff are always told in the portal; this also sends the payslip to their email.</div>
            </div></div>
            <div class="card"><div class="card-header"><h6 class="card-title mb-0">Retirement</h6></div><div class="card-body row g-2">
                <div class="col-md-4"><label class="form-label">Retirement age</label><input type="number" name="retirement_age" value="{{ old('retirement_age', $retirement['retirement_age']) }}" min="50" max="80" class="form-control"></div>
                <div class="col-md-4"><label class="form-label">Professorial cadre</label><input type="number" name="retirement_age_professorial" value="{{ old('retirement_age_professorial', $retirement['retirement_age_professorial']) }}" min="50" max="80" class="form-control"></div>
                <div class="col-md-4"><label class="form-label">Most years of service</label><input type="number" name="max_service_years" value="{{ old('max_service_years', $retirement['max_service_years']) }}" min="0" max="50" class="form-control"><div class="form-text">0 for no limit.</div></div>
            </div></div>
        </div>
    </div>
    @can('Set payroll policy')<button class="btn btn-primary">Save settings</button>@endcan
    </fieldset>
    </form>
</div></div></div>
@endsection
