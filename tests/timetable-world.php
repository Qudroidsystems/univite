<?php

// Shared by the timetable, seating and venue tests.

use App\Models\AcademicSession;
use App\Models\Course;
use App\Models\CourseRegistration;
use App\Models\Department;
use App\Models\Faculty;
use App\Models\Programme;
use App\Models\ProgrammeCourse;
use App\Models\RegistrationForm;
use App\Models\SemesterType;
use App\Models\Student;
use App\Models\Timetable;
use App\Models\User;
use App\Models\Venue;
use App\Services\TimetableService;
use Database\Seeders\DatabaseSeeder;

/** A small faculty: two programmes, 100 and 200 level, students registered for their courses, five venues. */
function timetableWorld($test): void
{
    $test->seed(DatabaseSeeder::class);
    $test->admin = User::where('email', env('ADMIN_EMAIL', 'eliabsiji@gmail.com'))->firstOrFail();
    $faculty = Faculty::create(['name' => 'Faculty of Science', 'code' => 'SCI']);
    $test->dept = Department::create(['faculty_id' => $faculty->id, 'name' => 'Computer Science', 'code' => 'CSC']);
    $test->session = AcademicSession::create(['name' => '2026/2027', 'is_current' => true]);
    $test->sem = SemesterType::where('sort_order', 1)->first();
    \App\Models\Semester::updateOrCreate(['academic_session_id' => $test->session->id, 'sort_order' => 1], ['name' => 'First Semester', 'is_current' => true]);
    $progs = [
        Programme::create(['department_id' => $test->dept->id, 'name' => 'B.Sc. Computer Science', 'code' => 'CSC', 'degree_type' => 'B.Sc', 'duration_years' => 4]),
        Programme::create(['department_id' => $test->dept->id, 'name' => 'B.Sc. Mathematics', 'code' => 'MTH', 'degree_type' => 'B.Sc', 'duration_years' => 4]),
    ];
    $test->courses = collect();
    foreach ($progs as $pi => $prog) {
        foreach ([100, 200] as $level) {
            foreach (range(1, 4) as $i) {
                $code = ($pi ? 'MTH ' : 'CSC ') . ($level + $i);
                $c = Course::create(['department_id' => $test->dept->id, 'code' => $code, 'title' => "Course {$code}", 'credit_units' => 2, 'level' => $level]);
                ProgrammeCourse::create(['programme_id' => $prog->id, 'course_id' => $c->id, 'level' => $level, 'semester_order' => 1, 'status' => 'compulsory']);
                $test->courses[$code] = $c;
            }
            // 30 students per programme-level, registered for its four courses
            foreach (range(1, 30) as $n) {
                $u = User::create(['name' => "S{$n} {$prog->code}{$level}", 'email' => strtolower("s{$n}{$prog->code}{$level}@example.com"), 'password' => 'secret-pass']);
                $s = Student::create(['user_id' => $u->id, 'first_name' => "S{$n}", 'last_name' => $prog->code . $level, 'programme_id' => $prog->id, 'academic_session_id' => $test->session->id, 'entry_mode' => 'utme', 'current_level' => $level, 'entry_level' => 100, 'status' => 'active', 'matric_number' => "{$prog->code}/{$level}/" . str_pad($n, 3, '0', STR_PAD_LEFT)]);
                $f = RegistrationForm::create(['student_id' => $s->id, 'academic_session_id' => $test->session->id, 'semester_type_id' => $test->sem->id, 'level' => $level, 'status' => 'approved']);
                foreach (range(1, 4) as $i) {
                    $code = ($pi ? 'MTH ' : 'CSC ') . ($level + $i);
                    CourseRegistration::create(['registration_form_id' => $f->id, 'student_id' => $s->id, 'course_id' => $test->courses[$code]->id, 'credit_units' => 2]);
                }
            }
        }
    }
    // CSC 101 is also taken by the mathematics 100 level students (a cross-listed course)
    foreach (Student::where('last_name', 'MTH100')->get() as $s) {
        CourseRegistration::create(['registration_form_id' => RegistrationForm::where('student_id', $s->id)->value('id'), 'student_id' => $s->id, 'course_id' => $test->courses['CSC 101']->id, 'credit_units' => 2]);
    }
    $test->venues = collect([
        Venue::create(['code' => 'LT1', 'name' => 'Lecture Theatre 1', 'type' => 'lecture_hall', 'capacity' => 120, 'teaching_capacity' => 120, 'exam_capacity' => 60, 'building' => 'Science Block']),
        Venue::create(['code' => 'CR1', 'name' => 'Classroom 1', 'type' => 'classroom', 'capacity' => 40, 'teaching_capacity' => 40, 'exam_capacity' => 20, 'building' => 'Science Block']),
        Venue::create(['code' => 'CR2', 'name' => 'Classroom 2', 'type' => 'classroom', 'capacity' => 40, 'teaching_capacity' => 40, 'exam_capacity' => 20, 'building' => 'Annex']),
        Venue::create(['code' => 'HALL', 'name' => 'Main Examination Hall', 'type' => 'exam_hall', 'capacity' => 300, 'teaching_capacity' => 0, 'exam_capacity' => 100, 'seat_rows' => 10, 'seats_per_row' => 10, 'uses' => ['exam'], 'shared_exams' => true]),
        Venue::create(['code' => 'LAB1', 'name' => 'Computer Lab', 'type' => 'computer_lab', 'capacity' => 35, 'teaching_capacity' => 35, 'exam_capacity' => 0, 'facilities' => ['computers'], 'uses' => ['lecture', 'practical']]),
    ]);
}

function newTimetable($test, string $kind, array $extra = []): Timetable
{
    return app(TimetableService::class)->create(array_merge([
        'kind' => $kind, 'name' => $kind === 'exam' ? 'First semester examinations' : 'First semester lectures',
        'academic_session_id' => $test->session->id, 'semester_type_id' => $test->sem->id,
        'start_date' => '2026-11-16', 'end_date' => '2026-11-20',
    ], $extra), $test->admin);
}
