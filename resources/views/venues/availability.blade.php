@extends('layouts.master')

@section('content')
<div class="main-content"><div class="page-content"><div class="container-fluid" style="max-width:1100px">
    @include('partials.page-title', ['title' => 'Find a free venue', 'icon' => 'ri-search-line', 'subtitle' => 'Free venues for a date and time, smallest suitable first. Closures, faults, bookings and published timetables are all checked.', 'back' => [route('venues.index'), 'Venues']])
    <div class="card"><div class="card-body"><form method="GET" class="row g-2 align-items-end">
        <div class="col-md-2"><label class="form-label fs-12">Date</label><input type="date" name="date" value="{{ $q['date'] ?? today()->toDateString() }}" class="form-control form-control-sm" required></div>
        <div class="col-md-2"><label class="form-label fs-12">From</label><input type="time" name="from" value="{{ $q['from'] ?? '09:00' }}" class="form-control form-control-sm" required></div>
        <div class="col-md-2"><label class="form-label fs-12">To</label><input type="time" name="to" value="{{ $q['to'] ?? '11:00' }}" class="form-control form-control-sm" required></div>
        <div class="col-md-2"><label class="form-label fs-12">People</label><input type="number" min="1" name="seats" value="{{ $q['seats'] ?? '' }}" class="form-control form-control-sm"></div>
        <div class="col-md-2"><label class="form-label fs-12">For</label><select name="use" class="form-select form-select-sm">@foreach (\App\Models\Venue::USES as $k => $l)<option value="{{ $k }}" @selected(($q['use'] ?? 'lecture') === $k)>{{ $l }}</option>@endforeach</select></div>
        <div class="col-md-2"><label class="form-label fs-12">Type</label><select name="type" class="form-select form-select-sm"><option value="">Any</option>@foreach ($types as $k => $l)<option value="{{ $k }}" @selected(($q['type'] ?? '') === $k)>{{ $l }}</option>@endforeach</select></div>
        <div class="col-12">@foreach ($facilities as $k => $l)<div class="form-check form-check-inline"><input class="form-check-input" type="checkbox" name="facilities[]" value="{{ $k }}" id="f{{ $k }}" @checked(in_array($k, $q['facilities'] ?? [], true))><label class="form-check-label fs-13" for="f{{ $k }}">{{ $l }}</label></div>@endforeach</div>
        <div class="col-12"><button class="btn btn-sm btn-primary"><i class="ri-search-line me-1"></i>Find</button></div>
    </form></div></div>

    @if (! empty($q['date']))
        <div class="card"><div class="card-header"><h6 class="card-title mb-0">{{ $free->count() }} free venue(s)</h6></div>
            <div class="table-responsive"><table class="table align-middle mb-0"><tbody>
                @forelse ($free as $i => [$v, $cap])
                    <tr><td><a href="{{ route('venues.show', $v) }}" class="fw-semibold">{{ $v->name }}</a> @if ($i === 0)<span class="badge bg-success">Recommended: smallest that fits</span>@endif<div class="text-muted fs-12">{{ $v->code }} · {{ $v->type_label }} · {{ $v->location }}</div></td>
                        <td class="text-end">{{ number_format($cap) }} seats</td></tr>
                @empty
                    <tr><td class="text-muted">No suitable venue is free then. Try another time, fewer people, or fewer facilities.</td></tr>
                @endforelse
            </tbody></table></div></div>
        @if ($taken->isNotEmpty())
            <div class="card"><div class="card-header"><h6 class="card-title mb-0">Suitable but not free</h6></div>
                <div class="table-responsive"><table class="table table-sm mb-0"><tbody>
                    @foreach ($taken as [$v, $why, $cap])<tr><td>{{ $v->name }} <span class="text-muted">({{ $cap }})</span></td><td class="text-muted">{{ $why }}</td></tr>@endforeach
                </tbody></table></div></div>
        @endif
    @endif
</div></div></div>
@endsection
