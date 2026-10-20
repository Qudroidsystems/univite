<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><title>{{ $lines[0] }}</title>
@php
    use App\Data\Senate\SenateStudent as S;
    use App\Models\SenateDecision as D;
    $color = \App\Support\Institution::get('primary_color') ?: '#1f3b73';
@endphp
<style>
    @page { size: A4 landscape; margin: 12mm 9mm; }
    * { box-sizing: border-box; }
    body { font-family: "DejaVu Sans", Arial, Helvetica, sans-serif; font-size: 8.6px; color: #1b1b1b; margin: 0; }
    .toolbar { position: sticky; top: 0; background: #f4f6fb; border-bottom: 1px solid #d9dee8; padding: 8px 12px; font: 13px Arial, sans-serif; }
    @media print { .toolbar { display: none; } }
    table.t { width: 100%; border-collapse: collapse; page-break-inside: auto; }
    table.t th { background: {{ $color }}; color: #fff; font-size: 8px; padding: 3px; border: .5px solid #7f8aa3; text-align: left; }
    table.t td { border: .5px solid #b8bfcc; padding: 2px 3px; vertical-align: top; }
    table.t tr { page-break-inside: avoid; }
    .eligible { color: #1e7b34; font-weight: bold; } .not_eligible { color: #b3261e; font-weight: bold; } .needs_decision { color: #8a6d00; font-weight: bold; }
    .g { display: inline-block; min-width: 10px; padding: 0 2px; border-radius: 2px; font-weight: bold; color: #fff; margin-right: 2px; }
    .gA { background: #405189; } .gB { background: #b8860b; } .gC { background: #2c7da0; } .gD { background: #666; }
    .blk { color: #b3261e; } .muted { color: #666; }
    .sign { width: 100%; margin-top: 22px; page-break-inside: avoid; } .sign td { width: 33%; text-align: center; padding: 0 18px; font-size: 9px; border: 0; } .sign .ln { border-top: .8px solid #333; padding-top: 3px; margin-top: 28px; }
    .lvl0 td { background: #e8ecf6; font-weight: bold; } .lvl1 td { font-weight: bold; }
</style></head><body>
@unless ($forPdf)<div class="toolbar"><button onclick="window.print()">Print or save as PDF</button> <a href="javascript:window.close()">Close</a></div>@endunless
@include('partials.print-letterhead', ['lines' => $lines])

@if ($view === 'students')
    @php $counts = $students->countBy(fn ($s) => $s->status()); @endphp
    <p><strong>{{ $students->count() }}</strong> candidates · <span class="eligible">{{ $counts['eligible'] ?? 0 }} eligible</span> · <span class="not_eligible">{{ $counts['not_eligible'] ?? 0 }} not eligible</span> · <span class="needs_decision">{{ $counts['needs_decision'] ?? 0 }} need a Senate decision</span></p>
    <table class="t"><thead><tr><th>#</th><th>Matric number</th><th>Name</th><th>Programme · level</th><th>CGPA</th><th>Class</th><th>Units</th><th>Status</th><th style="width:40%">Findings (A eligibility · B result integrity · C clearance)</th><th>Senate</th></tr></thead><tbody>
    @foreach ($students->values() as $i => $s)
        @php $st = $s->status(); @endphp
        <tr><td>{{ $i + 1 }}</td><td>{{ $s->student->matric_number }}</td><td>{{ $s->student->full_name }}</td><td>{{ $s->student->programme->name }} · {{ $s->level }}</td>
            <td>{{ number_format($s->cgpa, 2) }}</td><td>{{ $s->class }}</td><td>{{ $s->unitsPassed }}{{ $s->unitsNeeded ? '/' . $s->unitsNeeded : '' }}</td><td class="{{ $st }}">{{ S::STATUSES[$st] }}</td>
            <td>@foreach ($s->reasons as $r)<div class="{{ $r->blocks ? 'blk' : ($r->info ? 'muted' : '') }}"><span class="g g{{ $r->group() }}">{{ $r->group() }}</span>{{ $r->text }}</div>@endforeach</td>
            <td>{{ $s->decision ? D::DECISIONS[$s->decision->decision] : '' }}@if ($s->decision?->note)<div class="muted">{{ $s->decision->note }}</div>@endif</td></tr>
    @endforeach
    </tbody></table>
@elseif ($view === 'courses')
    <table class="t"><thead><tr><th>#</th><th>Code</th><th>Title</th><th>Semester</th><th>Department</th><th>Lecturer(s)</th><th>Students</th><th>Pass %</th><th>Avg (dept)</th><th>Grades</th><th>Sheet</th><th style="width:28%">Findings</th><th>Senate</th></tr></thead><tbody>
    @foreach ($courses->values() as $i => $c)
        <tr><td>{{ $i + 1 }}</td><td>{{ $c->course->code }}</td><td>{{ $c->course->title }}</td><td>{{ $c->semester }}</td><td>{{ $c->course->department?->name }}</td><td>{{ implode(', ', $c->lecturers) }}</td>
            <td>{{ $c->withResult }}/{{ $c->registered }}</td><td>{{ $c->passRate() }}</td><td>{{ $c->average }} ({{ $c->departmentAverage }})</td>
            <td>{{ collect($c->grades)->map(fn ($n, $g) => "{$g}:{$n}")->implode(' ') }}</td><td>{{ \App\Models\ResultSheet::STATUSES[$c->sheetStatus] ?? 'Not opened' }}{{ $c->stage ? ' · ' . $c->stage : '' }}</td>
            <td>@foreach ($c->reasons as $r)<div>{{ $r->text }}</div>@endforeach</td><td>{{ $c->decision ? D::DECISIONS[$c->decision->decision] : '' }}</td></tr>
    @endforeach
    </tbody></table>
@else
    <table class="t"><thead><tr><th>Faculty · department · programme</th><th>Candidates</th><th>Eligible</th><th>Not eligible</th><th>Needs decision</th><th>Approved</th><th>Avg CGPA</th><th>Course pass %</th><th>Classes of degree</th><th>Commonest reasons</th></tr></thead><tbody>
    @php
        $emit = function ($label, $x, $lvl) {
            return '<tr class="lvl' . $lvl . '"><td style="padding-left:' . (3 + $lvl * 12) . 'px">' . e($label) . '</td><td>' . $x['candidates'] . '</td><td>' . $x['eligible'] . '</td><td>' . $x['not_eligible'] . '</td><td>' . $x['needs_decision'] . '</td><td>' . $x['approved'] . '</td><td>'
                . ($x['average_cgpa'] !== null ? number_format($x['average_cgpa'], 2) : '') . '</td><td>' . ($x['pass_rate'] ?? '') . '</td><td>' . e(collect($x['classes'])->map(fn ($n, $c) => "{$c}: {$n}")->implode('; ')) . '</td><td>'
                . e(collect($x['reasons'])->map(fn ($n, $r) => "{$r} ({$n})")->implode('; ')) . '</td></tr>';
        };
    @endphp
    @foreach ($summary['faculties'] as $f => $fac)
        {!! $emit($f, $fac['total'], 0) !!}
        @foreach ($fac['departments'] as $d => $dep)
            {!! $emit($d, $dep['total'], 1) !!}
            @foreach ($dep['programmes'] as $p => $x){!! $emit($p, $x, 2) !!}@endforeach
        @endforeach
    @endforeach
    {!! $emit('Whole review', $summary['all'], 0) !!}
    </tbody></table>
@endif

<table class="sign"><tr>
    <td><div class="ln">Secretary to Senate</div></td><td><div class="ln">Chairman, Senate Business Committee</div></td><td><div class="ln">Vice-Chancellor / Chairman of Senate</div></td>
</tr></table>
</body></html>
