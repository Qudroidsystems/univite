{{--
    The score entry table, shared by a course's score sheet and Enter Scores. Each mark adds up as it is typed: a part's
    assessments fill its CA or exam total, the total and grade follow, and a lecturer may type a CA or exam total
    directly instead (the part's assessments are then cleared), or type only the overall total (every assessment, the
    CA and the exam are then cleared). Needs: $sheet, $rows, $scheme, $edit, $caMax, $examMax;
    optional $showClass (programme and level column) and $saveLabel.
--}}
@php
    $items = $scheme?->items ?? collect();
    $fmt = fn ($n) => $n === null ? '' : rtrim(rtrim(number_format((float) $n, 2, '.', ''), '0'), '.');
    $parts = ['ca' => $items->where('component', 'ca')->values(), 'exam' => $items->where('component', 'exam')->values()];
    $bands = \App\Support\GradeScale::bands()->map(fn ($b) => ['min' => (float) $b->min_score, 'max' => (float) $b->max_score, 'grade' => $b->letter, 'pass' => (bool) $b->is_pass])->values();
    $showClass = $showClass ?? false;
    $cols = 5 + $items->count() + ($showClass ? 1 : 0);
@endphp
<style>
    .uv-scores tbody tr { animation: uvRowIn .42s cubic-bezier(.2,.7,.3,1) both; animation-delay: calc(min(var(--i, 0), 40) * 22ms); }
    @keyframes uvRowIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: none; } }
    .uv-scores thead th { position: sticky; top: 0; z-index: 2; }
    .uv-scores tbody tr:hover { background: var(--tb-light, rgba(0,0,0,.025)); }
    .uv-scores .uv-total-in { font-weight: 600; background: var(--tb-primary-bg-subtle, #eef1fb); border-color: transparent; }
    .uv-scores .uv-total-in.is-partial { color: var(--tb-secondary-color, #888); font-style: italic; }
    .uv-scores .uv-out { font-weight: 600; min-width: 46px; display: inline-block; }
    .uv-scores .uv-flash { animation: uvFlash .7s ease; }
    @keyframes uvFlash { 0% { background: rgba(10,179,156,.28); } 100% { background: transparent; } }
    .uv-scores .uv-grade { transition: transform .25s ease; display: inline-block; }
    .uv-scores .uv-grade.uv-pop { transform: scale(1.25); }
    .uv-scores input.is-over { border-color: var(--tb-danger, #f06548); box-shadow: 0 0 0 .15rem rgba(240,101,72,.2); }
    .uv-scores .uv-part-head { font-size: 11px; letter-spacing: .04em; text-transform: uppercase; }
    .uv-progress { height: 6px; }
    .uv-progress .progress-bar { transition: width .4s ease; }
</style>
<div class="card uv-scores" data-score-table data-bands='@json($bands)'>
    @if ($rows->isNotEmpty())
        <div class="card-header d-flex flex-wrap gap-3 align-items-center justify-content-between">
            <div class="flex-grow-1" style="min-width:220px">
                <div class="d-flex justify-content-between fs-12 mb-1"><span data-progress-label>…</span><span class="text-muted">CA {{ $fmt($caMax) }} + Exam {{ $fmt($examMax) }} = {{ $fmt($caMax + $examMax) }}</span></div>
                <div class="progress uv-progress"><div class="progress-bar bg-success" data-progress style="width:0%"></div></div>
            </div>
            <div class="d-flex gap-3 fs-12 text-muted"><span>Passed <strong class="text-success" data-count="pass">0</strong></span><span>Failed <strong class="text-danger" data-count="fail">0</strong></span><span>Average <strong class="text-body" data-count="avg">–</strong></span></div>
        </div>
    @endif
    <div class="table-responsive" style="max-height:70vh">
        <table class="table table-sm align-middle mb-0">
            <thead class="table-light">
                @if ($scheme)
                    <tr class="uv-part-head text-muted">
                        <th colspan="{{ 3 + ($showClass ? 1 : 0) }}"></th>
                        @if ($parts['ca']->isNotEmpty())<th colspan="{{ $parts['ca']->count() + 1 }}" class="text-center border-start">Continuous assessment</th>@else<th class="border-start"></th>@endif
                        @if ($parts['exam']->isNotEmpty())<th colspan="{{ $parts['exam']->count() + 1 }}" class="text-center border-start">Examination</th>@else<th class="border-start"></th>@endif
                        <th colspan="2" class="border-start"></th>
                    </tr>
                @endif
                <tr><th>#</th><th>Matric number</th><th>Name</th>@if ($showClass)<th>Programme</th>@endif
                    @foreach (['ca' => 'CA', 'exam' => 'Exam'] as $part => $label)
                        @foreach ($parts[$part] as $k => $it)<th style="width:84px" class="text-nowrap {{ $k === 0 ? 'border-start' : '' }}" title="{{ $it->name }}">{{ $it->code }} <span class="text-muted fw-normal">/{{ $it->maxLabel() }}</span></th>@endforeach
                        <th style="width:96px" class="text-nowrap {{ $parts[$part]->isEmpty() ? 'border-start' : '' }}">{{ $scheme ? $label . ' total' : $label }} <span class="text-muted fw-normal">/{{ $fmt($part === 'ca' ? $caMax : $examMax) }}</span></th>
                    @endforeach
                    <th class="border-start">Total</th><th>Grade</th></tr>
            </thead>
            <tbody>
            @forelse ($rows->values() as $n => $r)
                @php $have = $r->assessmentScores->keyBy('assessment_item_id'); @endphp
                <tr data-row style="--i: {{ $n }}">
                    <td class="text-muted">{{ $n + 1 }}</td>
                    <td class="text-nowrap">{{ $r->student->matric_label }}</td>
                    <td>{{ $r->student->full_name }}</td>
                    @if ($showClass)<td class="fs-12 text-muted">{{ $r->student->programme?->code ?? $r->student->programme?->name }}{{ $r->student->current_level ? ' · ' . $r->student->current_level . 'L' : '' }}</td>@endif
                    @foreach (['ca' => $caMax, 'exam' => $examMax] as $part => $max)
                        @foreach ($parts[$part] as $k => $it)
                            @php $v = $have->has($it->id) ? $fmt($have[$it->id]->score) : ''; @endphp
                            <td class="{{ $k === 0 ? 'border-start' : '' }}">@if ($edit)<input type="number" step="0.01" min="0" max="{{ $it->max_score }}" name="scores[{{ $r->student_id }}][items][{{ $it->id }}]" value="{{ $v }}" class="form-control form-control-sm" data-item="{{ $part }}" aria-label="{{ $it->name }}">@else{{ $v }}@endif</td>
                        @endforeach
                        @php $tv = $fmt($part === 'ca' ? $r->ca : $r->exam); @endphp
                        <td class="{{ $parts[$part]->isEmpty() ? 'border-start' : '' }}">@if ($edit)<input type="number" step="0.01" min="0" max="{{ $max }}" name="scores[{{ $r->student_id }}][{{ $part }}]" value="{{ $tv }}" class="form-control form-control-sm {{ $scheme ? 'uv-total-in' : '' }}" data-total="{{ $part }}" data-has-items="{{ $parts[$part]->isNotEmpty() ? 1 : 0 }}" aria-label="{{ $part === 'ca' ? 'CA' : 'Exam' }} total" title="{{ $scheme && $parts[$part]->isNotEmpty() ? 'Adds up from the assessments, or type the total directly' : '' }}">@else<span data-total-text="{{ $part }}">{{ $tv }}</span>@endif</td>
                    @endforeach
                    <td class="border-start">@if ($edit)<input type="number" step="0.01" min="0" max="{{ $caMax + $examMax }}" name="scores[{{ $r->student_id }}][total]" value="{{ $fmt($r->total) }}" class="form-control form-control-sm uv-total-in" style="width:84px" data-out="total" data-grand aria-label="Total" title="Adds up from the CA and exam, or type the total directly">@else<span class="uv-out" data-out="total">{{ $fmt($r->total) }}</span>@endif</td>
                    <td><span class="uv-grade badge {{ $r->grade ? ($r->is_pass ? 'bg-success-subtle text-success' : 'bg-danger-subtle text-danger') : '' }}" data-out="grade">{{ $r->grade }}</span></td>
                </tr>
            @empty
                <tr><td colspan="{{ $cols + 2 }}" class="text-center text-muted py-5"><i class="ri-user-search-line fs-2 d-block mb-1"></i>No student with an approved registration matches.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    @if ($edit && $rows->isNotEmpty())
        <div class="card-footer d-flex flex-wrap gap-2 justify-content-between align-items-center">
            <span class="text-muted fs-13">Totals and grades update as you type. A part's assessments add up to its total. You can also type the CA total, the exam total or the overall total directly.</span>
            <button class="btn btn-primary" type="submit"><i class="ri-save-line me-1"></i>{{ $saveLabel ?? 'Save scores' }}</button>
        </div>
    @endif
</div>
@push('scripts')
<script src="{{ asset('js/univite-scores.js') }}?v={{ @filemtime(public_path('js/univite-scores.js')) }}"></script>
@endpush
