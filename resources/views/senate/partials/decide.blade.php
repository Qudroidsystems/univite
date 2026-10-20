{{-- Senate's decision on one student or course. --}}
<form method="POST" action="{{ route('senate.decide', $q->params()) }}" class="d-flex flex-wrap gap-2 align-items-center">
    @csrf
    <input type="hidden" name="subject_type" value="{{ $type }}"><input type="hidden" name="subject_id" value="{{ $id }}">
    @if ($sem)<input type="hidden" name="semester_type_id_subject" value="{{ $sem }}">@endif
    <div class="btn-group btn-group-sm" role="group">
        @foreach (\App\Models\SenateDecision::DECISIONS as $v => $l)
            <input type="radio" class="btn-check" name="decision" value="{{ $v }}" id="dec-{{ $type }}-{{ $id }}-{{ $sem }}-{{ $v }}" @checked($suggest === $v)>
            <label class="btn btn-outline-{{ $v === 'approved' ? 'success' : ($v === 'deferred' ? 'warning' : 'danger') }}" for="dec-{{ $type }}-{{ $id }}-{{ $sem }}-{{ $v }}">{{ $v === 'approved' ? 'Approve' : ($v === 'deferred' ? 'Defer' : 'Refer') }}</label>
        @endforeach
    </div>
    <input name="note" class="form-control form-control-sm flex-grow-1" style="max-width:520px" placeholder="Senate's note (needed to defer or refer)">
    <button class="btn btn-sm btn-primary">Record decision</button>
</form>
