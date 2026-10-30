<?php

namespace Database\Seeders;

use App\Models\AcademicSession;
use App\Models\AssessmentAssignment;
use App\Models\AssessmentScheme;
use App\Models\Faculty;
use Illuminate\Database\Seeder;

/**
 * Sample assessment schemes for the current session: a university default (two tests, an assignment and the exam) and,
 * for the first faculty, a scheme with a practical. Past sessions keep their plain CA / exam marks.
 */
class DemoAssessmentSeeder extends Seeder
{
    public function run(): void
    {
        $session = AcademicSession::current() ?? AcademicSession::orderByDesc('name')->first();
        if (! $session || AssessmentScheme::exists()) {
            return;
        }

        $make = function (string $name, string $about, array $items): AssessmentScheme {
            $scheme = AssessmentScheme::create(['name' => $name, 'description' => $about, 'is_active' => true]);
            foreach ($items as $n => [$label, $code, $component, $max]) {
                $scheme->items()->create(['name' => $label, 'code' => $code, 'component' => $component, 'max_score' => $max, 'sort_order' => $n + 1]);
            }

            return $scheme;
        };

        $standard = $make('Standard (2 tests, assignment, exam)', 'The usual 30 / 70 split, with the CA broken into three.', [
            ['Test 1', 'T1', 'ca', 10], ['Test 2', 'T2', 'ca', 10], ['Assignment', 'ASG', 'ca', 10], ['Examination', 'EXAM', 'exam', 70],
        ]);
        $practical = $make('With practical (40 / 60)', 'For courses with laboratory or field work.', [
            ['Test', 'T1', 'ca', 15], ['Practical', 'PRAC', 'ca', 20], ['Attendance', 'ATT', 'ca', 5], ['Examination', 'EXAM', 'exam', 60],
        ]);

        AssessmentAssignment::create(['assessment_scheme_id' => $standard->id, 'scope_type' => 'university', 'scope_id' => null, 'academic_session_id' => $session->id]);
        if ($faculty = Faculty::where('code', 'SCI')->first() ?? Faculty::orderBy('id')->first()) {
            AssessmentAssignment::create(['assessment_scheme_id' => $practical->id, 'scope_type' => 'faculty', 'scope_id' => $faculty->id, 'academic_session_id' => $session->id]);
        }
    }
}
