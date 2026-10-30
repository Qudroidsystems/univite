{{-- The student's referees, editable by staff who may edit students. --}}
@php $refs = $student->referees->keyBy('position'); @endphp
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center"><h6 class="card-title mb-0">Referees</h6>
        @if ($canEdit)<button class="btn btn-sm btn-soft-primary" type="button" data-bs-toggle="collapse" data-bs-target="#ref-form">Edit</button>@endif</div>
    <ul class="list-group list-group-flush">
        @forelse ($student->referees as $r)
            <li class="list-group-item fs-13"><span class="fw-semibold">{{ $r->name }}</span>@if ($r->occupation) · {{ $r->occupation }}@endif
                <div class="text-muted">{{ collect([$r->phone, $r->email, $r->address])->filter()->implode(' · ') }}</div></li>
        @empty
            <li class="list-group-item text-muted fs-13">No referees given.</li>
        @endforelse
    </ul>
    @if ($canEdit)
        <form method="POST" action="{{ route('students.referees', $student->id) }}" class="collapse card-body border-top" id="ref-form">@csrf @method('PUT')
            @foreach ([1, 2] as $i)
                <div class="row g-2 mb-2">
                    <div class="col-12 fw-semibold fs-13">Referee {{ $i }}</div>
                    <div class="col-md-6"><input name="referees[{{ $i }}][name]" value="{{ $refs[$i]->name ?? '' }}" class="form-control" placeholder="Name"></div>
                    <div class="col-md-6"><input name="referees[{{ $i }}][occupation]" value="{{ $refs[$i]->occupation ?? '' }}" class="form-control" placeholder="Occupation"></div>
                    <div class="col-md-6"><input name="referees[{{ $i }}][phone]" value="{{ $refs[$i]->phone ?? '' }}" class="form-control" placeholder="Phone"></div>
                    <div class="col-md-6"><input type="email" name="referees[{{ $i }}][email]" value="{{ $refs[$i]->email ?? '' }}" class="form-control" placeholder="Email"></div>
                    <div class="col-12"><input name="referees[{{ $i }}][address]" value="{{ $refs[$i]->address ?? '' }}" class="form-control" placeholder="Address"></div>
                </div>
            @endforeach
            <button class="btn btn-primary btn-sm">Save referees</button> <span class="text-muted fs-12 ms-2">Clear a name to remove that referee.</span>
        </form>
    @endif
</div>
