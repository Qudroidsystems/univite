@extends('layouts.master')

@section('content')
<div class="main-content"><div class="page-content"><div class="container-fluid">
    @include('partials.page-title', ['title' => 'Hostels', 'icon' => 'ri-hotel-bed-line', 'subtitle' => $session ? 'Beds for ' . $session->name : 'Set the current session to see bed numbers.'])
    @include('academics.partials.alerts')
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h6 class="card-title mb-0">{{ $rows->count() }} hostel(s) · {{ number_format($rows->sum(fn ($r) => $r->beds)) }} beds · {{ number_format($rows->sum(fn ($r) => $r->free())) }} free</h6>
            <div class="d-flex gap-2">
                @canany(['View accommodation', 'Allocate accommodation', 'Check students in and out of hostels'])<a href="{{ route('accommodation.allocations') }}" class="btn btn-sm btn-light">Allocations</a>@endcanany
                @can('Set accommodation policy')<a href="{{ route('accommodation.settings') }}" class="btn btn-sm btn-light">Rules</a><a href="{{ route('hostels.create') }}" class="btn btn-sm btn-primary"><i class="ri-add-line me-1"></i>New hostel</a>@endcan
            </div>
        </div>
        <div class="table-responsive"><table class="table align-middle mb-0">
            <thead class="table-light"><tr><th>Hostel</th><th>For</th><th class="text-end">Fee this session</th><th class="text-center">Beds</th><th class="text-center">Awaiting payment</th><th class="text-center">Confirmed</th><th class="text-center">Checked in</th><th class="text-center">Free</th></tr></thead><tbody>
            @forelse ($rows as $r)
                <tr class="{{ $r->hostel->is_active ? '' : 'text-muted' }}">
                    <td><a href="{{ route('hostels.show', $r->hostel) }}" class="fw-semibold">{{ $r->hostel->name }}</a>@unless ($r->hostel->is_active) <span class="badge bg-light text-body">Off</span>@endunless
                        @if ($r->hostel->warden)<div class="fs-12 text-muted">Warden: {{ $r->hostel->warden->name }}</div>@endif</td>
                    <td class="fs-13">{{ \App\Models\Hostel::GENDERS[$r->hostel->gender] }}</td>
                    <td class="text-end">{{ $r->rate > 0 ? '₦' . number_format($r->rate, 2) : 'Free' }}</td>
                    <td class="text-center">{{ $r->beds }}</td><td class="text-center">{{ $r->reserved }}</td><td class="text-center">{{ $r->confirmed }}</td><td class="text-center">{{ $r->checkedIn }}</td>
                    <td class="text-center fw-semibold">{{ $r->free() }}</td>
                </tr>
            @empty
                <tr><td colspan="8" class="text-center text-muted py-5">No hostels yet.</td></tr>
            @endforelse
        </tbody></table></div>
    </div>
</div></div></div>
@endsection
