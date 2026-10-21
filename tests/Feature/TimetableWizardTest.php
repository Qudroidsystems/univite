<?php

use App\Models\Timetable;
use App\Models\TimetableRun;
use App\Models\TimetableTemplate;
use App\Services\Timetable\TimetableRuns;
use App\Services\Timetable\TimetableWizard;
use App\Services\TimetableService;
use App\Timetabling\DayStructure;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

require_once __DIR__ . '/../timetable-world.php';

beforeEach(fn () => timetableWorld($this));

function wizardAnswers($test, array $extra = []): array
{
    return array_merge(TimetableWizard::defaults(), [
        'name' => 'Whole school lectures', 'academic_session_id' => $test->session->id, 'semester_type_id' => $test->sem->id,
        'algorithm' => 'quick', 'candidates' => 2, 'vary_algorithms' => true,
    ], $extra);
}

it('builds a teaching day from start, end, period length and breaks', function () {
    $d = DayStructure::build('08:00', '14:00', 60, [['after' => 2, 'minutes' => 30, 'label' => 'Short break'], ['after' => 4, 'minutes' => 60, 'label' => 'Lunch']]);

    expect($d['periods'])->toBe([['08:00', '09:00'], ['09:00', '10:00'], ['10:30', '11:30'], ['11:30', '12:30']])
        ->and($d['breaks'])->toBe([['10:00', '10:30', 'Short break'], ['12:30', '13:30', 'Lunch']])
        ->and(DayStructure::build('08:00', '18:00', 60, [], 6)['periods'])->toHaveCount(6);
});

it('shows the courses it would schedule and what is missing, without saving anything', function () {
    $before = Timetable::count();
    $r = app(TimetableWizard::class)->courses(wizardAnswers($this), $this->admin);

    expect(Timetable::count())->toBe($before)
        ->and($r['totals']['courses'])->toBe(16)
        ->and($r['courses']->firstWhere('code', 'CSC 101')['students'])->toBe(60)
        ->and($r['courses']->firstWhere('code', 'CSC 101')['meetings'])->toBe(1)       // 2 units: once a week…
        ->and($r['courses']->firstWhere('code', 'CSC 101')['duration'])->toBe(2)       // …as a double period
        ->and($r['courses']->firstWhere('code', 'CSC 101')['priority'])->toBe(4)       // compulsory
        ->and($r['courses']->firstWhere('code', 'CSC 101')['warnings'])->toContain('No lecturer defined for this semester');
});

it('previews a whole-school generation with half days and events kept free', function () {
    $answers = wizardAnswers($this, [
        'half_days' => [['day' => 3, 'ends_at' => '12:00', 'label' => 'Sports']],
        'events' => [['day' => 5, 'start' => '13:00', 'end' => '15:00', 'label' => 'Jumat prayers']],
    ]);
    $r = app(TimetableWizard::class)->preview($answers, $this->admin);

    expect(Timetable::count())->toBe(0)
        ->and($r['placed'])->toBe($r['units'])->and($r['violations'])->toBe(0)
        ->and(collect($r['blocked'])->pluck('label')->all())->toBe(['Sports', 'Jumat prayers'])
        ->and(collect($r['departments'])->firstWhere('department', 'Computer Science')['courses'])->toBe(16);
});

it('creates the draft through the wizard page and generates the candidates', function () {
    $this->actingAs($this->admin)->post(route('timetables.wizard.store'), wizardAnswers($this, [
        'courses' => [$this->courses['CSC 101']->id => ['include' => '1', 'meetings' => 2, 'duration' => 1, 'priority' => 5], $this->courses['MTH 204']->id => ['include' => '0']],
    ]))->assertRedirect();

    $t = Timetable::latest('id')->first();
    $csc = $t->items()->where('course_id', $this->courses['CSC 101']->id)->first();
    expect($csc->meetings)->toBe(2)->and($csc->duration)->toBe(1)->and($csc->priority)->toBe(5)
        ->and($t->items()->where('course_id', $this->courses['MTH 204']->id)->value('is_active'))->toBeFalsy()
        ->and($t->config['wizard']['day_start'])->toBe('08:00');

    $runs = $t->runs()->reorder('id')->get();
    expect($runs)->toHaveCount(2)->and($runs->pluck('status')->unique()->all())->toBe(['done'])   // the queue is synchronous in tests
        ->and($runs->pluck('algorithm')->all())->toBe(['quick', 'thorough'])
        ->and($runs[0]->summary['violations'])->toBe(0)->and($runs[0]->stats['venues']['occupancy'])->toBeGreaterThan(0);

    $this->get(route('timetables.runs', $t))->assertOk()->assertSee('Candidate A')->assertSee('Candidate B');
    $this->get(route('timetables.runs.compare', [$t, 'runs' => $runs->pluck('id')->all()]))->assertOk()->assertSee('Preference score');
    $this->get(route('timetables.runs.show', [$t, $runs[1]]))->assertOk()->assertSee('Lecturers');
});

it('applies the chosen candidate to the draft', function () {
    $this->actingAs($this->admin)->post(route('timetables.wizard.store'), wizardAnswers($this));
    $t = Timetable::latest('id')->first();
    $run = $t->runs()->get()->last();

    $this->post(route('timetables.runs.apply', [$t, $run]))->assertRedirect(route('timetables.show', $t));

    $t->refresh();
    expect($run->fresh()->status)->toBe('applied')->and($t->entries()->count())->toBeGreaterThan(0)
        ->and($t->summary['placed'])->toBe($run->summary['placed'])->and($t->summary['analytics']['required'])->toBe($run->summary['units'])
        ->and(TimetableRuns::diff(app(TimetableRuns::class)->current($t), $run->assignment, app(TimetableService::class)->problem($t))['moved'])->toBeEmpty();
    $this->get(route('timetables.analytics', $t))->assertOk()->assertSee('Venue occupancy');
});

it('regenerates only one level and leaves the rest where it was', function () {
    $t = newTimetable($this, 'lecture');
    $svc = app(TimetableService::class);
    $svc->generate($t, $this->admin);
    $before = app(TimetableRuns::class)->current($t);
    $p = $svc->problem($t);

    $runs = app(TimetableRuns::class)->queue($t, $this->admin, [['algorithm' => 'thorough', 'seed' => 4242]], ['level' => 200]);
    $after = $runs[0]->fresh()->assignment;

    foreach ($before as $unit => $a) {
        if ((int) \App\Models\Course::find($t->items()->find($p->units[$unit]['item'])->course_id)->level === 100) {
            expect($after[$unit]['slots'])->toBe($a['slots']);
        }
    }
    expect(count($after))->toBe(count($before));
});

it('places high-priority courses in the earlier periods and keeps to the most periods a day', function () {
    $t = newTimetable($this, 'lecture');
    $c = $t->config;
    $c['max_per_day'] = 2;
    $t->update(['config' => $c]);
    $t->items()->where('course_id', $this->courses['CSC 101']->id)->update(['priority' => 5]);
    $svc = app(TimetableService::class);
    $svc->generate($t, $this->admin);

    $first = $t->entries()->where('course_id', $this->courses['CSC 101']->id)->first();
    expect(substr($first->starts_at, 0, 5))->toBeLessThanOrEqual('10:00')
        ->and($t->fresh()->summary['analytics']['classes']['max_per_day'])->toBeLessThanOrEqual(4);
});

it('saves the answers as a template and starts the wizard from it', function () {
    $this->actingAs($this->admin)->postJson(route('timetables.wizard.templates.store'), wizardAnswers($this, ['template_name' => 'Standard week', 'day_start' => '07:30']))
        ->assertOk()->assertJson(['name' => 'Standard week']);
    $tpl = TimetableTemplate::firstWhere('name', 'Standard week');
    expect($tpl->settings['day_start'])->toBe('07:30')->and($tpl->settings)->not->toHaveKey('name');

    $this->get(route('timetables.wizard', ['template' => $tpl->id]))->assertOk()->assertSee('07:30');
});

it('compares a timetable with another version and restores its placement', function () {
    $svc = app(TimetableService::class);
    $old = newTimetable($this, 'lecture', ['name' => 'Old draft']);
    $svc->generate($old, $this->admin);
    $new = newTimetable($this, 'lecture', ['name' => 'New draft']);
    $c = $new->config;
    $c['seed'] = 999;
    $new->update(['config' => $c]);
    $svc->generate($new, $this->admin);

    $this->actingAs($this->admin)->get(route('timetables.versions', [$new, 'with' => $old->id]))->assertOk()->assertSee('Old draft');
    $this->post(route('timetables.versions.restore', [$new, $old]))->assertRedirect();

    $slots = fn (Timetable $t) => $t->entries()->get()->mapWithKeys(fn ($e) => [$e->course_id . '-' . $e->unit_key => $e->slots])->sortKeys()->values()->all();
    $byCourse = fn (Timetable $t) => $t->entries()->get()->groupBy('course_id')->map(fn ($r) => $r->pluck('slots')->sort()->values()->all())->sortKeys()->all();
    expect($byCourse($new->fresh()))->toBe($byCourse($old));
});

it('keeps the wizard to those who prepare timetables', function () {
    $student = \App\Models\Student::first()->user;
    $this->actingAs($student)->get(route('timetables.wizard'))->assertForbidden();
    $this->actingAs($this->admin)->get(route('timetables.wizard'))->assertOk()->assertSee('Timetable generation wizard');
});
