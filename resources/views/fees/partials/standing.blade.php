{{-- A student's fee standing for the session: paid / total with a bar, and what each area allows. --}}
@php $n = fn ($v) => number_format((float) $v, 2); @endphp
@if ($standing->billed)
    <div class="text-start">
        <div class="d-flex justify-content-between fs-13 mb-1"><span>{{ $standing->session }} fees paid</span><strong>{{ rtrim(rtrim(number_format($standing->percent, 1), '0'), '.') }}%</strong></div>
        <div class="progress mb-2" style="height:10px"><div class="progress-bar {{ $standing->percent >= 100 ? 'bg-success' : ($standing->percent >= 50 ? 'bg-info' : 'bg-warning') }}" style="width:{{ min(100, $standing->percent) }}%"></div></div>
        <div class="d-flex flex-wrap justify-content-between fs-12 text-muted mb-2"><span>Paid {{ $n($standing->paid) }} of {{ $n($standing->total) }}@if ($standing->loan > 0) (school loan {{ $n($standing->loan) }})@endif</span><span>Balance {{ $n($standing->outstanding()) }}</span></div>
        @if ($standing->arrears > 0)<div class="fs-12 text-danger mb-1">Owed from earlier sessions: {{ $n($standing->arrears) }}</div>@endif
        @if ($standing->loanOverdue > 0)<div class="fs-12 text-danger mb-1">Overdue loan repayment: {{ $n($standing->loanOverdue) }}</div>@endif
        <ul class="list-group list-group-flush fs-13">
            @foreach (\App\Services\Fees\FeeAccess::AREAS as $area => [$label])
                @php $a = $standing->areas[$area]; @endphp
                @continue($a['needed'] === null && ! $a['override'])
                <li class="list-group-item px-0 d-flex justify-content-between align-items-center gap-2">
                    <span>{{ $label }}@if ($a['needed'] !== null)<span class="text-muted fs-12"> · {{ $a['needed'] }}% needed</span>@endif @if ($a['override'])<div class="fs-11 text-muted">{{ $a['override'] }}</div>@endif</span>
                    @if ($a['allowed'])<span class="badge bg-success-subtle text-success"><i class="ri-check-line"></i> Open</span>@else<span class="badge bg-danger-subtle text-danger"><i class="ri-lock-line"></i> Locked</span>@endif
                </li>
            @endforeach
        </ul>
    </div>
@endif
