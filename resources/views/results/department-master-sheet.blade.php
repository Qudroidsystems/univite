<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Master marks sheet · {{ $sheet->query->title() }}</title>
@php
    use App\Data\Results\DepartmentSheetRow;
    use App\Services\Results\MasterSheets;
    $q = $sheet->query;
    $color = \App\Support\Institution::get('primary_color') ?: '#1f3b73';
    $name = \App\Support\Institution::get('name');
    $short = \App\Support\Institution::get('short_name');
    $n = fn ($v, $d = 0) => $d ? number_format((float) $v, $d) : (string) (0 + round((float) $v, 2));
@endphp
<style>
    @page { size: A4 landscape; margin: 14mm 10mm 14mm 10mm; }
    * { box-sizing: border-box; }
    body { font-family: "DejaVu Sans", Arial, Helvetica, sans-serif; font-size: 8.4px; color: #1b1b1b; margin: 0; }
    .toolbar { position: sticky; top: 0; background: #f4f6fb; border-bottom: 1px solid #d9dee8; padding: 8px 12px; font: 13px Arial, sans-serif; }
    .toolbar button, .toolbar a { margin-right: 8px; }
    .head { width: 100%; border-bottom: 2px solid {{ $color }}; margin-bottom: 6px; }
    .head td { vertical-align: middle; }
    .head .logo img { height: 62px; }
    .head .title { text-align: center; line-height: 1.35; }
    .head .uni { font-size: 15px; font-weight: bold; color: {{ $color }}; letter-spacing: .3px; }
    .head .line { font-size: 10px; font-weight: bold; }
    .head .sheet { font-size: 11.5px; font-weight: bold; letter-spacing: 1.5px; margin-top: 2px; }
    .head .meta { text-align: right; font-size: 9px; width: 120px; }
    table.ms { width: 100%; border-collapse: collapse; page-break-inside: auto; }
    table.ms th { background: {{ $color }}; color: #fff; font-weight: bold; font-size: 8px; padding: 3px 2px; border: .6px solid #7f8aa3; text-align: center; }
    table.ms td { border-left: .6px solid #b8bfcc; border-right: .6px solid #b8bfcc; padding: 1px 3px; vertical-align: top; }
    table.ms tr.section td { background: #eef1f6; font-weight: bold; font-size: 10px; padding: 4px 6px; border: .6px solid #b8bfcc; color: {{ $color }}; }
    table.ms tbody.student { page-break-inside: avoid; }
    table.ms tbody.student tr.first td { border-top: .6px solid #b8bfcc; }
    table.ms tr.foot td { border-bottom: .6px solid #b8bfcc; font-style: italic; color: #4a4f59; padding: 1px 4px 3px; }
    .reg { font-weight: bold; word-wrap: break-word; }
    .nm { color: #555; font-size: 7.6px; }
    .grid { border-collapse: collapse; }
    .grid td { border: 0 !important; padding: 0 3px 0 0 !important; text-align: center; white-space: nowrap; font-size: 8.2px; }
    .grid .u { color: #555; }
    .grid .m { font-weight: bold; }
    .grid .f { color: #b3261e; }
    .num { text-align: right; white-space: nowrap; }
    .rmk { text-align: center; font-weight: bold; }
    .REP, .INC { color: #b3261e; }
    .NRI { color: #8a6d00; }
    .keys { width: 100%; margin-top: 10px; page-break-inside: avoid; }
    .keys > tbody > tr > td { vertical-align: top; padding-right: 12px; }
    .keys h4 { font-size: 9.5px; margin: 0 0 3px; color: {{ $color }}; text-transform: uppercase; letter-spacing: .5px; }
    .kt { border-collapse: collapse; width: 100%; }
    .kt th, .kt td { border: .6px solid #b8bfcc; padding: 2px 4px; font-size: 8px; text-align: left; }
    .kt th { background: #eef1f6; }
    .sign { width: 100%; margin-top: 22px; page-break-inside: avoid; }
    .sign td { width: 33%; text-align: center; padding: 0 18px; font-size: 9px; }
    .sign .ln { border-top: .8px solid #333; padding-top: 3px; margin-top: 28px; }
    @media print { .toolbar { display: none; } }
</style>
</head>
<body>
@unless ($forPdf)
    <div class="toolbar"><button onclick="window.print()">Print</button>Choose “Save as PDF” in the print window for a PDF copy. Landscape, A4.</div>
@endunless

<table class="head">
    <tr>
        <td class="logo" style="width:120px">@if ($logo)<img src="{{ $logo }}" alt="">@endif</td>
        <td class="title">
            <div class="uni">{{ strtoupper($name) }}@if ($short && mb_strtoupper($short) !== mb_strtoupper($name)) ({{ strtoupper($short) }})@endif</div>
            @foreach ($heading as $i => $line)
                @if ($line)<div class="{{ $loop->last ? 'sheet' : 'line' }}">{{ $line }}</div>@endif
            @endforeach
        </td>
        <td class="meta">{{ now()->format('d/m/Y') }}<br>Computed {{ $sheet->computedAt?->format('d/m/Y g:i a') }}<br>{{ $sheet->count() }} students</td>
    </tr>
</table>

<table class="ms">
    <thead>
        <tr>
            <th rowspan="2" style="width:9.5%">REG NO</th>
            <th rowspan="2" style="width:31%">CURRENT SEMESTER</th>
            <th rowspan="2" style="width:3.6%">SEM<br>RMK</th>
            <th rowspan="2" style="width:15%">OUTSTANDING COURSES</th>
            <th colspan="3">PRESENT</th>
            <th colspan="3">PREVIOUS</th>
            <th colspan="3">CUMULATIVE</th>
            <th rowspan="2" style="width:3.6%">CUM<br>RMK</th>
            <th rowspan="2" style="width:9.5%">REG NO</th>
        </tr>
        <tr>
            @for ($i = 0; $i < 3; $i++)<th style="width:2.7%">TCP</th><th style="width:2.3%">TNU</th><th style="width:2.7%">GPA</th>@endfor
        </tr>
    </thead>
    @foreach ($sheet->sections as $rows)
        <tbody><tr class="section"><td colspan="15">{{ $service->sectionTitle($rows->first()->computed) }}</td></tr></tbody>
        @foreach ($rows as $row)
            @php($c = $row->computed)
            @php($out = collect($c->outstanding ?? []))
            <tbody class="student">
                <tr class="first">
                    <td><div class="reg">{{ $c->student->matric_number }}</div><div class="nm">{{ $c->student->full_name }}</div></td>
                    <td>
                        @if ($row->courses->isEmpty())
                            {{ $c->sem_remark === 'NRI' ? 'No Registration Information' : 'No submitted scores' }}
                        @else
                            @foreach ($row->courses->chunk($perLine) as $block)
                                <table class="grid" @if (! $loop->first) style="margin-top:2px" @endif>
                                    @php($split = $block->map(fn ($r) => DepartmentSheetRow::split($r->course->code)))
                                    <tr>@foreach ($split as [$subj])<td>{{ $subj }}</td>@endforeach</tr>
                                    <tr>@foreach ($split as [, $num])<td>{{ $num }}</td>@endforeach</tr>
                                    <tr>@foreach ($block as $r)<td class="u">({{ $r->credit_units }})</td>@endforeach</tr>
                                    <tr>@foreach ($block as $r)<td class="m {{ $r->is_pass === false ? 'f' : '' }}">{{ $r->total === null ? '—' : MasterSheets::score($r->total) . $r->grade }}</td>@endforeach</tr>
                                </table>
                            @endforeach
                        @endif
                    </td>
                    <td class="rmk {{ $c->sem_remark }}">{{ $c->sem_remark }}</td>
                    <td>
                        @foreach ($out->chunk(6) as $block)
                            <table class="grid" @if (! $loop->first) style="margin-top:2px" @endif>
                                @php($split = $block->map(fn ($o) => DepartmentSheetRow::split($o['code'])))
                                <tr>@foreach ($split as [$subj])<td>{{ $subj }}</td>@endforeach</tr>
                                <tr>@foreach ($split as [, $num])<td>{{ $num }}</td>@endforeach</tr>
                                <tr>@foreach ($block as $o)<td class="u">{{ $o['reason'] }}.</td>@endforeach</tr>
                            </table>
                        @endforeach
                    </td>
                    <td class="num">{{ $n($c->present_tcp) }}</td><td class="num">{{ $c->present_tnu }}</td><td class="num">{{ $n($c->present_gpa, 2) }}</td>
                    <td class="num">{{ $n($c->previous_tcp) }}</td><td class="num">{{ $c->previous_tnu }}</td><td class="num">{{ $n($c->previous_gpa, 2) }}</td>
                    <td class="num">{{ $n($c->cumulative_tcp) }}</td><td class="num">{{ $c->cumulative_tnu }}</td><td class="num"><strong>{{ $n($c->cgpa, 2) }}</strong></td>
                    <td class="rmk">{{ $c->cum_remark ?? '—' }}</td>
                    <td class="reg">{{ $c->student->matric_number }}</td>
                </tr>
                <tr class="foot"><td colspan="15">{{ $service->footer($c) }}</td></tr>
            </tbody>
        @endforeach
    @endforeach
</table>

<table class="keys">
    <tr>
        <td style="width:30%">
            <h4>Key to grade</h4>
            <table class="kt">
                <tr><th>Grade</th><th>Score range</th><th>Rank</th><th>Point</th></tr>
                @foreach ($sheet->gradeKey as $b)
                    <tr><td>{{ $b->letter }}</td><td>{{ MasterSheets::score($b->min_score) }} – {{ MasterSheets::score($b->max_score) }}</td><td>{{ $b->rank ?? ($b->is_pass ? 'Pass' : 'Fail') }}</td><td>{{ MasterSheets::score($b->points) }}</td></tr>
                @endforeach
            </table>
        </td>
        <td style="width:46%">
            <h4>Key to result</h4>
            @if ($sheet->resultKey->isEmpty())
                <div>No key to result is set for this department. Set one under Results, Key to Result.</div>
            @else
                <table class="kt">
                    <tr><th>Remark</th><th>CGPA range</th><th>Rank</th><th>Action</th><th>Activity</th></tr>
                    @foreach ($sheet->resultKey as $k)
                        <tr><td><strong>{{ $k->code }}</strong></td><td>{{ MasterSheets::score($k->min_gpa) }} – {{ MasterSheets::score($k->max_gpa) }}</td><td>{{ $k->rank }}</td><td>{{ $k->actionLabel() }}</td><td>{{ $k->activity }}</td></tr>
                    @endforeach
                </table>
            @endif
        </td>
        <td style="width:24%">
            <h4>Summary</h4>
            <table class="kt">
                @foreach (\App\Models\ComputedResult::SEM_REMARKS as $code => $meaning)
                    <tr><td><strong>{{ $code }}</strong> {{ $meaning }}</td><td class="num">{{ $sheet->semRemarks()[$code] ?? 0 }}</td></tr>
                @endforeach
                @foreach ($sheet->cumRemarks() as $code => $count)
                    <tr><td>Cumulative remark <strong>{{ $code }}</strong></td><td class="num">{{ $count }}</td></tr>
                @endforeach
                <tr><th>Total students</th><th class="num">{{ $sheet->count() }}</th></tr>
            </table>
            <div style="margin-top:4px">TCP: total credit points. TNU: total number of units. R.: to be repeated.</div>
        </td>
    </tr>
</table>

<table class="sign">
    <tr>
        <td><div class="ln">Head of Department</div></td>
        <td><div class="ln">Dean, {{ $q->department->faculty?->name }}</div></td>
        <td><div class="ln">Date</div></td>
    </tr>
</table>
</body>
</html>
