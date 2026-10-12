@extends('layouts.master')

@section('content')
<div class="main-content"><div class="page-content"><div class="container-fluid">
    @include('partials.page-title', ['title' => $s->code . ' salary scale', 'icon' => 'ri-table-line', 'back' => [route('payroll.structures.index'), 'Salary structures'],
        'subtitle' => $s->name . '. Annual basic salary (₦) for each grade level and step. Leave a cell blank where the step does not exist.'])
    @include('academics.partials.alerts')
    @php $edit = auth()->user()->can('Set payroll policy'); @endphp
    @if ($edit)
        <div class="card"><div class="card-body">
            <form method="POST" action="{{ route('payroll.scales.upload', $s) }}" enctype="multipart/form-data" class="d-flex gap-2 align-items-end flex-wrap">@csrf
                <div><label class="form-label">Load from a spreadsheet</label><input type="file" name="file" accept=".csv,.xlsx,.xls" class="form-control" required></div>
                <button class="btn btn-soft-primary"><i class="ri-upload-2-line me-1"></i>Upload</button>
                <span class="text-muted fs-13">Three columns with headings in the first row: Grade, Step, Annual basic. Rows in the file replace what is on screen; others are kept.</span>
            </form>
        </div></div>
    @endif
    <form method="POST" action="{{ route('payroll.scales.update', $s) }}">@csrf @method('PUT')
        <div class="card">
            <div class="table-responsive"><table class="table table-sm table-bordered align-middle mb-0">
                <thead class="table-light"><tr><th class="text-center">Grade \ Step</th>@for ($st = 1; $st <= $s->max_step; $st++)<th class="text-center">{{ $st }}</th>@endfor</tr></thead>
                <tbody>
                @for ($g = 1; $g <= $s->max_grade; $g++)
                    <tr><th class="text-center">{{ $g }}</th>
                        @for ($st = 1; $st <= $s->max_step; $st++)
                            @php $v = $grid["{$g}-{$st}"] ?? null; @endphp
                            <td class="p-1">@if ($edit)<input name="amount[{{ $g }}][{{ $st }}]" value="{{ $v !== null ? (float) $v : '' }}" type="number" step="0.01" min="0" class="form-control form-control-sm text-end" style="min-width:110px">@else<span class="d-block text-end">{{ $v !== null ? number_format((float) $v, 2) : '' }}</span>@endif</td>
                        @endfor
                    </tr>
                @endfor
                </tbody>
            </table></div>
            @if ($edit)<div class="card-footer"><button class="btn btn-primary">Save scale</button></div>@endif
        </div>
    </form>
</div></div></div>
@endsection
