@extends('layouts.master')

@section('content')
<div class="main-content"><div class="page-content"><div class="container-fluid">
    @include('partials.page-title', ['title' => 'My payslips', 'icon' => 'ri-file-list-3-line'])
    <div class="card"><div class="table-responsive"><table class="table align-middle mb-0">
        <thead class="table-light"><tr><th>Month</th><th class="text-end">Gross</th><th class="text-end">Deductions</th><th class="text-end">Net pay</th><th></th></tr></thead><tbody>
        @forelse ($slips as $s)
            <tr><td class="fw-semibold">{{ $s->run->label() }}</td><td class="text-end">₦{{ number_format($s->gross, 2) }}</td><td class="text-end">₦{{ number_format($s->total_deductions, 2) }}</td>
                <td class="text-end fw-semibold">₦{{ number_format($s->net, 2) }}</td><td class="text-end"><a href="{{ route('my.payslips.show', $s) }}" class="btn btn-sm btn-soft-primary">Open</a></td></tr>
        @empty
            <tr><td colspan="5" class="text-center text-muted py-5">No payslips yet.</td></tr>
        @endforelse
    </tbody></table></div></div>
</div></div></div>
@endsection
