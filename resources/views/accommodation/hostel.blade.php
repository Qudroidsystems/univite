@extends('layouts.master')

@section('content')
<div class="main-content"><div class="page-content"><div class="container-fluid">
    @include('partials.page-title', ['title' => $hostel->name, 'icon' => 'ri-hotel-bed-line', 'subtitle' => \App\Models\Hostel::GENDERS[$hostel->gender] . ' · ' . app(\App\Services\Campus\AudienceResolver::class)->describe($hostel->audienceData()) . ($session ? ' · ' . ($hostel->rateFor($session) > 0 ? '₦' . number_format($hostel->rateFor($session), 2) . ' per bed for ' . $session->name : 'free') : ''), 'back' => [route('hostels.index'), 'Hostels']])
    @include('academics.partials.alerts')
    @can('Set accommodation policy')
        <div class="card"><div class="card-body d-flex flex-wrap gap-3 justify-content-between align-items-end">
            <form method="POST" action="{{ route('hostels.rooms.store', $hostel) }}" class="row g-2 align-items-end flex-grow-1">@csrf
                <div class="col-md-2"><label class="form-label fs-12">Block / wing</label><input name="block" class="form-control form-control-sm"></div>
                <div class="col-md-1"><label class="form-label fs-12">Prefix</label><input name="prefix" class="form-control form-control-sm" placeholder="A"></div>
                <div class="col-md-2"><label class="form-label fs-12">Rooms from</label><input name="from" type="number" min="0" class="form-control form-control-sm" required></div>
                <div class="col-md-2"><label class="form-label fs-12">to</label><input name="to" type="number" min="0" class="form-control form-control-sm" required></div>
                <div class="col-md-2"><label class="form-label fs-12">Beds per room</label><input name="capacity" type="number" min="1" max="50" value="4" class="form-control form-control-sm" required></div>
                <div class="col-md-1"><label class="form-label fs-12">Floor</label><input name="floor" type="number" min="0" class="form-control form-control-sm"></div>
                <div class="col-md-2"><button class="btn btn-sm btn-primary w-100">Add rooms</button></div>
            </form>
            <a href="{{ route('hostels.edit', $hostel) }}" class="btn btn-sm btn-light">Edit hostel</a>
        </div></div>
    @endcan
    <div class="row">
        @forelse ($rooms as $room)
            @php $in = ($allocations[$room->id] ?? collect())->keyBy('bed'); @endphp
            <div class="col-xl-3 col-lg-4 col-md-6"><div class="card {{ $room->is_active ? '' : 'opacity-50' }}"><div class="card-body p-3">
                <div class="d-flex justify-content-between"><strong>{{ $room->label() }}</strong><span class="fs-12 text-muted">{{ $in->count() }}/{{ $room->capacity }}</span></div>
                @if ($room->note)<div class="fs-12 text-muted">{{ $room->note }}</div>@endif
                <ul class="list-unstyled fs-12 mb-2 mt-1">
                    @for ($b = 1; $b <= $room->capacity; $b++)
                        <li>Bed {{ $b }}: @if ($a = $in->get($b)){{ $a->student->full_name }} <span class="badge {{ \App\Models\BedAllocation::BADGES[$a->status] }}">{{ \Illuminate\Support\Str::before(\App\Models\BedAllocation::STATUSES[$a->status], ' (') }}</span>@else<span class="text-success">free</span>@endif</li>
                    @endfor
                </ul>
                @can('Set accommodation policy')
                    <form method="POST" action="{{ route('hostels.rooms.update', $room) }}" class="d-flex gap-1 align-items-center">@csrf @method('PUT')
                        <input name="capacity" type="number" min="1" max="50" value="{{ $room->capacity }}" class="form-control form-control-sm" style="width:64px" title="Beds">
                        <input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" class="form-check-input" title="In use" @checked($room->is_active)>
                        <input name="note" value="{{ $room->note }}" class="form-control form-control-sm" placeholder="Note">
                        <button class="btn btn-sm btn-light">Save</button>
                    </form>
                @endcan
            </div></div></div>
        @empty
            <div class="col-12"><div class="alert alert-info">No rooms yet.</div></div>
        @endforelse
    </div>
</div></div></div>
@endsection
