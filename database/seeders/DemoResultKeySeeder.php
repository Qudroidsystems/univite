<?php

namespace Database\Seeders;

use App\Data\Results\ComputeQuery;
use App\Models\AcademicSession;
use App\Models\GradeBand;
use App\Models\ResultKey;
use App\Models\ResultSheet;
use App\Models\SemesterType;
use App\Models\User;
use App\Services\Results\ResultComputation;
use Illuminate\Database\Seeder;

/**
 * Sample data, part 6b: ranks on the university's key to grade, a sample key to result, and this semester's final-year
 * results computed for one department, so Compute Results and the master marks sheet have something to show.
 * The ranges are only examples; each institution sets its own under Results, Key to Grade and Key to Result.
 */
class DemoResultKeySeeder extends Seeder
{
    public function run(ResultComputation $computation): void
    {
        $ranks = ['A' => 'Distinction', 'B' => 'Credit', 'C' => 'Merit', 'D' => 'Pass', 'E' => 'Pass', 'F' => 'Fail'];
        foreach (GradeBand::whereNull('department_id')->whereNull('faculty_id')->whereNull('study_category_id')->get() as $band) {
            $band->update(['rank' => $band->rank ?? ($ranks[$band->letter] ?? null)]);
        }
        if (! ResultKey::exists()) {
            foreach ([
                ['PASS', 1.50, 5.00, 'Good standing', 'proceed', 'Register for the next level.'],
                ['CSO', 1.00, 1.49, 'Carry some over', 'carry_over', 'Register the outstanding courses first, with the next level\'s courses.'],
                ['TOS', 0.00, 0.99, 'Probation', 'probation', 'See the level adviser before registering.'],
            ] as [$code, $min, $max, $rank, $action, $activity]) {
                ResultKey::create(['code' => $code, 'min_gpa' => $min, 'max_gpa' => $max, 'rank' => $rank, 'action' => $action, 'activity' => $activity]);
            }
        }

        // Compute the department whose final-year sheets got furthest through approval.
        $session = AcademicSession::where('is_current', true)->first();
        $type = SemesterType::orderBy('sort_order')->first();
        $sheet = ResultSheet::with('course.department')->where('academic_session_id', $session?->id)->where('semester_type_id', $type?->id)
            ->whereIn('status', ['approved', 'submitted'])->orderByRaw("status = 'approved' desc")->first();
        $hod = User::find($sheet?->course?->department?->hod_id) ?? User::role('Super Admin')->orderBy('id')->first();
        if ($sheet && $hod) {
            $computation->compute(new ComputeQuery($sheet->course->department, null, null, (int) $sheet->course->level, $session, $type), $hod);
        }
    }
}
