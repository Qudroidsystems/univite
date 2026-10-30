<?php

namespace Database\Seeders;

use App\Models\AcademicSession;
use App\Models\Faculty;
use App\Models\Semester;
use App\Models\SemesterType;
use App\Models\Timetable;
use App\Models\User;
use App\Services\TimetableService;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Sample data, part 9: this semester's lecture timetable and examination timetable for the whole school, generated,
 * approved and published like a real one, so every student, lecturer and invigilator has a full timetable and the
 * "timetable published" notices go out. Every class is placed: the seeder stops if the solver leaves one out.
 *
 * Generating the whole school takes a few minutes. DEMO_TIMETABLE_SCOPE limits it to one school (a school code such as
 * SCI), which the automated tests use.
 */
class DemoTimetableSeeder extends Seeder
{
    public function run(TimetableService $timetables): void
    {
        $session = AcademicSession::current();
        $semester = Semester::current();
        $type = $semester ? SemesterType::where('sort_order', $semester->sort_order)->first() : null;
        $admin = User::role('Super Admin')->orderBy('id')->first();
        if (! $session || ! $type || ! $admin || Timetable::where('academic_session_id', $session->id)->where('semester_type_id', $type->id)->exists()) {
            return;
        }
        $scope = env('DEMO_TIMETABLE_SCOPE', 'all');
        $departments = $scope === 'all' ? [] : Faculty::where('code', $scope)->first()?->departments()->pluck('id')->all() ?? [];
        $label = $scope === 'all' ? 'All schools' : (Faculty::where('code', $scope)->value('name') ?? $scope);

        $lectures = $timetables->create(['kind' => 'lecture', 'name' => "{$label}: lectures", 'academic_session_id' => $session->id,
            'semester_type_id' => $type->id, 'departments' => $departments], $admin);
        $this->finish($timetables, $lectures, $admin);

        // The examinations: the last three weeks of the semester, two sittings a day, lecturers invigilating.
        $end = $semester->end_date ? $semester->end_date->copy() : now()->addMonths(3);
        $exams = $timetables->create(['kind' => 'exam', 'name' => "{$label}: examinations", 'academic_session_id' => $session->id,
            'semester_type_id' => $type->id, 'departments' => $departments,
            'start_date' => $end->copy()->subWeeks(3)->startOfWeek()->toDateString(), 'end_date' => $end->toDateString()], $admin);
        $config = $exams->config;
        $config['invigilators'] = ['role' => 'Lecturer', 'users' => []];
        $exams->update(['config' => $config]);
        $this->finish($timetables, $exams, $admin);
    }

    private function finish(TimetableService $timetables, Timetable $t, User $admin): void
    {
        $summary = $timetables->generate($t, $admin);
        if (($summary['unplaced'] ?? 0) > 0 || ($summary['violations'] ?? 0) > 0) {
            throw new RuntimeException("The sample {$t->name} timetable left {$summary['unplaced']} class(es) out and breaks {$summary['violations']} rule(s): " . implode(' ', array_slice($summary['problems'] ?? [], 0, 3)));
        }
        $timetables->submit($t, $admin);
        $timetables->approve($t->fresh(), $admin, 'Approved for the sample data.');
        $timetables->publish($t->fresh(), $admin);
        $this->command?->line("  {$t->name}: {$summary['placed']} classes placed, none left out.");
    }
}
