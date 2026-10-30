<div class="alert alert-warning d-flex flex-wrap align-items-center gap-2 uv-owing" role="status">
    <i class="ri-money-dollar-circle-line fs-20"></i>
    <div class="flex-grow-1">
        <div class="fw-semibold">You owe ₦{{ number_format($standing->owing(), 2) }}</div>
        <div class="fs-13">
            {{ $standing->owingNote ?? 'Please clear what you owe.' }}
            @if ($standing->arrears > 0.009) Includes ₦{{ number_format($standing->arrears, 2) }} from earlier sessions. @endif
        </div>
    </div>
    @if (Route::has('my.fees'))<a href="{{ route('my.fees') }}" class="btn btn-sm btn-warning">Pay now</a>@endif
</div>
