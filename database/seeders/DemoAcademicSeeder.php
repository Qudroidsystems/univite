<?php

namespace Database\Seeders;

use App\Models\AcademicSession;
use App\Models\Course;
use App\Models\CourseOption;
use App\Models\Department;
use App\Models\DepartmentCategory;
use App\Models\Faculty;
use App\Models\GradeBand;
use App\Models\MatricFormat;
use App\Models\Programme;
use App\Models\ProgrammeCourse;
use App\Models\Semester;
use App\Models\SemesterType;
use App\Models\StudyCategory;
use App\Models\SubDepartment;
use App\Support\CourseType;
use Database\Seeders\Demo\AfuedStructure as A;
use Illuminate\Database\Seeder;

/**
 * Sample data, part 1: four sessions (the last one current, first semester running) and the school structure of
 * Adeyemi Federal University of Education as its old portal shows it (see Demo\AfuedStructure): schools, departments,
 * student categories per department, degree programmes, NCE single and double majors, part-time, pre-degree and
 * certificate programmes, sub-departments, course options, matric number formats, the NCE key to grade, and a
 * curriculum for every programme. Development machines only.
 */
class DemoAcademicSeeder extends Seeder
{
    public const SESSIONS = ['2023/2024', '2024/2025', '2025/2026', '2026/2027'];

    /** Course titles by level; each department's subject fills the %s. Four courses a semester: [first semester, second]. */
    private const TOPICS = [
        100 => [['Introduction to %s', 'Foundations of %s', 'Basic Concepts in %s', 'Practical %s I'], ['History of %s', 'Elements of %s', 'Principles of %s', 'Practical %s II']],
        200 => [['Intermediate %s', 'Methods in %s', 'Theories of %s', 'Applied %s'], ['%s and Society', 'Quantitative Methods for %s', 'Readings in %s', 'Field Work in %s I']],
        300 => [['Advanced %s I', 'Research Methods in %s', 'Teaching Methods in %s', 'Contemporary %s'], ['Measurement and Evaluation in %s', 'Seminar in %s', 'Advanced %s II', 'Special Topics in %s']],
        400 => [['Project in %s', 'Policy and Planning in %s', 'Advanced Seminar in %s', 'Entrepreneurship in %s'], ['Philosophy of %s', 'Current Trends in %s', 'Assessment in %s', 'Leadership in %s']],
    ];

    /** Which bank of courses a category studies from: the degree bank (BIO101 ...) or the NCE bank (BIO111 ...). */
    private const BANK = ['DEG' => 'deg', 'DPT' => 'deg', 'PRE' => 'deg', 'NCE' => 'nce', 'NPT' => 'nce', 'NSW' => 'nce', 'CRT' => 'nce'];

    /** @var array<string, array<string, array<int, array<int, Course[]>>>> department => bank => level => semester => courses */
    private array $banks = [];

    public function run(): void
    {
        $this->sessions();
        $types = SemesterType::pluck('id', 'sort_order');
        $first = AcademicSession::orderBy('name')->value('id');

        $categories = [];
        foreach (A::CATEGORIES as $code => [$name, $low, $high, $semesters, $format]) {
            $categories[$code] = StudyCategory::updateOrCreate(['code' => $code], ['name' => $name, 'duration_years' => $high - $low + 1, 'level_format' => $format, 'is_active' => true]);
        }
        $faculties = [];
        foreach (A::SCHOOLS as $code => [$name]) {
            $faculties[$code] = Faculty::updateOrCreate(['code' => $code], ['name' => $name, 'is_active' => true]);
        }

        $departments = [];
        foreach (A::DEPARTMENTS as $code => [$school, $name, $degPrefix, $ncePrefix, $title, $cats]) {
            $dept = $departments[$code] = Department::updateOrCreate(['code' => $code], ['faculty_id' => $faculties[$school]->id, 'name' => $name, 'is_active' => true]);
            foreach ($cats as $cat) {
                [, $low, $high, $semesters] = A::CATEGORIES[$cat];
                DepartmentCategory::updateOrCreate(['department_id' => $dept->id, 'study_category_id' => $categories[$cat]->id], [
                    'lowest_level' => $low * 100, 'highest_level' => $high * 100, 'semesters_per_level' => $semesters,
                    'allow_combination' => in_array($cat, ['NCE', 'NPT', 'NSW'], true), 'is_active' => true,
                ]);
            }
            foreach (['deg' => [$degPrefix ? $code : null, [100, 200, 300, 400], 0], 'nce' => [$ncePrefix, [100, 200, 300], 10]] as $bank => [$prefix, $levels, $offset]) {
                if (! $prefix || ! array_intersect($cats, array_keys(array_filter(self::BANK, fn ($b) => $b === $bank)))) {
                    continue;
                }
                $subject = A::subject($code);
                foreach ($levels as $level) {
                    foreach ([1, 2] as $sem) {
                        foreach (self::TOPICS[$level][$sem - 1] as $k => $title) {
                            $number = $level + $offset + $k * 2 + $sem;   // BIO101, 103 ... first semester; 102, 104 ... second (NCE: 111, 113 ...)
                            $this->banks[$code][$bank][$level][$sem][] = Course::updateOrCreate(['code' => $prefix . $number], [
                                'department_id' => $dept->id, 'title' => sprintf($title, $subject), 'credit_units' => $k % 2 === 0 ? 3 : 2, 'level' => $level,
                                'semester_type_id' => $types[$sem] ?? null, 'academic_session_id' => $first, 'is_active' => true,
                                'course_type' => [CourseType::COMPULSORY, CourseType::COMPULSORY, CourseType::COMPULSORY_ELECTIVE, CourseType::ELECTIVE][$k],
                            ]);
                        }
                    }
                }
            }
            $this->matricFormats($dept, $code, $degPrefix, $ncePrefix, $categories);
        }

        // Degree programmes, one per department that runs the degree.
        foreach (A::DEPARTMENTS as $code => [, , , , $title, $cats]) {
            if (in_array('DEG', $cats, true)) {
                $subject = A::subject($code);
                $this->programme($departments[$code], $categories['DEG'], "{$code}-DEG", $subject, "{$title} {$subject}", $title, [[$code, 4]], 4);
            }
        }
        // NCE and part-time NCE programmes: a double major takes all of its department's courses; a combination three of each.
        foreach (A::COMBINATIONS as [$a, $b, $cat]) {
            $name = A::subject($a) . ($b ? '/' . A::subject($b) : ($cat === 'NCE' ? ' (Double Major)' : '')) . ['NCE' => '', 'NPT' => ' (Part Time)', 'NSW' => ' (Sandwich)'][$cat];
            $code = $a . '-' . ($b ?? $a) . '-' . $cat;
            $this->programme($departments[$a], $categories[$cat], $code, $name, $name, 'NCE', $b ? [[$a, 3], [$b, 3]] : [[$a, 4]],
                A::CATEGORIES[$cat][2], $b ? $departments[$b] : null);
        }
        foreach (A::OTHER_PROGRAMMES as [$dept, $cat, $name, $appName]) {
            $this->programme($departments[$dept], $categories[$cat], "{$dept}-{$cat}", $name, $appName, A::CATEGORIES[$cat][0], [[$dept, 4]], A::CATEGORIES[$cat][2]);
        }

        foreach (A::SUB_DEPARTMENTS as [$dept, $cat, $name]) {
            SubDepartment::updateOrCreate(['department_id' => $departments[$dept]->id, 'name' => $name], ['study_category_id' => $categories[$cat]->id, 'is_active' => true]);
        }
        foreach (A::COURSE_OPTIONS as [$dept, $cat, , $options]) {
            $programme = Programme::where('department_id', $departments[$dept]->id)->where('study_category_id', $categories[$cat]->id)->orderBy('id')->first();
            foreach ($programme ? $options : [] as $option) {
                CourseOption::updateOrCreate(['programme_id' => $programme->id, 'name' => $option], ['is_active' => true]);
            }
        }

        $this->generalCourses($departments, $types, $first);
        foreach (['NCE', 'NPT', 'NSW'] as $cat) {
            foreach (A::NCE_GRADES as [$letter, $min, $max, $points, $rank, $pass]) {
                GradeBand::updateOrCreate(['study_category_id' => $categories[$cat]->id, 'letter' => $letter, 'faculty_id' => null, 'department_id' => null],
                    ['min_score' => $min, 'max_score' => $max, 'points' => $points, 'rank' => $rank, 'remark' => $rank, 'is_pass' => $pass]);
            }
        }
    }

    private function sessions(): void
    {
        // Three finished sessions and the current one, first semester running and course registration open.
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
    }

    /**
     * A programme and its curriculum. $parts lists [department, courses a semester taken from it]; a category with one
     * semester a level (NCE Sandwich) takes the first semester's courses only, and levels past the bank repeat its last.
     */
    private function programme(Department $dept, StudyCategory $category, string $code, string $name, string $appName, string $degree, array $parts, int $levels, ?Department $second = null): void
    {
        $cat = $category->code;
        $programme = Programme::updateOrCreate(['code' => $code], [
            'department_id' => $dept->id, 'study_category_id' => $category->id, 'name' => $name, 'degree_type' => $degree, 'duration_years' => $levels,
            'min_credit_load' => 12, 'max_credit_load' => 24, 'graduation_units' => $levels * 32, 'is_active' => true,
            'is_combination' => $second !== null, 'second_department_id' => $second?->id,
            'show_on_application' => in_array($cat, ['DEG', 'NCE', 'DPT', 'NPT', 'PRE'], true), 'application_name' => $appName,
        ]);
        $semesters = A::CATEGORIES[$cat][3];
        $bank = self::BANK[$cat];
        for ($l = 1; $l <= $levels; $l++) {
            $level = $l * 100;
            foreach (range(1, $semesters) as $sem) {
                foreach ($parts as [$deptCode, $take]) {
                    $levelsHere = array_keys($this->banks[$deptCode][$bank] ?? []);
                    if (! $levelsHere) {
                        continue;
                    }
                    $from = in_array($level, $levelsHere, true) ? $level : max($levelsHere);
                    foreach (array_slice($this->banks[$deptCode][$bank][$from][$sem], 0, $take) as $course) {
                        ProgrammeCourse::updateOrCreate(['programme_id' => $programme->id, 'course_id' => $course->id],
                            ['level' => $level, 'semester_order' => $sem, 'status' => $course->course_type ?? CourseType::COMPULSORY]);
                    }
                }
            }
        }
    }

    /**
     * Degree: PREFIX/YEAR/0001U (U for UTME, D for direct entry); NCE: PREFIX/YY/0001, as the old portal issues them.
     * Part-time, sandwich, pre-degree and certificate numbers carry a marker (PT, SW, PD, CRT) after the prefix; those
     * patterns are assumed and should be checked against numbers the school has issued.
     */
    private function matricFormats(Department $dept, string $code, ?string $degPrefix, ?string $ncePrefix, array $categories): void
    {
        $cats = A::DEPARTMENTS[$code][5];
        if ($degPrefix && in_array('DEG', $cats, true)) {
            foreach (['utme' => 'U', 'direct_entry' => 'D'] as $mode => $suffix) {
                MatricFormat::updateOrCreate(['department_id' => $dept->id, 'study_category_id' => $categories['DEG']->id, 'entry_mode' => $mode], [
                    'name' => "{$dept->name} (Degree, " . ($suffix === 'U' ? 'UTME' : 'Direct Entry') . ')', 'pattern' => '{PREFIX}/{YEAR}/{SEQ:4}{SUFFIX}',
                    'prefix' => $degPrefix, 'suffix' => $suffix, 'serial_from' => 1, 'serial_to' => 9999, 'is_active' => true,
                ]);
            }
        }
        if ($ncePrefix && in_array('NCE', $cats, true)) {
            MatricFormat::updateOrCreate(['department_id' => $dept->id, 'study_category_id' => $categories['NCE']->id, 'entry_mode' => null], [
                'name' => "{$dept->name} (NCE)", 'pattern' => '{PREFIX}/{SHORT_YEAR}/{SEQ:4}', 'prefix' => $ncePrefix, 'serial_from' => 1, 'serial_to' => 9999, 'is_active' => true,
            ]);
        }
        $markers = ['NPT' => ['PT', '{SHORT_YEAR}'], 'NSW' => ['SW', '{SHORT_YEAR}'], 'DPT' => ['PT', '{YEAR}'], 'PRE' => ['PD', '{YEAR}'], 'CRT' => ['CRT', '{YEAR}']];
        foreach (array_intersect_key($markers, array_flip($cats)) as $cat => [$marker, $year]) {
            MatricFormat::updateOrCreate(['department_id' => $dept->id, 'study_category_id' => $categories[$cat]->id, 'entry_mode' => null], [
                'name' => "{$dept->name} ({$categories[$cat]->name})", 'pattern' => "{PREFIX}/{$marker}/{$year}/{SEQ:4}",
                'prefix' => in_array($cat, ['NPT', 'NSW'], true) ? ($ncePrefix ?? $degPrefix ?? $code) : ($degPrefix ?? $ncePrefix ?? $code), 'serial_from' => 1, 'serial_to' => 9999, 'is_active' => true,
            ]);
        }
    }

    /** General studies and education courses, on every programme's curriculum where they belong. */
    private function generalCourses(array $departments, $types, ?int $first): void
    {
        $courses = [];
        foreach (A::GENERAL_COURSES as [$code, $title, $units, $level, $sem, $deg, $nce]) {
            $dept = str_starts_with($code, 'GSE') ? $departments['GSE'] : $departments['EDF'];
            $courses[] = [Course::updateOrCreate(['code' => $code], [
                'department_id' => $dept->id, 'title' => $title, 'credit_units' => $units, 'level' => $level, 'semester_type_id' => $types[$sem] ?? null,
                'academic_session_id' => $first, 'is_active' => true, 'course_type' => CourseType::COMPULSORY,
            ]), $level, $sem, $deg, $nce];
        }
        foreach (Programme::with('studyCategory')->get() as $programme) {
            $cat = $programme->studyCategory?->code;
            $isDegree = in_array($cat, ['DEG', 'DPT'], true);
            $isNce = in_array($cat, ['NCE', 'NPT'], true);
            foreach ($courses as [$course, $level, $sem, $deg, $nce]) {
                if (($isDegree && $deg) || ($isNce && $nce)) {
                    ProgrammeCourse::updateOrCreate(['programme_id' => $programme->id, 'course_id' => $course->id], ['level' => $level, 'semester_order' => $sem, 'status' => CourseType::COMPULSORY]);
                }
            }
        }
    }
}
