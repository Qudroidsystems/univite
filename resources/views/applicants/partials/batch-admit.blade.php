@php
    $canAdmit = auth()->user()->can('Admit applicants');
    $canSettings = auth()->user()->can('View application settings');
@endphp
@if ($canAdmit || $canSettings)
<div class="card border-0 bg-light mb-3">
    <div class="card-body d-flex flex-wrap gap-3 align-items-end justify-content-between">
        @if ($canAdmit)
            <form method="POST" action="{{ route('applicants.admit-batch') }}" class="d-flex flex-wrap gap-2 align-items-end"
                  onsubmit="return confirm('Admit every submitted applicant that matches? Each one gets a matric number and a login. This cannot be undone.')">
                @csrf
                <div>
                    <label class="form-label fs-12 mb-1">Admit all submitted applicants as a batch ({{ $pendingCount }} waiting)</label>
                    <input name="batch" class="form-control" style="width:260px" placeholder="First Batch 2026/2027" required maxlength="100">
                </div>
                <div>
                    <select name="programme_id" data-lookup="programmes" class="form-select" style="width:220px">
                        <option value="">All programmes</option>
                        @foreach ($programmeOptions as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach
                    </select>
                </div>
                <button class="btn btn-success" type="submit" @disabled(! $pendingCount)><i class="ri-user-follow-line me-1"></i>Admit batch</button>
            </form>
        @endif
        @if ($canSettings)
            <a href="{{ route('applicants.settings') }}" class="btn btn-soft-primary"><i class="ri-settings-3-line me-1"></i>Application settings</a>
        @endif
    </div>
</div>
@endif
