{{-- $results: App\Data\Polls\PollResults; $poll --}}
<div class="d-flex flex-wrap gap-4 mb-3">
    <div><div class="text-muted fs-12">Could vote</div><div class="fs-4 fw-semibold">{{ number_format($results->eligible) }}</div></div>
    <div><div class="text-muted fs-12">Voted</div><div class="fs-4 fw-semibold">{{ number_format($results->voted) }}</div></div>
    <div><div class="text-muted fs-12">Turnout</div><div class="fs-4 fw-semibold">{{ $results->turnout() }}%</div></div>
</div>
@foreach ($results->questions as $q)
    <div class="mb-4">
        <h6 class="mb-1">{{ $q->prompt }}</h6>
        <div class="text-muted fs-12 mb-2">{{ number_format($q->ballots) }} answered @if ($q->abstained) · {{ number_format($q->abstained) }} did not answer @endif
            @if ($q->tie && $poll->phase() === 'closed')<span class="badge bg-warning-subtle text-warning ms-1">Tie for first place</span>@endif</div>
        @foreach ($q->options as $o)
            <div class="d-flex align-items-center gap-2 mb-2">
                @if ($o->photoUrl)<img src="{{ $o->photoUrl }}" alt="" class="rounded-circle" style="width:32px;height:32px;object-fit:cover">@endif
                <div class="flex-grow-1">
                    <div class="d-flex justify-content-between fs-13"><span>@if ($o->leading && $poll->phase() === 'closed' && ! $q->tie)<i class="ri-trophy-line text-warning me-1"></i>@endif{{ $o->label }}</span><span class="fw-semibold">{{ number_format($o->votes) }} · {{ $o->percent }}%</span></div>
                    <div class="progress" style="height:8px"><div class="progress-bar {{ $o->leading ? 'bg-success' : '' }}" style="width: {{ $o->percent }}%"></div></div>
                </div>
            </div>
        @endforeach
        @if ($q->texts)
            <details><summary class="fs-13">{{ count($q->texts) }} written answer(s)</summary>
                <ul class="fs-13 mt-2">@foreach ($q->texts as $t)<li>{{ $t }}</li>@endforeach</ul></details>
        @endif
    </div>
@endforeach
