{{-- Loaded into the "Last changed by" details window. --}}
@php($p = $staff?->staffProfile)
@if ($staff)
    <div class="d-flex align-items-center gap-3 mb-3">
        @if ($staff->photoUrl())
            <img src="{{ $staff->photoUrl() }}" alt="" class="rounded-circle object-fit-cover" style="width:64px;height:64px">
        @else
            <div class="avatar-md"><span class="avatar-title rounded-circle bg-primary-subtle text-primary fs-4">{{ mb_strtoupper(mb_substr($staff->name, 0, 1)) }}</span></div>
        @endif
        <div>
            <h5 class="mb-1">{{ $staff->name }}</h5>
            <div class="text-muted">{{ $p?->designation ?: 'Staff' }}@if ($p?->department) · {{ $p->department->name }}@endif</div>
        </div>
    </div>
    <table class="table table-sm mb-3 uv-no-anim">
        <tr><th class="text-muted fw-normal" style="width:35%">Staff number</th><td>{{ $p?->staff_number ?: '—' }}</td></tr>
        <tr><th class="text-muted fw-normal">Email</th><td>{{ $staff->email ?: '—' }}</td></tr>
        <tr><th class="text-muted fw-normal">Phone</th><td>{{ $staff->phone ?? $p?->phone ?? '—' }}</td></tr>
    </table>
@else
    <p class="text-muted">No one is recorded as having changed this record yet.</p>
@endif

@if ($history->isNotEmpty())
    <h6 class="text-uppercase text-muted fs-12 mb-2">History of this {{ strtolower(Str::headline($type)) }}</h6>
    <ul class="list-unstyled mb-0">
        @foreach ($history as $a)
            <li class="d-flex gap-2 py-2 border-top">
                <i class="{{ ['created' => 'ri-add-circle-line text-success', 'deleted' => 'ri-delete-bin-line text-danger'][$a->action] ?? 'ri-pencil-line text-primary' }} fs-16"></i>
                <div class="flex-grow-1">
                    <div><strong>{{ \App\Models\RecordActivity::ACTIONS[$a->action] ?? $a->action }}</strong> by {{ $a->user?->name ?? 'a deleted account' }}
                        @if ($a->user?->staffProfile?->staff_number)<span class="text-muted">({{ $a->user->staffProfile->staff_number }})</span>@endif
                    </div>
                    @if ($a->fields)
                        <div class="text-muted fs-12">{{ collect($a->fields)->map(fn ($f) => Str::headline(preg_replace('/_id$/', '', $f)))->implode(', ') }}</div>
                    @endif
                </div>
                <div class="text-muted fs-12 text-end text-nowrap" title="{{ $a->created_at->format('l j F Y, g:i a') }}">
                    {{ $a->created_at->format('D j M Y') }}<br>{{ $a->created_at->format('g:i a') }}
                </div>
            </li>
        @endforeach
    </ul>
@endif
