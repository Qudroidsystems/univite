<?php

use App\Models\AcademicSession;
use App\Models\Applicant;
use App\Models\Course;
use App\Models\CourseResult;
use App\Models\Department;
use App\Models\Faculty;
use App\Models\GradeBand;
use App\Models\Invoice;
use App\Models\Programme;
use App\Models\ProgrammeCourse;
use App\Models\RegistrationForm;
use App\Models\Semester;
use App\Models\Setting;
use App\Models\Student;
use App\Models\User;
use App\Services\AdmissionService;
use App\Support\GradeScale;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    $this->admin = User::where('email', env('ADMIN_EMAIL', 'eliabsiji@gmail.com'))->firstOrFail();
    $faculty = Faculty::create(['name' => 'Faculty of Education', 'code' => 'EDU']);
    $this->dept = Department::create(['faculty_id' => $faculty->id, 'name' => 'Science Education', 'code' => 'SED']);
    $this->programme = Programme::create(['department_id' => $this->dept->id, 'name' => 'B.Ed. Biology', 'code' => 'BED-BIO', 'degree_type' => 'B.Ed', 'duration_years' => 4]);
    $this->session = AcademicSession::create(['name' => '2026/2027', 'is_current' => true]);
    $this->semester = Semester::create(['academic_session_id' => $this->session->id, 'name' => 'First Semester', 'sort_order' => 1, 'is_current' => true]);

    // 8 courses x 3 units on 100 level first semester, one of them compulsory
    $this->courses = collect(range(1, 8))->map(function ($i) {
        $c = Course::create(['department_id' => $this->dept->id, 'code' => "BIO 10$i", 'title' => "Biology $i", 'credit_units' => 3, 'level' => 100]);
        ProgrammeCourse::create(['programme_id' => $this->programme->id, 'course_id' => $c->id, 'level' => 100, 'semester_order' => 1, 'status' => $i === 1 ? 'required' : 'core']);

        return $c;
    });

    $a = Applicant::create([
        'first_name' => 'Tola', 'last_name' => 'Adeyemi', 'email' => 'tola@example.com', 'jamb_reg_no' => '12345678AB', 'jamb_score' => 200,
        'programme_id' => $this->programme->id, 'academic_session_id' => $this->session->id, 'entry_mode' => 'utme', 'entry_level' => 100,
        'status' => 'pending', 'password' => 'my-secret-pw',
    ]);
    $a->update(['application_no' => 'APP/2026/0001']);
    $this->student = app(AdmissionService::class)->admit($a->fresh(), 'First Batch')->student;
    $this->student->update(['needs_onboarding' => false, 'matric_number' => '2026/BED/0001']);
});

function ids(int ...$n): array
{
    return collect($n)->map(fn ($i) => test()->courses[$i - 1]->id)->all();
}

function regAs(): \Illuminate\Foundation\Testing\TestCase
{
    return test()->actingAs(test()->student->user->fresh());
}

it('grades scores from the configured scale and computes GPA and class', function () {
    expect(GradeScale::grade(72))->toMatchArray(['grade' => 'A', 'points' => 5.0, 'is_pass' => true])
        ->and(GradeScale::grade(39.9))->toMatchArray(['grade' => 'F', 'is_pass' => false])
        ->and(GradeScale::grade(null))->toBeNull();

    GradeBand::where('letter', 'E')->update(['min_score' => 45, 'max_score' => 49.99]);
    GradeBand::where('letter', 'D')->delete();
    GradeScale::flush();
    expect(GradeScale::grade(46)['grade'])->toBe('E');

    $r = fn ($pts, $u) => new CourseResult(['grade' => 'X', 'points' => $pts, 'credit_units' => $u]);
    expect(GradeScale::gpa([$r(5, 3), $r(3, 3), $r(4, 2)]))->toBe(4.0)
        ->and(GradeScale::classOf(4.6))->toBe('First Class')->and(GradeScale::classOf(3.6))->toBe('Second Class Upper');
});

it('manages the grade scale and refuses overlapping bands', function () {
    $this->actingAs($this->admin)->get(route('academics.grade-bands.index'))->assertOk()->assertSee('Excellent');
    $this->post(route('academics.grade-bands.store'), ['letter' => 'A+', 'min_score' => 65, 'max_score' => 75, 'points' => 5])->assertSessionHasErrors('max_score');

    GradeBand::query()->delete();
    $this->get(route('academics.grade-bands.index'))->assertSee('The scale has gaps');
});

it('offers the curriculum for the student\'s level and semester, and lets a student save and submit within unit limits', function () {
    Setting::put('registration.require_approval', '1');
    regAs()->get(route('my.registration'))->assertOk()->assertSee('BIO 101')->assertSee('BIO 108');

    // 5 courses = 15 units (min), includes the compulsory one
    $this->post(route('my.registration.save'), ['courses' => ids(1, 2, 3, 4, 5), 'submit' => 1])->assertSessionHas('success');
    $form = RegistrationForm::firstOrFail();
    expect($form->status)->toBe('submitted')->and($form->total_units)->toBe(15);

    // locked once submitted
    $this->post(route('my.registration.save'), ['courses' => ids(1, 2)])->assertSessionHas('error');
});

it('enforces the minimum, maximum and compulsory courses', function () {
    regAs()->post(route('my.registration.save'), ['courses' => ids(1, 2), 'submit' => 1])->assertSessionHas('error', fn ($m) => str_contains($m, 'minimum is 15'));
    $this->post(route('my.registration.save'), ['courses' => ids(2, 3, 4, 5, 6), 'submit' => 1])->assertSessionHas('error', fn ($m) => str_contains($m, 'Compulsory courses missing: BIO 101'));
    Setting::put('registration.max_units', '21');
    $this->post(route('my.registration.save'), ['courses' => ids(1, 2, 3, 4, 5, 6, 7, 8), 'submit' => 1])->assertSessionHas('error', fn ($m) => str_contains($m, 'maximum is 21'));
});

it('rejects a course that is not offered to the student', function () {
    $other = Course::create(['department_id' => $this->dept->id, 'code' => 'PHY 301', 'title' => 'Physics', 'credit_units' => 3]);
    regAs()->post(route('my.registration.save'), ['courses' => [$other->id]])->assertSessionHas('error');
});

it('forces carry-overs of failed courses and locks courses whose prerequisites are not passed', function () {
    $spring = Course::create(['department_id' => $this->dept->id, 'code' => 'BIO 099', 'title' => 'Remedial Biology', 'credit_units' => 3, 'level' => 100]);
    ProgrammeCourse::create(['programme_id' => $this->programme->id, 'course_id' => $spring->id, 'level' => 200, 'semester_order' => 1, 'status' => 'core']);
    $this->student->update(['current_level' => 200]);
    $c200 = Course::create(['department_id' => $this->dept->id, 'code' => 'BIO 201', 'title' => 'Genetics', 'credit_units' => 15, 'level' => 200]);
    ProgrammeCourse::create(['programme_id' => $this->programme->id, 'course_id' => $c200->id, 'level' => 200, 'semester_order' => 1, 'status' => 'core']);
    $c200->prerequisites()->attach($this->courses[1]->id);   // needs BIO 102

    // last year: failed BIO 102 (carry-over), passed BIO 101 (never offered again)
    $prev = AcademicSession::create(['name' => '2025/2026']);
    $type = \App\Models\SemesterType::where('sort_order', 1)->first();
    foreach ([[1, true], [2, false]] as [$n, $pass]) {
        CourseResult::create(['student_id' => $this->student->id, 'course_id' => $this->courses[$n - 1]->id, 'academic_session_id' => $prev->id, 'semester_type_id' => $type->id,
            'total' => $pass ? 60 : 20, 'grade' => $pass ? 'B' : 'F', 'points' => $pass ? 4 : 0, 'is_pass' => $pass, 'credit_units' => 3, 'status' => 'published']);
    }

    regAs()->get(route('my.registration'))->assertSee('Carry-over')->assertSee('Needs BIO 102 first.')->assertDontSee('BIO 101');

    // BIO 201 is locked by its prerequisite
    $this->post(route('my.registration.save'), ['courses' => [$c200->id]])->assertSessionHas('error', fn ($m) => str_contains($m, 'BIO 201'));

    // not registering the carry-over blocks submission
    Setting::put('registration.min_units', '0');
    $this->post(route('my.registration.save'), ['courses' => [$spring->id], 'submit' => 1])->assertSessionHas('error', fn ($m) => str_contains($m, 'Carry-over courses must be registered: BIO 102'));
    $this->post(route('my.registration.save'), ['courses' => [$this->courses[1]->id, $spring->id], 'submit' => 1])->assertSessionHas('success');
});

it('blocks registration by status, when closed, and when new students are not cleared', function () {
    $this->student->user->update([]);
    \App\Models\StudentStatus::where('code', 'suspended')->update(['can_register' => false]);
    \App\Models\StudentStatus::flush();
    $this->student->update(['status' => 'suspended']);
    regAs()->post(route('my.registration.save'), ['courses' => ids(1, 2, 3, 4, 5)])->assertSessionHas('error', fn ($m) => str_contains($m, 'does not allow'));

    $this->student->update(['status' => 'active']);
    Setting::put('registration.open', '0');
    $this->post(route('my.registration.save'), ['courses' => ids(1, 2, 3, 4, 5)])->assertSessionHas('error', fn ($m) => str_contains($m, 'closed'));

    Setting::put('registration.open', '1');
    $this->student->update(['needs_onboarding' => true, 'cleared_at' => null]);
    $this->post(route('my.registration.save'), ['courses' => ids(1, 2, 3, 4, 5)])->assertSessionHas('error', fn ($m) => str_contains($m, 'clearance'));
});

it('checks fees only when the admin turns the fee rule on', function () {
    Invoice::create(['reference' => 'INV-1', 'kind' => 'fees', 'payable_type' => Student::class, 'payable_id' => $this->student->id, 'academic_session_id' => $this->session->id,
        'title' => 'Fees', 'total' => 100000, 'amount_paid' => 20000, 'status' => 'partial', 'installment_plan' => [50000, 50000]]);

    regAs()->post(route('my.registration.save'), ['courses' => ids(1, 2, 3, 4, 5)])->assertSessionHas('success');   // rule is off

    Setting::put('registration.fee_rule', 'first_instalment');
    $this->post(route('my.registration.save'), ['courses' => ids(1, 2, 3, 4, 5)])->assertSessionHas('error', fn ($m) => str_contains($m, 'school fees'));

    Invoice::query()->update(['amount_paid' => 50000]);
    $this->post(route('my.registration.save'), ['courses' => ids(1, 2, 3, 4, 5)])->assertSessionHas('success');

    Setting::put('registration.fee_rule', 'full');
    $this->post(route('my.registration.save'), ['courses' => ids(1, 2, 3, 4, 5)])->assertSessionHas('error');
});

it('lets staff approve, return and reopen forms, and prints approved ones for the student', function () {
    regAs()->post(route('my.registration.save'), ['courses' => ids(1, 2, 3, 4, 5), 'submit' => 1]);
    $form = RegistrationForm::firstOrFail();

    $this->actingAs($this->admin)->get(route('registrations.index'))->assertOk()->assertSee('Adeyemi Tola')->assertSee('Awaiting approval');
    $this->post(route('registrations.decide', $form->id), ['decision' => 'return'])->assertSessionHas('error');   // reason required
    $this->post(route('registrations.decide', $form->id), ['decision' => 'return', 'note' => 'Take BIO 106 as well'])->assertSessionHas('success');
    expect($form->fresh()->status)->toBe('rejected');

    regAs()->get(route('my.registration'))->assertSee('Take BIO 106 as well');
    $this->post(route('my.registration.save'), ['courses' => ids(1, 2, 3, 4, 5, 6), 'submit' => 1])->assertSessionHas('success');

    $this->actingAs($this->admin)->post(route('registrations.bulk'), ['forms' => [$form->id]])->assertSessionHas('success');
    expect($form->fresh()->status)->toBe('approved');

    regAs()->get(route('my.registration.print', $form->id))->assertOk()->assertSee('Course registration form');
    $this->actingAs($this->admin)->post(route('registrations.reopen', $form->id));
    expect($form->fresh()->status)->toBe('draft');
});

it('approves straight away when approval is switched off', function () {
    Setting::put('registration.require_approval', '0');
    regAs()->post(route('my.registration.save'), ['courses' => ids(1, 2, 3, 4, 5), 'submit' => 1])->assertSessionHas('success', 'Your registration is complete.');
    expect(RegistrationForm::first()->status)->toBe('approved');
});

it('saves registration settings and stores prerequisites on a course', function () {
    $this->actingAs($this->admin)->put(route('registrations.settings.update'), ['min_units' => 12, 'max_units' => 30, 'fee_rule' => 'full', 'open' => 1])->assertSessionHas('success');
    expect(Setting::get('registration.max_units'))->toBe('30')->and(Setting::get('registration.fee_rule'))->toBe('full')->and(Setting::get('registration.require_approval'))->toBe('0');

    $this->put(route('registrations.settings.update'), ['min_units' => 20, 'max_units' => 10, 'fee_rule' => 'none'])->assertSessionHasErrors('max_units');

    $c = $this->courses[1];
    $this->put(route('academics.courses.update', $c->id), ['department_id' => $this->dept->id, 'code' => $c->code, 'title' => $c->title, 'credit_units' => 3, 'prerequisites' => 'bio 101'])->assertSessionHasNoErrors();
    expect($c->fresh()->prerequisites->pluck('code')->all())->toBe(['BIO 101']);
    $this->put(route('academics.courses.update', $c->id), ['department_id' => $this->dept->id, 'code' => $c->code, 'title' => $c->title, 'credit_units' => 3, 'prerequisites' => 'NOPE 999'])->assertSessionHasErrors('prerequisites');
});

it('uses the department\'s credit load for the student\'s level and semester', function () {
    $rules = app(\App\Support\AcademicRules::class);
    $type = \App\Models\SemesterType::where('sort_order', 1)->first();
    $rules->save('department', $this->dept->id, 100, $type->id, ['min_units' => 6, 'max_units' => 9], null);

    regAs()->post(route('my.registration.save'), ['courses' => ids(1, 2, 3, 4), 'submit' => 1])->assertSessionHas('error', fn ($m) => str_contains($m, 'maximum is 9'));
    $this->post(route('my.registration.save'), ['courses' => ids(1, 2), 'submit' => 1])->assertSessionHas('success');

    // another level is untouched: the university's 15 still applies there
    $reg = app(\App\Services\RegistrationService::class);
    expect($reg->limits($this->student->fresh(), $reg->context(), 200)->min)->toBe(15);
});

it('only forces a failed elective back when the rules say so', function () {
    $elective = Course::create(['department_id' => $this->dept->id, 'code' => 'BIO 150', 'title' => 'Field Trip', 'credit_units' => 2, 'level' => 100]);
    ProgrammeCourse::create(['programme_id' => $this->programme->id, 'course_id' => $elective->id, 'level' => 100, 'semester_order' => 1, 'status' => 'elective']);
    $this->student->update(['current_level' => 200]);
    $prev = AcademicSession::create(['name' => '2025/2026']);
    $type = \App\Models\SemesterType::where('sort_order', 1)->first();
    CourseResult::create(['student_id' => $this->student->id, 'course_id' => $elective->id, 'academic_session_id' => $prev->id, 'semester_type_id' => $type->id,
        'total' => 20, 'grade' => 'F', 'points' => 0, 'is_pass' => false, 'credit_units' => 2, 'status' => 'published']);
    $c200 = Course::create(['department_id' => $this->dept->id, 'code' => 'BIO 201', 'title' => 'Genetics', 'credit_units' => 3, 'level' => 200]);
    ProgrammeCourse::create(['programme_id' => $this->programme->id, 'course_id' => $c200->id, 'level' => 200, 'semester_order' => 1, 'status' => 'core']);
    Setting::put('registration.min_units', '0');

    regAs()->post(route('my.registration.save'), ['courses' => [$c200->id], 'submit' => 1])->assertSessionHas('error', fn ($m) => str_contains($m, 'BIO 150'));

    app(\App\Support\AcademicRules::class)->save('programme', $this->programme->id, null, null, ['retake_failed_electives' => false], null);
    $this->post(route('my.registration.save'), ['courses' => [$c200->id], 'submit' => 1])->assertSessionHas('success');
});

it('enforces the semester registration window and an optional late period', function () {
    $reg = app(\App\Services\RegistrationService::class);
    $this->semester->update(['registration_opens_at' => '2026-09-10 08:00:00', 'registration_closes_at' => '2026-10-10 17:00:00']);
    $blockers = fn () => implode(' ', $reg->blockers($this->student->fresh()));

    $this->travelTo(\Carbon\Carbon::parse('2026-09-01 09:00'));
    expect($blockers())->toContain('Registration opens on');

    $this->travelTo(\Carbon\Carbon::parse('2026-09-20 09:00'));
    expect($blockers())->not->toContain('Registration opens')->not->toContain('Registration closed');

    $this->travelTo(\Carbon\Carbon::parse('2026-10-15 09:00'));
    expect($blockers())->toContain('Registration closed on');

    Setting::put('session.late_registration', '1');
    Setting::put('session.late_registration_days', '14');
    expect($blockers())->not->toContain('Registration closed');

    $this->travelTo(\Carbon\Carbon::parse('2026-11-15 09:00'));
    expect($blockers())->toContain('Registration closed on');
});
