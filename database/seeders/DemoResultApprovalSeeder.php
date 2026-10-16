<?php

namespace Database\Seeders;

use App\Data\Results\CarryoverWaiverRequest;
use App\Data\Results\Score;
use App\Models\AcademicSession;
use App\Models\CourseAssignment;
use App\Models\CourseResult;
use App\Models\Department;
use App\Models\Faculty;
use App\Models\ProgrammeCourse;
use App\Models\ResultSheet;
use App\Models\ResultStage;
use App\Models\Semester;
use App\Models\SemesterType;
use App\Models\Student;
use App\Models\User;
use App\Services\ResultService;
use App\Services\Results\CarryoverWaivers;
use Database\Seeders\Demo\DemoPeople;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Sample data, part 6: the approval chain Head of Department, Dean, then Senate (with the registrar signing for Senate),
 * this semester's final-year results part-way through it, and a few carry-overs the departments have waived. So the
 * master sheet shows every state: in the first faculty the final-year sheets wait for Senate, in the second for the
 * dean, in the third for the heads of department, and in the fourth they are approved and ready to publish.
 */
class DemoResultApprovalSeeder extends Seeder
{
    public function run(ResultService $results, CarryoverWaivers $waivers, PermissionRegistrar $registrar): void
    {
        config(['mail.default' => 'array']);
        $people = new DemoPeople(7701);
        $admin = User::role('Super Admin')->orderBy('id')->first();

        // The chain: each step signed by its office. The registrar presents results to Senate.
        $senate = Role::firstOrCreate(['name' => 'Senate', 'guard_name' => 'web']);
        $senate->givePermissionTo(['Approve results', 'Publish results', 'View student results', 'View result sheets']);
        User::role('Registrar')->orderBy('id')->first()?->assignRole($senate);
        ResultStage::where('name', 'Head of Department')->update(['office_role' => 'Head of Department']);
        ResultStage::where('name', 'Dean of Faculty')->update(['office_role' => 'Dean']);
        ResultStage::firstOrCreate(['name' => 'Senate'], ['office_role' => 'Senate', 'sort_order' => 3, 'is_active' => true]);
        $registrar->forgetCachedPermissions();

        $semester = Semester::current();
        $session = AcademicSession::current();
        if (! $semester || ! $session) {
            return;
        }
        $type = SemesterType::where('sort_order', $semester->sort_order)->firstOrFail();
        $stopAt = [];   // faculty id => how many steps to approve
        foreach (Faculty::orderBy('id')->get()->values() as $i => $faculty) {
            $stopAt[$faculty->id] = [2, 1, 0, 3][$i % 4];
        }

        $courses = ProgrammeCourse::with('course.department.faculty')->where('level', 400)->where('semester_order', $semester->sort_order)->get()->pluck('course')->unique('id');
        foreach ($courses as $course) {
            $sheet = $results->sheet($course->id, $session->id, $type->id);
            if (! $sheet->isEditable() || $sheet->results()->doesntExist()) {
                continue;
            }
            $sheet->loadMissing('scheme.items');
            $caMax = $sheet->scheme ? (float) $sheet->scheme->items->where('component', 'ca')->sum('max_score') : ResultService::caMax();
            $examMax = $sheet->scheme ? (float) $sheet->scheme->items->where('component', 'exam')->sum('max_score') : ResultService::examMax();

            $scores = [];
            foreach ($sheet->results()->pluck('student_id') as $studentId) {
                $total = max(15, min(95, $this->ability($studentId) + $people->int(-15, 12)));
                $ca = min($caMax, max(3, round($total * $caMax / 100) + $people->int(-3, 3)));
                $scores[$studentId] = new Score($ca, min($examMax, max(0, $total - $ca)));
            }
            $lecturer = User::find(CourseAssignment::where('course_id', $course->id)->where('academic_session_id', $session->id)->value('user_id')) ?? $admin;
            $results->saveScores($sheet, $scores, $lecturer);
            $results->submit($sheet->fresh(), $lecturer);

            $department = $course->department;
            $signers = [User::find($department?->hod_id), User::find($department?->faculty?->dean_id), User::role('Senate')->orderBy('id')->first()];
            for ($step = 0; $step < ($stopAt[$department?->faculty_id] ?? 0); $step++) {
                $sheet = $sheet->fresh();
                $by = $signers[$step] && $results->canApprove($signers[$step], $sheet) ? $signers[$step] : $admin;
                $results->approve($sheet, $by, $step === 2 ? 'Approved at the Senate meeting' : null);
            }
        }

        $this->waivers($waivers, $people);
    }

    /** One waived carry-over in each of the first few departments, granted by the head of department. */
    private function waivers(CarryoverWaivers $waivers, DemoPeople $people): void
    {
        foreach (Department::whereNotNull('hod_id')->orderBy('id')->limit(4)->get() as $department) {
            $hod = User::find($department->hod_id);
            $failed = CourseResult::with('course', 'student')->where('status', 'published')->where('is_pass', false)
                ->whereHas('student.programme', fn ($q) => $q->where('department_id', $department->id))->orderBy('id')->get()
                ->first(fn ($r) => $waivers->failedCourseIds($r->student)->contains($r->course_id));
            if (! $hod || ! $failed) {
                continue;
            }
            $reason = $people->pick([
                'Course no longer offered in the revised curriculum; replaced by an equivalent elective.',
                'Elective failed; the student has passed another elective of the same units.',
                'Approved by the departmental board on the student\'s appeal.',
            ]);
            $waivers->grant(new CarryoverWaiverRequest($failed->student_id, $failed->course_id, $reason), $hod);
        }
    }

    /** Each student's steady ability, so their scores this semester look like their earlier ones. */
    private function ability(int $studentId): int
    {
        $avg = CourseResult::where('student_id', $studentId)->where('status', 'published')->avg('total');

        return (int) round($avg ?? 60);
    }
}
