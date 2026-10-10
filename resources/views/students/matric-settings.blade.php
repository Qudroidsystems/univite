@extends('layouts.master')

@section('content')
@php $canSet = auth()->user()->can('Set the matric number format'); @endphp
<div class="main-content">
    <div class="page-content">
        <div class="container-fluid">

            <div class="row">
                <div class="col-12">
                    <div class="page-title-box d-sm-flex align-items-center justify-content-between">
                        <h4 class="mb-sm-0"><i class="ri-hashtag me-1 align-middle"></i>Matric Number Format</h4>
                        <div class="page-title-right">
                            <ol class="breadcrumb m-0">
                                <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Dashboard</a></li>
                                <li class="breadcrumb-item active">Matric Number Format</li>
                            </ol>
                        </div>
                    </div>
                </div>
            </div>

            @include('academics.partials.alerts')

            <div class="row">
                <div class="col-lg-7">
                    <div class="card">
                        <div class="card-body">
                            <p class="text-muted">The format is used every time an applicant is admitted. The running number restarts for each different prefix, so with the default format every programme starts at 0001 in each entry year. Existing matric numbers never change.</p>

                            <form method="POST" action="{{ route('students.settings.update') }}" id="matric-form">
                                @csrf @method('PUT')
                                <label class="form-label" for="pattern">Format</label>
                                <input type="text" name="pattern" id="pattern" value="{{ old('pattern', $pattern) }}" maxlength="60"
                                       class="form-control form-control-lg font-monospace @if ($errors->has('pattern') || $error) is-invalid @endif" @readonly(! $canSet)>
                                @error('pattern') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                                @if ($error && ! $errors->has('pattern')) <div class="invalid-feedback d-block">{{ $error }}</div> @endif

                                <div class="mt-3 p-3 rounded bg-light">
                                    <div class="text-muted fs-12 mb-1">Next matric number @if ($sample) for {{ $sample }}@endif</div>
                                    @if ($preview)
                                        <div class="fs-4 fw-semibold font-monospace">{{ $preview }}</div>
                                    @else
                                        <div class="text-muted">{{ $sample ? 'Fix the format to see an example.' : 'Add an active programme to see an example.' }}</div>
                                    @endif
                                </div>

                                <div class="mt-3 d-flex gap-2">
                                    <button type="submit" formmethod="get" formaction="{{ route('students.settings') }}" class="btn btn-light">Preview</button>
                                    @if ($canSet)
                                        <button type="submit" class="btn btn-primary"><i class="ri-save-line me-1"></i>Save format</button>
                                    @endif
                                </div>
                                @if ($pattern !== $saved)
                                    <div class="form-text">Previewing only. Currently saved: <code>{{ $saved }}</code></div>
                                @endif
                            </form>
                        </div>
                    </div>
                </div>

                <div class="col-12 order-last">
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                            <div><h6 class="card-title mb-0">Running numbers</h6><div class="text-muted fs-12">The last number used for each prefix is detected from numbers already issued, reserved, cancelled or superseded. If numbers were issued before the portal, set the last one here and the portal carries on after it. It cannot be set below a number in use, so duplicates are impossible.</div></div>
                            <form method="GET" class="d-flex gap-2 align-items-center"><label class="text-muted fs-12 text-nowrap">Entry session</label><select name="seq_session" class="form-select form-select-sm" onchange="this.form.submit()">@foreach ($sessions as $id => $n)<option value="{{ $id }}" @selected($seqSession?->id === $id)>{{ $n }}</option>@endforeach</select></form>
                        </div>
                        <div class="table-responsive"><table class="table table-sm align-middle mb-0">
                            <thead class="table-light"><tr><th>Prefix</th><th>Programmes</th><th>Last used</th><th>Next number</th>@if ($canSet)<th style="width:30%">Set last number used</th>@endif</tr></thead>
                            <tbody>
                            @forelse ($sequences as $q)
                                <tr><td><code>{{ str_replace('{SEQ}', '…', $q['prefix']) }}</code></td><td class="fs-13">{{ implode(', ', $q['programmes']) }}</td><td>{{ $q['last'] }}</td><td><code>{{ $q['next'] }}</code></td>
                                    @if ($canSet)<td><form method="POST" action="{{ route('students.settings.sequence') }}" class="d-flex gap-1">@csrf @method('PUT')
                                        <input type="hidden" name="prefix" value="{{ $q['prefix'] }}"><input type="hidden" name="seq_session" value="{{ $seqSession?->id }}">
                                        <input type="number" name="last" min="{{ $q['highest'] }}" value="{{ $q['last'] }}" class="form-control form-control-sm" required><button class="btn btn-sm btn-soft-primary">Set</button></form></td>@endif</tr>
                            @empty
                                <tr><td colspan="5" class="text-center text-muted py-3">Add an active programme and a session to see the running numbers.</td></tr>
                            @endforelse
                            </tbody>
                        </table></div>
                    </div>
                </div>

                <div class="col-lg-5">
                    <div class="card">
                        <div class="card-header"><h6 class="card-title mb-0">When is the number issued?</h6></div>
                        <div class="card-body">
                            <form method="POST" action="{{ route('students.settings.issue') }}">
                                @csrf @method('PUT')
                                <div class="form-check mb-2"><input class="form-check-input" type="radio" name="issue_at" id="at-clearance" value="clearance" @checked($issueAt === 'clearance') @disabled(! $canSet)><label class="form-check-label" for="at-clearance"><strong>After clearance</strong> (recommended)<div class="text-muted fs-12">The student gets a login with their application number first. The matric number comes once every required clearance item is cleared.</div></label></div>
                                <div class="form-check mb-3"><input class="form-check-input" type="radio" name="issue_at" id="at-admission" value="admission" @checked($issueAt === 'admission') @disabled(! $canSet)><label class="form-check-label" for="at-admission"><strong>At admission</strong><div class="text-muted fs-12">The number is issued the moment the applicant is admitted.</div></label></div>
                                <div class="form-check form-switch mb-3"><input type="hidden" name="auto_issue" value="0"><input class="form-check-input" type="checkbox" role="switch" name="auto_issue" id="auto-issue" value="1" @checked($autoIssue) @disabled(! $canSet)><label class="form-check-label" for="auto-issue">Issue automatically when a student is cleared<div class="text-muted fs-12">Turn off if staff should generate numbers in a batch instead.</div></label></div>
                                @if ($canSet)<button class="btn btn-primary" type="submit"><i class="ri-save-line me-1"></i>Save</button>@endif
                            </form>
                        </div>
                    </div>
                    <div class="card">
                        <div class="card-header"><h6 class="card-title mb-0">Approval before publishing</h6></div>
                        <form method="POST" action="{{ route('students.settings.approval') }}" class="card-body">
                            @csrf @method('PUT')
                            <div class="form-check form-switch mb-2"><input type="hidden" name="require_approval" value="0"><input class="form-check-input" type="checkbox" role="switch" name="require_approval" id="req-approval" value="1" @checked($approval) @disabled(! $canSet)>
                                <label class="form-check-label" for="req-approval">Numbers need approval before students see them<div class="text-muted fs-12">Generated numbers (by staff or automatically) are reserved, so nobody else can get them, and are only published when someone with "Approve matric numbers" approves the batch, for example after Senate or the admissions committee.</div></label></div>
                            <div class="form-check form-switch mb-3"><input type="hidden" name="separate_approver" value="0"><input class="form-check-input" type="checkbox" role="switch" name="separate_approver" id="sep-approver" value="1" @checked($separate) @disabled(! $canSet)>
                                <label class="form-check-label" for="sep-approver">Someone else must approve<div class="text-muted fs-12">The person who generated a batch cannot approve it.</div></label></div>
                            @if ($canSet)<button class="btn btn-primary" type="submit"><i class="ri-save-line me-1"></i>Save</button>@endif
                            <a href="{{ route('matric.approvals.index') }}" class="btn btn-link">Batches awaiting approval</a>
                        </form>
                    </div>
                    <div class="card">
                        <div class="card-header"><h6 class="card-title mb-0">Parts you can use</h6></div>
                        <ul class="list-group list-group-flush">
                            @foreach ($tokens as $token => $meaning)
                                <li class="list-group-item">
                                    <code>{{ '{' . $token . '}' }}</code>
                                    <div class="text-muted fs-12">{{ $meaning }}</div>
                                </li>
                            @endforeach
                        </ul>
                        <div class="card-footer text-muted fs-12">
                            Anything else you type (slashes, dashes, letters) is kept as written. Examples: <code>{YEAR}/{PROGRAMME_CODE}/{SEQ:4}</code> · <code>{DEPARTMENT_CODE}/{SHORT_YEAR}/{SEQ:3}</code>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
