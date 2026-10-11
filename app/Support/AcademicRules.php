<?php

namespace App\Support;

use App\Models\AcademicRule;
use App\Models\Department;
use App\Models\Faculty;
use App\Models\Programme;
use App\Models\SemesterType;
use App\Models\Setting;
use App\Models\Student;
use Illuminate\Support\Collection;

/**
 * The academic rules (credit load, carry-overs, CGPA method, probation, graduation ...) and where each value comes from.
 *
 * The university sets the defaults. A faculty, department or programme can override any rule, and the credit-load
 * rules can also be overridden for one level and/or one semester. The most specific value wins:
 * university < faculty < department < programme, and within each of those: all levels < one level < one semester < both.
 *
 * Inject AcademicRules where needed; it is scoped, so each request shares one cache.
 */
class AcademicRules
{
    /** key => [group, label, type (int|decimal|bool|choice), help, university setting key, default, choices?] */
    public const DEFS = [
        'min_units' => ['Credit load', 'Minimum units per semester', 'int', 'A registration form with fewer units cannot be submitted.', 'registration.min_units', '15'],
        'max_units' => ['Credit load', 'Maximum units per semester', 'int', 'A registration form with more units cannot be submitted.', 'registration.max_units', '24'],
        'carryover_exempt' => ['Credit load', 'Carry-overs do not count against the maximum', 'bool', 'Students may go above the maximum by the units of their carry-over courses.', 'registration.carryover_exempt', '1'],
        'force_carryovers' => ['Credit load', 'Failed courses must be registered again', 'bool', 'The form cannot be submitted without the carry-over courses.', 'registration.force_carryovers', '1'],
        'check_prerequisites' => ['Credit load', 'Check prerequisites', 'bool', 'A course stays locked until its prerequisites are passed.', 'registration.check_prerequisites', '1'],

        'retake_failed_electives' => ['Results', 'A failed elective must be passed', 'bool', 'On: a failed elective is a carry-over and blocks graduation until passed. Off: the student may drop it and take another elective.', 'rules.retake_failed_electives', '1'],
        'cgpa_method' => ['Results', 'How CGPA counts repeated courses', 'choice', 'Every attempt is the usual TCP ÷ TNU method: a failed attempt stays in the CGPA after the re-sit is passed.', 'rules.cgpa_method', 'latest', ['latest' => 'Latest attempt only (a passed re-sit replaces the fail)', 'all' => 'Every attempt (TCP ÷ TNU)']],

        'probation_cgpa' => ['Academic standing', 'Probation below CGPA', 'decimal', 'A student whose CGPA falls below this is on probation. Blank = no probation.', 'rules.probation_cgpa', ''],
        'probation_max_units' => ['Academic standing', 'Maximum units per semester on probation', 'int', 'A student on probation cannot register more than this. Blank = the normal maximum.', 'rules.probation_max_units', ''],
        'withdrawal_cgpa' => ['Academic standing', 'Advise to withdraw below CGPA', 'decimal', 'A student whose CGPA falls below this is advised to withdraw. Blank = never.', 'rules.withdrawal_cgpa', ''],
        'withdrawal_blocks_registration' => ['Academic standing', 'Students advised to withdraw cannot register', 'bool', 'They need the department to allow them for the session first.', 'rules.withdrawal_blocks_registration', '1'],

        'graduation_units' => ['Graduation', 'Units needed to graduate', 'int', 'Total credit units a student must pass. Blank = not checked.', 'rules.graduation_units', ''],
        'min_elective_units' => ['Graduation', 'Elective units needed to graduate', 'int', 'Units of elective courses a student must pass. Blank = not checked.', 'rules.min_elective_units', ''],
        'min_graduation_cgpa' => ['Graduation', 'Minimum CGPA to graduate', 'decimal', 'Blank = any CGPA that earns a class of degree.', 'rules.min_graduation_cgpa', ''],
        'require_core' => ['Graduation', 'Every core and required course must be taken', 'bool', 'Counted from the student\'s entry level, so direct-entry students are not asked for lower-level courses.', 'graduation.require_core', '1'],
        'max_years' => ['Graduation', 'Maximum years of study', 'int', 'Sessions a student may spend on the programme, including spill-over years. Students past it cannot graduate and are flagged. Blank = no limit.', 'rules.max_years', ''],
    ];

    public const SCOPES = ['university' => 'University', 'faculty' => 'Faculty', 'department' => 'Department', 'programme' => 'Programme'];

    private ?Collection $rows = null;

    public static function group(string $key): string
    {
        return self::DEFS[$key][0];
    }

    /** Keys that may be set for a single level or semester. */
    public static function perTermKeys(): array
    {
        return array_keys(array_filter(self::DEFS, fn ($d) => $d[0] === 'Credit load'));
    }

    public function flush(): void
    {
        $this->rows = null;
    }

    private function rows(): Collection
    {
        return $this->rows ??= AcademicRule::all();
    }

    /** Turn stored text into the rule's type; '' / null = not set. */
    public static function cast(string $key, $value)
    {
        if ($value === null || $value === '') {
            return null;
        }

        return match (self::DEFS[$key][2]) {
            'int' => (int) $value,
            'decimal' => round((float) $value, 2),
            'bool' => in_array($value, [true, 1, '1', 'true', 'on'], true),
            default => (string) $value,
        };
    }

    /** The university's own values (stored as settings). */
    public function university(): array
    {
        $out = [];
        foreach (self::DEFS as $key => $d) {
            $out[$key] = self::cast($key, Setting::get($d[4], $d[5]));
        }

        return $out;
    }

    public function saveUniversity(array $values): void
    {
        foreach ($values as $key => $v) {
            if (isset(self::DEFS[$key])) {
                $v = self::cast($key, $v);
                Setting::put(self::DEFS[$key][4], $v === null ? '' : (is_bool($v) ? ($v ? '1' : '0') : (string) $v));
            }
        }
        $this->flush();
    }

    /**
     * The scopes above a programme / department / faculty, most general first: [[type, id, label], ...].
     */
    public static function chain(string $type, ?int $id): array
    {
        $chain = [['university', null, 'University']];
        if ($type === 'university' || ! $id) {
            return $chain;
        }
        $programme = $type === 'programme' ? Programme::with('department.faculty')->find($id) : null;
        $department = $type === 'department' ? Department::with('faculty')->find($id) : $programme?->department;
        $faculty = $type === 'faculty' ? Faculty::find($id) : $department?->faculty;

        if ($faculty) {
            $chain[] = ['faculty', $faculty->id, $faculty->name];
        }
        if ($department) {
            $chain[] = ['department', $department->id, $department->name];
        }
        if ($programme) {
            $chain[] = ['programme', $programme->id, $programme->name];
        }

        return $chain;
    }

    /**
     * Every rule's value for a scope (and optionally a level / semester), with where it comes from.
     *
     * @return array<string, array{value: mixed, from: string}>
     */
    public function resolve(string $type = 'university', ?int $id = null, ?int $level = null, ?int $semesterTypeId = null, bool $includeOwn = true): array
    {
        $out = [];
        foreach ($this->university() as $key => $v) {
            $out[$key] = ['value' => $v, 'from' => 'University'];
        }

        $chain = self::chain($type, $id);
        $semName = $semesterTypeId ? SemesterType::find($semesterTypeId)?->name : null;
        foreach ($chain as $i => [$t, $sid, $label]) {
            $last = $i === count($chain) - 1;
            // Within a scope: all levels, then the level, then the semester, then both.
            $variants = [[null, null]];
            if ($level) {
                $variants[] = [$level, null];
            }
            if ($semesterTypeId) {
                $variants[] = [null, $semesterTypeId];
            }
            if ($level && $semesterTypeId) {
                $variants[] = [$level, $semesterTypeId];
            }
            foreach ($variants as [$l, $s]) {
                if ($t === 'university' && $l === null && $s === null) {
                    continue;   // already applied from settings
                }
                if (! $includeOwn && $last && $l === $level && $s === $semesterTypeId) {
                    continue;   // the caller wants what this exact scope would inherit
                }
                $row = $this->rows()->first(fn ($r) => $r->scope_type === $t && $r->scope_id === (int) $sid && $r->level === $l && $r->semester_type_id === $s);
                foreach ($row?->rules ?? [] as $key => $v) {
                    if (isset(self::DEFS[$key])) {
                        $out[$key] = ['value' => self::cast($key, $v), 'from' => $label . ($l ? ', ' . $l . ' level' : '') . ($s ? ', ' . $semName : '')];
                    }
                }
            }
        }

        return $out;
    }

    /** Plain values for a student's programme (and a level / semester when given). */
    public function forProgramme(?Programme $programme, ?int $level = null, ?int $semesterTypeId = null): array
    {
        return array_map(fn ($r) => $r['value'], $programme
            ? $this->resolve('programme', $programme->id, $level, $semesterTypeId)
            : $this->resolve());
    }

    public function forStudent(Student $student, ?int $level = null, ?int $semesterTypeId = null): array
    {
        return $this->forProgramme($student->programme, $level ?? $student->current_level, $semesterTypeId);
    }

    /** The stored overrides of one exact scope (university excluded). */
    public function own(string $type, int $id, ?int $level, ?int $semesterTypeId): array
    {
        return $this->rows()->first(fn ($r) => $r->scope_type === $type && $r->scope_id === $id && $r->level === $level && $r->semester_type_id === $semesterTypeId)?->rules ?? [];
    }

    /**
     * Save one scope's overrides. A key left out (or sent as null) is inherited; '' for a number rule means "no limit here".
     */
    public function save(string $type, int $id, ?int $level, ?int $semesterTypeId, array $rules, ?int $userId): void
    {
        $clean = [];
        foreach ($rules as $key => $v) {
            if (! isset(self::DEFS[$key]) || $v === null) {
                continue;
            }
            if (($level || $semesterTypeId) && ! in_array($key, self::perTermKeys(), true)) {
                continue;
            }
            $clean[$key] = $v === '' ? '' : self::cast($key, $v);
        }

        $row = AcademicRule::where(['scope_type' => $type, 'scope_id' => $id])
            ->where(fn ($q) => $level ? $q->where('level', $level) : $q->whereNull('level'))
            ->where(fn ($q) => $semesterTypeId ? $q->where('semester_type_id', $semesterTypeId) : $q->whereNull('semester_type_id'))->first();
        if (! $clean) {
            $row?->delete();
        } elseif ($row) {
            $row->update(['rules' => $clean, 'updated_by' => $userId]);
        } else {
            AcademicRule::create(['scope_type' => $type, 'scope_id' => $id, 'level' => $level, 'semester_type_id' => $semesterTypeId, 'rules' => $clean, 'updated_by' => $userId]);
        }
        $this->flush();
    }

    /** "On probation" / "Advised to withdraw" / null for a CGPA under a programme's rules. */
    public static function standing(array $rules, float $cgpa, bool $hasResults = true): ?string
    {
        if (! $hasResults) {
            return null;
        }
        if ($rules['withdrawal_cgpa'] !== null && $cgpa < $rules['withdrawal_cgpa']) {
            return 'Advised to withdraw';
        }
        if ($rules['probation_cgpa'] !== null && $cgpa < $rules['probation_cgpa']) {
            return 'On probation';
        }

        return null;
    }

    public static function display(string $key, $value): string
    {
        if ($value === null) {
            return self::DEFS[$key][2] === 'bool' ? 'No' : 'Not set';
        }

        return match (self::DEFS[$key][2]) {
            'bool' => $value ? 'Yes' : 'No',
            'choice' => self::DEFS[$key][6][$value] ?? (string) $value,
            'decimal' => number_format($value, 2),
            default => (string) $value,
        };
    }
}
