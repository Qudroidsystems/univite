<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><title>Fee eligibility · {{ $session?->name }}</title>
@php
    use App\Services\Fees\FeeAccess;
    $color = \App\Support\Institution::get('primary_color') ?: '#1f3b73';
    $n = fn ($v) => number_format((float) $v, 2);
@endphp
<style>
    @page { size: A4 landscape; margin: 12mm; }
    body { font-family: "DejaVu Sans", Arial, sans-serif; font-size: 10px; color: #1b1b1b; }
    .toolbar { padding: 8px; background: #f4f6fb; margin-bottom: 8px; } @media print { .toolbar { display: none; } }
    table { width: 100%; border-collapse: collapse; } th { background: {{ $color }}; color: #fff; padding: 4px; font-size: 9px; } td { border: .5px solid #b8bfcc; padding: 3px 4px; }
    .no { color: #b3261e; font-weight: bold; } .yes { color: #1e7b34; }
</style></head><body>
<div class="toolbar"><button onclick="window.print()">Print or save as PDF</button></div>
@include('partials.print-letterhead', ['lines' => ['Fee eligibility list', ($session?->name ?? '') . ($area ? ' · ' . (FeeAccess::AREAS[$area][0] ?? '') . ($state ? ': ' . ($state === 'blocked' ? 'locked' : 'open') : '') : '')]])
<table><thead><tr><th>#</th><th>Matric number</th><th>Name</th><th>Programme</th><th>Level</th><th>Billed</th><th>Paid</th><th>%</th>@foreach (FeeAccess::AREAS as [$l])<th>{{ $l }}</th>@endforeach</tr></thead><tbody>
@foreach ($rows->values() as $i => $r)
    @php $s = $r['standing']; @endphp
    <tr><td>{{ $i + 1 }}</td><td>{{ $r['student']->matric_number }}</td><td>{{ $r['student']->full_name }}</td><td>{{ $r['student']->programme?->name }}</td><td>{{ $r['student']->current_level }}</td>
        <td style="text-align:right">{{ $n($s->total) }}</td><td style="text-align:right">{{ $n($s->paid) }}</td><td>{{ round($s->percent) }}</td>
        @foreach (array_keys(FeeAccess::AREAS) as $a)<td class="{{ $s->allows($a) ? 'yes' : 'no' }}">{{ $s->allows($a) ? 'Yes' : 'No' }}</td>@endforeach</tr>
@endforeach
</tbody></table>
<p style="margin-top:8px;color:#555">Printed {{ now()->format('j M Y, g:i a') }}. {{ $rows->count() }} students.</p>
</body></html>
