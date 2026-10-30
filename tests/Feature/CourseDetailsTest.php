<?php

use App\Models\AcademicSession;
use App\Models\Course;
use App\Models\CourseOption;
use App\Models\CourseResult;
use App\Models\Department;
use App\Models\Faculty;
use App\Models\Programme;
use App\Models\ProgrammeCourse;
use App\Models\ResultSheet;
use App\Models\ResultSignatory;
use App\Models\Semester;
use App\Models\SemesterType;
use App\Models\User;
use App\Services\RegistrationService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    $this->admin = User::where('email', env('ADMIN_EMAIL', 'eliabsiji@gmail.com'))->firstOrFail();
    $this->faculty = Faculty::create(['name' => 'Faculty of Arts', 'code' => 'ART']);
    $this->dept = Department::create(['faculty_id' => $this->faculty->id, 'name' => 'Theatre Arts', 'code' => 'THA']);
    $this->prog = Programme::create(['department_id' => $this->dept->id, 'name' => 'B.A. Theatre Arts', 'code' => 'BA-THA', 'degree_type' => 'B.A', 'duration_years' => 4]);
    $this->session = AcademicSession::create(['name' => '2026/2027', 'is_current' => true]);
    Semester::create(['academic_session_id' => $this->session->id, 'name' => 'First Semester', 'sort_order' => 1, 'is_current' => true]);
    $this->type = SemesterType::where('sort_order', 1)->first();
    $this->acting = CourseOption::create(['programme_id' => $this->prog->id, 'name' => 'Acting']);
    $this->directing = CourseOption::create(['programme_id' => $this->prog->id, 'name' => 'Directing']);
});

it('marks project and teaching practice courses and limits a course to one option', function () {
    $stage = Course::create(['department_id' => $this->dept->id, 'code' => 'THA 105', 'title' => 'Stage Movement', 'credit_units' => 2, 'level' => 100]);
    $tp = Course::create(['department_id' => $this->dept->id, 'code' => 'THA 109', 'title' => 'Teaching Practice', 'credit_units' => 6, 'level' => 100]);

    $this->actingAs($this->admin)->post(route('academics.curriculum.store'), ['programme_id' => $this->prog->id, 'course_ids' => [$stage->id], 'level' => 100,
        'semester_order' => 1, 'course_option_id' => $this->acting->id])->assertSessionHas('success');
    $this->post(route('academics.curriculum.store'), ['programme_id' => $this->prog->id, 'course_ids' => [$tp->id], 'level' => 100,
        'semester_order' => 1, 'kind' => 'teaching_practice'])->assertSessionHas('success');
    $this->get(route('academics.curriculum.index', ['programme' => $this->prog->id]))->assertOk()->assertSee('Acting only')->assertSee('Teaching practice');

    // another programme's option is refused
    $other = CourseOption::create(['programme_id' => Programme::create(['department_id' => $this->dept->id, 'name' => 'B.A. Music', 'code' => 'BA-MUS', 'degree_type' => 'B.A', 'duration_years' => 4])->id, 'name' => 'Piano']);
    $entry = ProgrammeCourse::where('course_id', $tp->id)->sole();
    $this->put(route('academics.curriculum.update', $entry->id), ['level' => 100, 'semester_order' => 1, 'status' => 'compulsory', 'course_option_id' => $other->id])->assertSessionHasErrors('course_option_id');

    $actor = makeStudent($this, 'Ada', 'female', 100, null, ['course_option_id' => $this->acting->id]);
    $director = makeStudent($this, 'Bayo', 'male', 100, null, ['course_option_id' => $this->directing->id]);
    $svc = app(RegistrationService::class);
    $ctx = $svc->context();
    expect($svc->offered($actor, $ctx)->pluck('course.code')->sort()->values()->all())->toBe(['THA 105', 'THA 109'])
        ->and($svc->offered($director, $ctx)->pluck('course.code')->all())->toBe(['THA 109']);
});

it('clears a failed course once the student passes the course that replaces it', function () {
    $old = Course::create(['department_id' => $this->dept->id, 'code' => 'THA 101', 'title' => 'Old Drama', 'credit_units' => 2, 'level' => 100]);
    $this->actingAs($this->admin)->post(route('academics.courses.store'), ['faculty_id' => $this->faculty->id, 'department_id' => $this->dept->id, 'code' => 'THA 111',
        'title' => 'Introduction to Drama', 'credit_units' => 2, 'course_type' => 'compulsory', 'replaces' => [$old->id], 'is_active' => 1])->assertSessionHasNoErrors();
    $new = Course::where('code', 'THA 111')->sole();
    expect($new->replaces->pluck('code')->all())->toBe(['THA 101']);
    $this->get(route('academics.courses.index'))->assertOk()->assertSee('Replaces THA 101');

    $student = makeStudent($this, 'Uche', 'male', 200);
    $earlier = AcademicSession::create(['name' => '2025/2026']);
    foreach ([[$old, false, 30, 'F'], [$new, true, 65, 'B']] as [$c, $pass, $total, $grade]) {
        $sheet = ResultSheet::create(['course_id' => $c->id, 'academic_session_id' => $earlier->id, 'semester_type_id' => $this->type->id, 'status' => 'published']);
        CourseResult::create(['result_sheet_id' => $sheet->id, 'student_id' => $student->id, 'course_id' => $c->id, 'academic_session_id' => $earlier->id, 'semester_type_id' => $this->type->id,
            'total' => $total, 'grade' => $grade, 'points' => $pass ? 4 : 0, 'is_pass' => $pass, 'credit_units' => 2, 'status' => 'published']);
    }
    $svc = app(RegistrationService::class);
    expect($svc->failed($student)->all())->toBe([])->and($svc->outstanding($student)->all())->toBe([]);
});

it('prints the most specific signatories on the master sheet', function () {
    $this->actingAs($this->admin)->post(route('academics.result-signatories.store'), ['position' => 'Head of Department', 'title' => 'Dr.', 'name' => 'A. General', 'sort_order' => 1, 'is_active' => 1])->assertSessionHasNoErrors();
    $this->post(route('academics.result-signatories.store'), ['department_id' => $this->dept->id, 'position' => 'Head of Department', 'title' => 'Dr.', 'name' => 'J. Edafe', 'address' => 'Department of Theatre Arts', 'sort_order' => 1, 'is_active' => 1])->assertSessionHasNoErrors();
    $this->post(route('academics.result-signatories.store'), ['faculty_id' => $this->faculty->id, 'position' => 'Dean', 'title' => 'Prof.', 'name' => 'K. Bello', 'sort_order' => 2, 'is_active' => 1])->assertSessionHasNoErrors();
    $this->get(route('academics.result-signatories.index'))->assertOk()->assertSee('J. Edafe');

    $names = ResultSignatory::forProgramme($this->prog)->map->fullName()->all();
    expect($names)->toBe(['Dr. J. Edafe', 'Prof. K. Bello']);

    makeStudent($this, 'Ada');
    $this->get(route('results.broadsheet', ['programme_id' => $this->prog->id, 'level' => 100, 'academic_session_id' => $this->session->id, 'semester_type_id' => $this->type->id]))
        ->assertOk()->assertSee('Dr. J. Edafe')->assertSee('Prof. K. Bello')->assertDontSee('A. General');
});
