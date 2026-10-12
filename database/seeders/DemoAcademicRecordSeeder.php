<?php

namespace Database\Seeders;

use App\Models\AcademicSession;
use App\Models\CourseAssignment;
use App\Models\ProgrammeCourse;
use App\Models\RegistrationForm;
use App\Models\ResultSheet;
use App\Models\SemesterType;
use App\Models\Student;
use App\Support\GradeScale;
use Database\Seeders\Demo\DemoPeople;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Sample data, part 4: every student's course registration for each semester since they entered, and published
 * results for every finished semester (each student has a steady ability, so GPAs and CGPAs look believable and a few
 * courses are failed). For the semester running now, most forms are approved, some wait for approval, and some
 * students have not registered yet; there are no results for it.
 */
class DemoAcademicRecordSeeder extends Seeder
{
    public function run(): void
    {
        $people = new DemoPeople(4401);
        $sessions = AcademicSession::orderBy('name')->get()->values();
        $current = AcademicSession::current() ?? $sessions->last();
        $currentIndex = $sessions->search(fn ($s) => $s->id === $current->id);
        $types = SemesterType::orderBy('sort_order')->get()->keyBy('sort_order');
        $curriculum = ProgrammeCourse::with('course')->get()->groupBy(fn ($pc) => "{$pc->programme_id}|{$pc->level}|{$pc->semester_order}");
        $lecturers = CourseAssignment::pluck('user_id', 'course_id');
        $sheets = [];
        GradeScale::flush();

        foreach (Student::where('needs_onboarding', false)->orderBy('id')->get() as $student) {
            $ability = $people->int(42, 80);
            $entryIndex = $sessions->search(fn ($s) => $s->id === $student->academic_session_id);
            for ($si = $entryIndex; $si <= $currentIndex; $si++) {
                $session = $sessions[$si];
                $level = $student->entry_level + ($si - $entryIndex) * 100;
                foreach ([1, 2] as $order) {
                    $now = $si === $currentIndex;
                    if ($now && $order === 2) {
                        continue;   // the second semester has not started
                    }
                    $courses = $curriculum->get("{$student->programme_id}|{$level}|{$order}", collect());
                    if ($courses->isEmpty() || RegistrationForm::where(['student_id' => $student->id, 'academic_session_id' => $session->id, 'semester_type_id' => $types[$order]->id])->exists()) {
                        continue;
                    }
                    $status = 'approved';
                    if ($now) {
                        $roll = $people->int(1, 100);
                        if ($roll <= 15) {
                            continue;   // not registered yet
                        }
                        $status = $roll <= 30 ? 'submitted' : 'approved';
                    }
                    $year = (int) substr($session->name, 0, 4);
                    $submitted = $order === 1 ? now()->setDate($year, 10, $people->int(10, 28)) : now()->setDate($year + 1, 3, $people->int(5, 25));
                    if ($submitted->isFuture()) {
                        $submitted = now()->subDays($people->int(1, 10));
                    }
                    $form = RegistrationForm::create([
                        'student_id' => $student->id, 'academic_session_id' => $session->id, 'semester_type_id' => $types[$order]->id, 'level' => $level, 'status' => $status,
                        'total_units' => $courses->sum(fn ($pc) => $pc->course->credit_units), 'submitted_at' => $submitted,
                        'decided_at' => $status === 'approved' ? $submitted->copy()->addDays($people->int(1, 7)) : null,
                    ]);
                    DB::table('course_registrations')->insert($courses->map(fn ($pc) => [
                        'registration_form_id' => $form->id, 'student_id' => $student->id, 'course_id' => $pc->course_id, 'credit_units' => $pc->course->credit_units,
                        'is_carryover' => false, 'course_status' => $pc->status, 'created_at' => $submitted, 'updated_at' => $submitted,
                    ])->all());

                    if ($now) {
                        continue;
                    }
                    $published = $order === 1 ? now()->setDate($year + 1, 3, 20) : now()->setDate($year + 1, 9, 15);
                    $rows = [];
                    foreach ($courses as $pc) {
                        $key = "{$pc->course_id}|{$session->id}|{$order}";
                        $sheets[$key] ??= ResultSheet::create([
                            'course_id' => $pc->course_id, 'academic_session_id' => $session->id, 'semester_type_id' => $types[$order]->id, 'status' => 'published',
                            'submitted_by' => $lecturers[$pc->course_id] ?? null, 'submitted_at' => $published->copy()->subWeeks(4), 'published_at' => $published,
                        ])->id;
                        $total = max(12, min(96, $ability + $people->int(-16, 14)));
                        $ca = min(40, max(5, (int) round($total * 0.4) + $people->int(-4, 4)));
                        $exam = max(0, min(60, $total - $ca));
                        $total = $ca + $exam;
                        $g = GradeScale::grade($total);
                        $rows[] = [
                            'result_sheet_id' => $sheets[$key], 'student_id' => $student->id, 'course_id' => $pc->course_id, 'academic_session_id' => $session->id,
                            'semester_type_id' => $types[$order]->id, 'ca' => $ca, 'exam' => $exam, 'total' => $total, 'grade' => $g['grade'] ?? null,
                            'points' => $g['points'] ?? null, 'is_pass' => $g['is_pass'] ?? false, 'credit_units' => $pc->course->credit_units, 'status' => 'published',
                            'created_at' => $published, 'updated_at' => $published,
                        ];
                    }
                    DB::table('course_results')->insert($rows);
                }
            }
        }
    }
}
