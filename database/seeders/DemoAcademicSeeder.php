<?php

namespace Database\Seeders;

use App\Support\CourseType;
use App\Models\AcademicSession;
use App\Models\Course;
use App\Models\Department;
use App\Models\Faculty;
use App\Models\Programme;
use App\Models\ProgrammeCourse;
use App\Models\Semester;
use App\Models\SemesterType;
use App\Models\StudyCategory;
use Illuminate\Database\Seeder;

/**
 * Sample data, part 1: four sessions (the last one current, first semester running), four faculties, eleven
 * departments with one programme each, and a full 100–400 level curriculum. Development machines only.
 */
class DemoAcademicSeeder extends Seeder
{
    /** Faculty => [code, [department code => [department, programme, degree]]] */
    public const FACULTIES = [
        'Faculty of Education' => ['EDU', [
            'EDM' => ['Educational Management', 'B.Ed. Educational Management', 'B.Ed'],
            'GCE' => ['Guidance and Counselling', 'B.Ed. Guidance and Counselling', 'B.Ed'],
            'ECE' => ['Early Childhood Education', 'B.Ed. Early Childhood Education', 'B.Ed'],
        ]],
        'Faculty of Science' => ['SCI', [
            'CSC' => ['Computer Science', 'B.Sc. (Ed) Computer Science', 'B.Sc.(Ed)'],
            'MTH' => ['Mathematics', 'B.Sc. (Ed) Mathematics', 'B.Sc.(Ed)'],
            'BIO' => ['Biology', 'B.Sc. (Ed) Biology', 'B.Sc.(Ed)'],
            'CHM' => ['Chemistry', 'B.Sc. (Ed) Chemistry', 'B.Sc.(Ed)'],
        ]],
        'Faculty of Arts' => ['ART', [
            'ENG' => ['English Studies', 'B.A. (Ed) English', 'B.A.(Ed)'],
            'HIS' => ['History and Diplomatic Studies', 'B.A. (Ed) History', 'B.A.(Ed)'],
        ]],
        'Faculty of Social Sciences' => ['SSC', [
            'ECO' => ['Economics', 'B.Sc. (Ed) Economics', 'B.Sc.(Ed)'],
            'POL' => ['Political Science', 'B.Sc. (Ed) Political Science', 'B.Sc.(Ed)'],
        ]],
    ];

    public const SESSIONS = ['2023/2024', '2024/2025', '2025/2026', '2026/2027'];

    private const TOPICS = [
        100 => ['Introduction to %s', 'Foundations of %s', 'Basic Concepts in %s', 'Practical %s I', 'History of %s', 'Elements of %s',
            'Principles of %s', 'Survey of %s', 'Practical %s II', 'Introductory %s Laboratory', 'Use of Library in %s', 'Communication in %s'],
        200 => ['Intermediate %s', 'Methods in %s', 'Theories of %s', 'Applied %s', '%s and Society', 'Quantitative Methods for %s',
            'Readings in %s', 'Developments in %s', 'Field Work in %s I', 'Comparative %s', 'Issues in %s', '%s Curriculum Studies'],
        300 => ['Advanced %s I', 'Research Methods in %s', 'Teaching Methods in %s', 'Contemporary %s', 'Measurement and Evaluation in %s', 'Seminar in %s',
            'Advanced %s II', 'Special Topics in %s', 'Field Work in %s II', 'Statistics for %s', 'Ethics in %s', 'Technology in %s'],
        400 => ['Project in %s', 'Policy and Planning in %s', 'Advanced Seminar in %s', 'Entrepreneurship in %s', 'Philosophy of %s', 'Topics in %s',
            'Research Project in %s', 'Assessment in %s', 'Administration of %s', 'Current Trends in %s', 'Instructional Design in %s', 'Leadership in %s'],
    ];

    public function run(): void
    {
        // Sessions: three finished ones and the current one, first semester running and course registration open.
        foreach (self::SESSIONS as $i => $name) {
            $start = (int) substr($name, 0, 4);
            $current = $i === count(self::SESSIONS) - 1;
            $session = AcademicSession::updateOrCreate(['name' => $name], [
                'start_date' => "{$start}-10-01", 'end_date' => ($start + 1) . '-09-30', 'is_current' => $current, 'status' => $current ? 'active' : 'closed',
            ]);
            foreach ([1 => 'First Semester', 2 => 'Second Semester'] as $order => $label) {
                Semester::updateOrCreate(['academic_session_id' => $session->id, 'sort_order' => $order], [
                    'name' => $label,
                    'start_date' => $order === 1 ? "{$start}-10-01" : ($start + 1) . '-03-01',
                    'end_date' => $order === 1 ? ($start + 1) . '-02-28' : ($start + 1) . '-08-31',
                    'is_current' => $current && $order === 1,
                    'registration_opens_at' => $current && $order === 1 ? now()->subWeeks(2) : null,
                    'registration_closes_at' => $current && $order === 1 ? now()->addWeeks(6) : null,
                ]);
            }
        }
        AcademicSession::where('name', '!=', self::SESSIONS[array_key_last(self::SESSIONS)])->update(['is_current' => false]);

        $category = StudyCategory::firstOrCreate(['code' => 'UG'], ['name' => 'Undergraduate (full-time)', 'duration_years' => 4, 'level_format' => '{LEVEL} Level', 'is_active' => true]);

        // Courses every 100 level student takes, whatever their programme.
        $edm = null;
        $shared = [];

        foreach (self::FACULTIES as $facultyName => [$fcode, $departments]) {
            $faculty = Faculty::updateOrCreate(['code' => $fcode], ['name' => $facultyName, 'is_active' => true]);
            foreach ($departments as $dcode => [$deptName, $progName, $degree]) {
                $dept = Department::updateOrCreate(['code' => $dcode], ['faculty_id' => $faculty->id, 'name' => $deptName, 'is_active' => true]);
                $edm ??= $dept;
                $programme = Programme::updateOrCreate(['code' => $dcode], [
                    'department_id' => $dept->id, 'name' => $progName, 'degree_type' => $degree, 'duration_years' => 4,
                    'min_credit_load' => 15, 'max_credit_load' => 24, 'is_active' => true, 'study_category_id' => $category->id, 'graduation_units' => 140,
                ]);
                $subject = preg_replace('/ (Studies|and Diplomatic Studies)$/', '', $deptName);

                foreach (self::TOPICS as $level => $titles) {
                    foreach ([1, 2] as $sem) {
                        for ($k = 0; $k < 6; $k++) {
                            $number = $level + ($sem === 1 ? 1 : 2) + $k * 2;   // 101, 103 ... first semester; 102, 104 ... second
                            $course = Course::updateOrCreate(['code' => "{$dcode} {$number}"], [
                                'department_id' => $dept->id, 'title' => sprintf($titles[($sem - 1) * 6 + $k], $subject),
                                'credit_units' => $k % 2 === 0 ? 3 : 2, 'level' => $level, 'is_active' => true, 'course_type' => $type = match (true) {
                                    $k < 3 => CourseType::COMPULSORY, $k < 5 => CourseType::COMPULSORY_ELECTIVE, default => CourseType::ELECTIVE,
                                },
                            ]);
                            ProgrammeCourse::updateOrCreate(['programme_id' => $programme->id, 'course_id' => $course->id], ['level' => $level, 'semester_order' => $sem, 'status' => $type]);
                        }
                    }
                }
            }
        }

        foreach ([['GST 101', 'Use of English I', 1], ['GST 102', 'Use of English II', 2], ['EDU 101', 'Introduction to Teaching Profession', 1], ['EDU 102', 'History of Education in Nigeria', 2]] as [$code, $title, $sem]) {
            $shared[$sem][] = Course::updateOrCreate(['code' => $code], ['department_id' => $edm->id, 'title' => $title, 'credit_units' => 2, 'level' => 100, 'is_active' => true]);
        }
        foreach (Programme::all() as $programme) {
            foreach ($shared as $sem => $courses) {
                foreach ($courses as $course) {
                    ProgrammeCourse::updateOrCreate(['programme_id' => $programme->id, 'course_id' => $course->id], ['level' => 100, 'semester_order' => $sem, 'status' => CourseType::COMPULSORY]);
                }
            }
        }

        // Each course's own semester, category and the session it was introduced (the curriculum places it per programme).
        $types = SemesterType::pluck('id', 'sort_order');
        $first = AcademicSession::orderBy('name')->value('id');
        foreach (ProgrammeCourse::with('course')->orderBy('id')->get()->unique('course_id') as $pc) {
            $pc->course->update(['semester_type_id' => $types[$pc->semester_order] ?? null, 'study_category_id' => $category->id, 'academic_session_id' => $first]);
        }
    }
}
