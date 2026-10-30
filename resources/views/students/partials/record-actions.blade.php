{{-- Transfer to another programme and defer to a later session (the old portal's ⇄ and Deferred screens). --}}
@php $u = auth()->user(); @endphp
@if ($u->can('Change student programmes'))
    <div class="card">
        <div class="card-header"><h6 class="card-title mb-0">Transfer to another programme</h6></div>
        <form method="POST" action="{{ route('students.transfer', $student->id) }}" class="card-body row g-2">@csrf
            <div class="col-12"><select name="programme_id" class="form-select" required>
                @foreach ($programmes as $p)<option value="{{ $p->id }}" @selected($p->id === $student->programme_id)>{{ $p->name }} ({{ $p->code }})</option>@endforeach
            </select></div>
            <div class="col-6"><select name="level" class="form-select" data-no-search>@foreach (\App\Support\Levels::options() as $lv => $ll)<option value="{{ $lv }}" @selected($lv === (int) $student->current_level)>{{ $ll }}</option>@endforeach</select></div>
            <div class="col-6"><select name="course_option_id" class="form-select" data-lookup="course-options" data-lookup-parent="programme_id"><option value="">No option</option></select></div>
            <div class="col-12"><textarea name="reason" rows="2" class="form-control" placeholder="Reason (kept on the record)" required minlength="5"></textarea></div>
            <div class="col-12"><button class="btn btn-soft-primary w-100" onclick="return confirm('Move this student? Their course registrations and results stay as they are.')"><i class="ri-arrow-left-right-line me-1"></i>Transfer</button></div>
        </form>
    </div>
@endif
@if ($u->can('Manage deferred admissions') && $student->status !== 'deferred')
    <div class="card">
        <div class="card-header"><h6 class="card-title mb-0">Defer to a later session</h6></div>
        <form method="POST" action="{{ route('students.defer', $student->id) }}" class="card-body row g-2">@csrf
            <div class="col-12"><select name="to_session_id" class="form-select" required><option value="">Returns in…</option>@foreach ($sessions as $sid => $sname)<option value="{{ $sid }}">{{ $sname }}</option>@endforeach</select></div>
            <div class="col-12"><textarea name="reason" rows="2" class="form-control" placeholder="Reason" required minlength="5"></textarea></div>
            <div class="col-12"><button class="btn btn-soft-info w-100"><i class="ri-time-line me-1"></i>Defer</button></div>
        </form>
    </div>
@endif
