@extends('layouts.master')

@section('content')
@php
    $who = ['supervisor' => 'The applicant\'s supervisor', 'hod' => 'Head of the applicant\'s department', 'dean' => 'Dean of the applicant\'s faculty', 'role' => 'Anyone with a role'];
    $rows = ($usesDefault ? $defaultSteps : $steps)->values();
    $days = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];
@endphp
<div class="main-content"><div class="page-content"><div class="container-fluid" style="max-width:1100px">
    @include('partials.page-title', ['title' => 'Leave settings', 'icon' => 'ri-settings-4-line'])
    @include('academics.partials.alerts')
    @if ($errors->any())<div class="alert alert-danger">{{ $errors->first() }}</div>@endif

    <div class="d-flex flex-wrap gap-2 mb-3">
        <a href="{{ route('leave.types.index') }}" class="btn btn-sm btn-light"><i class="ri-suitcase-line me-1"></i>Leave types</a>
        <a href="{{ route('leave.entitlements.index') }}" class="btn btn-sm btn-light"><i class="ri-scales-3-line me-1"></i>Entitlement rules</a>
        <a href="{{ route('leave.holidays.index') }}" class="btn btn-sm btn-light"><i class="ri-calendar-event-line me-1"></i>Holidays</a>
        <a href="{{ route('staff.index') }}" class="btn btn-sm btn-light"><i class="ri-id-card-line me-1"></i>Staff profiles</a>
    </div>

    <div class="row">
        <div class="col-lg-5">
            <div class="card"><div class="card-header"><h6 class="card-title mb-0">Reminders, coverage and working days</h6></div><div class="card-body">
                <form method="POST" action="{{ route('leave.settings.update') }}">@csrf @method('PUT')
                    <div class="mb-3"><label class="form-label fs-13">{{ \App\Services\LeaveService::SETTINGS['coverage_percent'][0] }}</label><input type="number" name="coverage_percent" value="{{ $values['coverage_percent'] }}" class="form-control"></div>
                    <div class="mb-3"><label class="form-label fs-13">{{ \App\Services\LeaveService::SETTINGS['remind_before'][0] }}</label><input type="number" name="remind_before" value="{{ $values['remind_before'] }}" class="form-control"></div>
                    <div class="mb-3"><label class="form-label fs-13">{{ \App\Services\LeaveService::SETTINGS['remind_left'][0] }}</label><input name="remind_left" value="{{ $values['remind_left'] }}" class="form-control" placeholder="7,1"></div>
                    <div class="mb-3"><label class="form-label fs-13">{{ \App\Services\LeaveService::SETTINGS['late_grace'][0] }}</label><input type="number" name="late_grace" value="{{ $values['late_grace'] }}" class="form-control"></div>
                    <div class="mb-3"><label class="form-label fs-13">Days that are not working days</label><div class="d-flex flex-wrap gap-3">
                        @foreach ($days as $n => $d)<div class="form-check"><input type="checkbox" name="weekend[]" value="{{ $n }}" class="form-check-input" id="wd{{ $n }}" @checked(in_array((string) $n, explode(',', $values['weekend']), true))><label class="form-check-label" for="wd{{ $n }}">{{ substr($d, 0, 3) }}</label></div>@endforeach
                    </div></div>
                    <button class="btn btn-primary">Save</button>
                </form>
                <div class="text-muted fs-12 mt-3">Reminders and escalations are sent by the daily leave check. On the server it runs from the scheduler (<code>php artisan schedule:run</code> every minute from cron).</div>
            </div></div>
        </div>
        <div class="col-lg-7">
            <div class="card"><div class="card-header"><h6 class="card-title mb-1">Approval route</h6><div class="text-muted fs-12">Who decides, in order. A step with nobody to decide it (for example no HOD on record) is skipped when allowed; if nobody can be found at all, leave administrators decide.</div></div>
                <div class="card-body">
                    <form method="GET" class="mb-3"><select name="type" class="form-select" onchange="this.form.submit()">
                        <option value="">Default route (all leave types without their own)</option>
                        @foreach ($types as $t)<option value="{{ $t->id }}" @selected($type?->id === $t->id)>{{ $t->name }}{{ $customised->contains($t->id) ? ' · own route' : '' }}</option>@endforeach
                    </select></form>
                    @if ($usesDefault)<div class="alert alert-light fs-13 py-2">{{ $type->name }} follows the default route. Change the steps below and save to give it its own.</div>@endif
                    <form method="POST" action="{{ route('leave.routes.update') }}">@csrf @method('PUT')
                        <input type="hidden" name="leave_type_id" value="{{ $type?->id }}">
                        <div class="table-responsive"><table class="table table-sm align-middle">
                            <thead><tr><th>#</th><th>Step name</th><th>Who decides</th><th>Role</th><th>Days to decide</th><th>Skip if nobody</th></tr></thead>
                            <tbody>
                            @for ($i = 0; $i < max(5, $rows->count() + 2); $i++)
                                @php $s = $rows[$i] ?? null; @endphp
                                <tr><td>{{ $i + 1 }}</td>
                                    <td><input name="steps[{{ $i }}][label]" value="{{ $s?->label }}" class="form-control form-control-sm" placeholder="{{ $i === 0 ? 'Head of department' : '' }}"></td>
                                    <td><select name="steps[{{ $i }}][approver]" class="form-select form-select-sm"><option value=""></option>@foreach ($who as $k => $l)<option value="{{ $k }}" @selected($s?->approver === $k)>{{ $l }}</option>@endforeach</select></td>
                                    <td><select name="steps[{{ $i }}][role_name]" class="form-select form-select-sm"><option value=""></option>@foreach ($roles as $r)<option @selected($s?->role_name === $r)>{{ $r }}</option>@endforeach</select></td>
                                    <td><input type="number" name="steps[{{ $i }}][due_days]" value="{{ $s?->due_days ?? 3 }}" class="form-control form-control-sm" style="width:70px"></td>
                                    <td class="text-center"><input type="checkbox" name="steps[{{ $i }}][can_skip_if_missing]" value="1" class="form-check-input" @checked($s?->can_skip_if_missing ?? true)></td></tr>
                            @endfor
                            </tbody></table></div>
                        <div class="text-muted fs-12 mb-2">Leave a row's name empty to remove it. @if ($type && ! $usesDefault)Clear every row to send {{ $type->name }} back to the default route.@endif The roles "HR Officer" and "Registrar" are used by the starting route; create them under Roles and give them to the right people.</div>
                        <button class="btn btn-primary">Save route</button>
                    </form>
                </div></div>
        </div>
    </div>
</div></div></div>
@endsection
