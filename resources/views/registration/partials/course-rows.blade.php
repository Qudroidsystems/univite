{{-- Course rows of the registration form: a carry-over section or this semester's courses. --}}
<div class="table-responsive">
    <table class="table align-middle mb-0">
        <thead class="table-light"><tr><th style="width:1%"></th><th>Course</th><th>Type · owned by</th><th class="text-end">Units</th></tr></thead>
        <tbody>
        @forelse ($rows as $o)
            @php
                $c = $o['course'];
                $on = in_array($c->id, $chosen);
                $required = $o['carryover'] && ! $o['blocked'] && \App\Support\CourseType::mustRetake($o['status'], $rules);
                $last = $o['carryover'] ? collect($outstanding)->first(fn ($x) => $x['course']->id === $c->id) : null;
            @endphp
            <tr class="{{ $o['blocked'] ? 'text-muted' : '' }}">
                <td><input type="checkbox" class="form-check-input course-box" name="courses[]" data-code="{{ $c->code }}" data-required="{{ $required ? 1 : 0 }}" data-blocked="{{ $o['blocked'] ? 1 : 0 }}" value="{{ $c->id }}" data-units="{{ $c->credit_units }}" data-carry="{{ $o['carryover'] ? 1 : 0 }}" @checked($on) @disabled(! $editable || $o['blocked'])></td>
                <td><span class="fw-semibold">{{ $c->code }}</span> {{ $c->title }}
                    @if ($o['carryover'])<span class="badge bg-danger-subtle text-danger ms-1">Carry-over</span>@endif
                    @if ($last)<div class="fs-12 text-muted">Last attempt {{ $last['last_term'] }}: {{ $last['last_grade'] ?? '—' }}{{ $last['attempts'] > 1 ? ' · ' . $last['attempts'] . ' attempts' : '' }}</div>@endif
                    @if ($o['blocked'])<div class="fs-12 text-danger">{{ $o['blocked'] }}</div>@endif
                    <div class="fs-12 text-danger uv-why"></div></td>
                <td><x-course-tag :course="$c" :type="$o['status']" full faculty /></td>
                <td class="text-end">{{ $c->credit_units }}</td>
            </tr>
        @empty
            <tr><td colspan="4" class="text-center text-muted py-4">{{ $section === 'carry' ? 'No carry-over course is offered this semester.' : 'No courses are set up for your programme at this level and semester yet.' }}</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
