@extends('layouts.master')

@section('content')
<div class="main-content"><div class="page-content"><div class="container-fluid">
    @include('partials.page-title', ['title' => 'My Accommodation', 'icon' => 'ri-hotel-bed-line', 'subtitle' => $session?->name])
    @include('academics.partials.alerts')
    @if ($settings->get('instructions'))<div class="alert alert-info">{!! nl2br(e($settings->get('instructions'))) !!}</div>@endif

    @if ($allocation)
        <div class="card border-success"><div class="card-body">
            <h5 class="mb-1">{{ $allocation->room->hostel->name }}</h5>
            <div class="fs-15">{{ $allocation->room->label() }}, bed {{ $allocation->bed }}</div>
            <div class="mt-2"><span class="badge {{ \App\Models\BedAllocation::BADGES[$allocation->status] }}">{{ \App\Models\BedAllocation::STATUSES[$allocation->status] }}</span></div>
            @if ($allocation->status === 'reserved' && $allocation->invoice)
                <div class="alert alert-warning mt-3 mb-0">Pay ₦{{ number_format((float) $allocation->invoice->total - (float) $allocation->invoice->amount_paid, 2) }} by <strong>{{ $allocation->reserved_until->format('l j F Y') }}</strong> or the bed will be given to someone else.
                    <a href="{{ route('my.fees.show', $allocation->invoice) }}" class="btn btn-sm btn-warning ms-2">Pay now</a></div>
            @elseif ($allocation->status === 'confirmed')
                <p class="mt-2 mb-0 text-muted">Your place is confirmed. Report to the hostel to check in{{ $allocation->room->hostel->warden ? ' with the warden, ' . $allocation->room->hostel->warden->name : '' }}.</p>
            @endif
        </div></div>
    @elseif (! $session)
        <div class="alert alert-info">Accommodation opens once the new session is set up.</div>
    @else
        @if ($application && $application->status !== 'withdrawn')
            <div class="card"><div class="card-body">
                <h6>Your application <span class="badge {{ \App\Models\AccommodationApplication::BADGES[$application->status] }}">{{ \App\Models\AccommodationApplication::STATUSES[$application->status] }}</span></h6>
                <p class="mb-2 fs-13">Choices: {{ collect($application->preferences)->map(fn ($id) => $hostels->firstWhere('id', $id)?->name)->filter()->implode(', ') }} · sent {{ $application->created_at->format('j M Y') }}</p>
                @if ($application->status === 'pending')<form method="POST" action="{{ route('my.accommodation.withdraw') }}" onsubmit="return confirm('Withdraw your application?')">@csrf<button class="btn btn-sm btn-light">Withdraw</button></form>@endif
            </div></div>
        @endif
        @if ($settings->on('applications_open') && (! $application || $application->status !== 'pending'))
            <div class="card"><div class="card-header"><h6 class="card-title mb-0">Apply for a hostel place</h6></div><div class="card-body">
                @if ($hostels->isEmpty())
                    <p class="text-muted mb-0">No hostel is open to you at the moment.</p>
                @else
                    <form method="POST" action="{{ route('my.accommodation.apply') }}" class="row g-2">@csrf
                        @for ($i = 0; $i < max(1, min($settings->int('max_choices'), $hostels->count())); $i++)
                            <div class="col-md-4"><label class="form-label">{{ ['First', 'Second', 'Third', 'Fourth', 'Fifth'][$i] ?? 'Next' }} choice</label>
                                <select name="preferences[]" class="form-select" @if ($i === 0) required @endif><option value="">{{ $i ? 'None' : 'Choose…' }}</option>
                                    @foreach ($hostels as $h)<option value="{{ $h->id }}">{{ $h->name }} — {{ $h->rateFor($session) > 0 ? '₦' . number_format($h->rateFor($session)) : 'free' }}{{ ($free[$h->id] ?? false) ? '' : ' (full)' }}</option>@endforeach
                                </select></div>
                        @endfor
                        <div class="col-12"><button class="btn btn-primary">Apply</button></div>
                    </form>
                @endif
            </div></div>
        @elseif (! $settings->on('applications_open') && ! $application)
            <div class="alert alert-light border">Applications for accommodation are closed at the moment.</div>
        @endif
    @endif

    @if ($history->isNotEmpty())
        <div class="card"><div class="card-header"><h6 class="card-title mb-0">Earlier places</h6></div><ul class="list-group list-group-flush">
            @foreach ($history as $h)<li class="list-group-item fs-13 d-flex justify-content-between"><span>{{ $h->session->name }} · {{ $h->room->hostel->name }}, {{ $h->room->label() }}</span><span class="badge {{ \App\Models\BedAllocation::BADGES[$h->status] }}">{{ \App\Models\BedAllocation::STATUSES[$h->status] }}</span></li>@endforeach
        </ul></div>
    @endif
</div></div></div>
@endsection
