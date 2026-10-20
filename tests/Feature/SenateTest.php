<?php

use App\Models\AcademicSession;
use App\Models\Course;
use App\Models\CourseResult;
use App\Models\Department;
use App\Models\Faculty;
use App\Models\Invoice;
use App\Models\Programme;
use App\Models\ProgrammeCourse;
use App\Models\RegistrationForm;
use App\Models\ResultSheet;
use App\Models\Semester;
use App\Models\SemesterType;
use App\Models\SenateDecision;
use App\Models\Setting;
use App\Models\User;
use App\Support\AcademicRules;
use App\Support\GradeScale;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    $this->admin = User::where('email', env('ADMIN_EMAIL', 'eliabsiji@gmail.com'))->firstOrFail();
    $f = Faculty::create(['name' => 'Faculty of Computing', 'code' => 'FCP']);
    $this->dept = Department::create(['faculty_id' => $f->id, 'name' => 'Computer Science', 'code' => 'CSC']);
    $this->prog = Programme::create(['department_id' => $this->dept->id, 'name' => 'B.Sc. Computer Science', 'code' => 'BSC-CSC', 'degree_type' => 'B.Sc', 'duration_years' => 4]);
    $this->session = AcademicSession::create(['name' => '2026/2027', 'is_current' => true]);
    Semester::create(['academic_session_id' => $this->session->id, 'name' => 'First Semester', 'sort_order' => 1, 'is_current' => true]);
    $this->type = SemesterType::where('sort_order', 1)->first();
    $this->courses = collect([['CSC 401', 'compulsory'], ['CSC 403', 'compulsory'], ['CSC 405', 'elective']])->map(function ($c) {
        $course = Course::create(['department_id' => $this->dept->id, 'code' => $c[0], 'title' => "Course {$c[0]}", 'credit_units' => 3, 'level' => 400]);
        ProgrammeCourse::create(['programme_id' => $this->prog->id, 'course_id' => $course->id, 'level' => 400, 'semester_order' => 1, 'status' => $c[1]]);

        return $course;
    });
    app(AcademicRules::class)->save('programme', $this->prog->id, null, null, ['graduation_units' => 6, 'senate_levels' => '300, 400', 'min_units' => 0], $this->admin->id);
    Setting::put('senate.min_takers', '2');
    foreach ($this->courses as $c) {
        ResultSheet::create(['course_id' => $c->id, 'academic_session_id' => $this->session->id, 'semester_type_id' => $this->type->id, 'status' => 'approved', 'submitted_at' => now()]);
    }
});

/** A student at $level with these totals (course index => total) this session, results approved but not yet published. */
function senateStudent(string $name, int $level, array $totals): \App\Models\Student
{
    $s = makeStudent(test(), $name, 'female', $level, test()->prog, ['entry_level' => 100]);
    $form = RegistrationForm::create(['student_id' => $s->id, 'academic_session_id' => test()->session->id, 'semester_type_id' => test()->type->id, 'level' => $level, 'status' => 'approved', 'total_units' => count($totals) * 3]);
    foreach ($totals as $i => $total) {
        $c = test()->courses[$i];
        $form->courses()->create(['student_id' => $s->id, 'course_id' => $c->id, 'credit_units' => 3, 'course_status' => $i === 2 ? 'elective' : 'compulsory']);
        if ($total !== null) {
            $g = GradeScale::grade($total);
            CourseResult::create(['result_sheet_id' => ResultSheet::where('course_id', $c->id)->value('id'), 'student_id' => $s->id, 'course_id' => $c->id,
                'academic_session_id' => test()->session->id, 'semester_type_id' => test()->type->id, 'ca' => 30, 'exam' => $total - 30, 'total' => $total,
                'grade' => $g['grade'], 'points' => $g['points'], 'is_pass' => $g['is_pass'], 'credit_units' => 3, 'status' => 'approved']);
        }
    }

    return $s;
}

it('sorts the Senate levels into eligible, not eligible and needing a decision, with the reasons', function () {
    $good = senateStudent('Ada', 400, [0 => 75, 1 => 72, 2 => 70]);
    $failed = senateStudent('Bayo', 400, [0 => 75, 1 => 30, 2 => 70]);
    $owing = senateStudent('Chi', 400, [0 => 75, 1 => 72, 2 => 70]);
    Invoice::create(['reference' => 'INV-X1', 'kind' => 'fees', 'payable_type' => \App\Models\Student::class, 'payable_id' => $owing->id, 'academic_session_id' => $this->session->id,
        'title' => 'Fees', 'total' => 50000, 'amount_paid' => 0, 'status' => 'unpaid']);
    $missing = senateStudent('Dupe', 300, [0 => 60, 1 => null]);
    senateStudent('Efe', 200, [0 => 60]);   // 200 level: not a Senate level

    $page = $this->actingAs($this->admin)->get(route('senate.index'))->assertOk()
        ->assertDontSee('Student Efe')->assertSee('Student Ada')->assertSee('Student Bayo')->assertSee('Student Chi')->assertSee('Student Dupe')
        ->assertSee('Failed and not yet passed: CSC 403')->assertSee('of the 2026/2027 fees')->assertSee('No result yet for: CSC 403');

    $review = app(\App\Services\Senate\SenateReview::class);
    $rows = $review->students(new \App\Data\Senate\SenateQuery($this->session), \App\Data\Access\AccessScope::everything())->keyBy(fn ($r) => $r->student->first_name);
    expect($rows['Ada']->status())->toBe('eligible')->and($rows['Ada']->class)->toBe('First Class')
        ->and($rows['Bayo']->status())->toBe('not_eligible')
        ->and($rows['Chi']->status())->toBe('needs_decision')
        ->and($rows['Dupe']->status())->toBe('not_eligible')->and($rows['Dupe']->final)->toBeFalse()
        ->and($rows->has('Efe'))->toBeFalse();

    // filter by finding
    $this->get(route('senate.index', ['flag' => 'fees']))->assertSee('Student Chi')->assertDontSee('Student Bayo');
    $this->get(route('senate.index', ['status' => 'eligible']))->assertSee('Student Ada')->assertDontSee('Student Chi');
});

it('flags a CGPA close to a class boundary and a score amended after publication', function () {
    expect(app(\App\Services\Senate\SenateReview::class)->boundary(3.47))->toContain('0.03 below Second Class Upper');
    $s = senateStudent('Femi', 400, [0 => 75, 1 => 72, 2 => 70]);
    $r = CourseResult::where('student_id', $s->id)->where('course_id', $this->courses[0]->id)->first();
    $r->sheet->update(['status' => 'published']);
    $r->update(['status' => 'published']);
    app(\App\Services\ResultService::class)->amend($r->fresh(), 30, 30, 'Script re-marked', $this->admin);

    $row = app(\App\Services\Senate\SenateReview::class)->student($s->fresh(), 400, new \App\Data\Senate\SenateQuery($this->session));
    expect($row->has('amended'))->toBeTrue()->and($row->status())->toBe('needs_decision');
    $this->actingAs($this->admin)->get(route('senate.index', ['flag' => 'amended']))->assertSee('amended after publication by')->assertSee('Script re-marked');
});

it('checks courses for their grade spread and approval trail', function () {
    senateStudent('Gbenga', 400, [0 => 75, 1 => 72, 2 => 30]);
    senateStudent('Hauwa', 400, [0 => 80, 1 => 45, 2 => 20]);
    $this->actingAs($this->admin)->get(route('senate.index', ['view' => 'courses']))->assertOk()
        ->assertSee('CSC 401')->assertSee('Pass rate 100%')->assertSee('100% of students got A')->assertSee('Pass rate only 0%')->assertSee('Signed at 0 of the');
    $this->get(route('senate.index', ['view' => 'summary']))->assertOk()->assertSee('Faculty of Computing')->assertSee('B.Sc. Computer Science');
});

it('records approve, defer and refer, approves every eligible student at once, and prints every view', function () {
    $good = senateStudent('Ijeoma', 400, [0 => 75, 1 => 72, 2 => 70]);
    $bad = senateStudent('Jide', 400, [0 => 75, 1 => 30, 2 => 70]);
    $this->actingAs($this->admin)->post(route('senate.decide'), ['subject_type' => 'student', 'subject_id' => $bad->id, 'decision' => 'deferred'])->assertSessionHas('error');
    $this->post(route('senate.decide', ['session_id' => $this->session->id]), ['subject_type' => 'student', 'subject_id' => $bad->id, 'decision' => 'referred', 'note' => 'Re-sit CSC 403'])->assertSessionHas('success');
    $this->post(route('senate.approve-eligible', ['session_id' => $this->session->id]), ['note' => 'Senate meeting 12 Nov'])->assertSessionHas('success', '1 eligible student approved.');
    expect(SenateDecision::where('subject_id', $good->id)->value('decision'))->toBe('approved')
        ->and(SenateDecision::where('subject_id', $bad->id)->latest('id')->value('decision'))->toBe('referred');
    $this->post(route('senate.decide', ['session_id' => $this->session->id]), ['subject_type' => 'course', 'subject_id' => $this->courses[0]->id, 'semester_type_id_subject' => $this->type->id, 'decision' => 'approved'])->assertSessionHas('success');

    $this->get(route('senate.index', ['decision' => 'referred']))->assertSee('Student Jide')->assertDontSee('Student Ijeoma');
    foreach (['students', 'courses', 'summary'] as $view) {
        $this->get(route('senate.export', ['format' => 'print', 'view' => $view]))->assertOk()->assertSee('Senate review');
        $this->get(route('senate.export', ['format' => 'excel', 'view' => $view]))->assertOk()->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }
    $pdf = $this->get(route('senate.export', ['format' => 'pdf', 'view' => 'students']))->assertOk()->assertHeader('content-type', 'application/pdf');
    expect(substr($pdf->getContent(), 0, 4))->toBe('%PDF');
});

it('lets Senate members in and keeps everyone else out', function () {
    $other = Faculty::create(['name' => 'Faculty of Arts', 'code' => 'ART']);
    senateStudent('Kemi', 400, [0 => 75, 1 => 72, 2 => 70]);
    $nobody = User::factory()->create();
    $this->actingAs($nobody)->get(route('senate.index'))->assertForbidden();

    $member = User::factory()->create();
    $member->assignRole('Senate');
    $this->actingAs($member->fresh())->get(route('senate.index'))->assertOk()->assertSee('Student Kemi');
});
