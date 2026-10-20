<?php

namespace App\Data\Senate;

/**
 * One finding about a student or a course. Group A = graduation eligibility, B = result integrity, C = clearance and
 * standing. A blocking finding makes the student not eligible; the others put them before Senate for a decision;
 * info-only findings are shown but change nothing.
 */
final readonly class SenateReason
{
    public const GROUPS = ['A' => 'Graduation eligibility', 'B' => 'Result integrity', 'C' => 'Clearance & standing', 'D' => 'Course'];

    /** code => [group, label] */
    public const CODES = [
        'no_results' => ['A', 'No results'], 'missing' => ['B', 'Missing results (AR)'], 'outstanding' => ['A', 'Outstanding / carry-over courses'],
        'compulsory' => ['A', 'Compulsory courses not taken'], 'units' => ['A', 'Units short of the total needed'], 'electives' => ['A', 'Elective units short'],
        'cgpa_min' => ['A', 'Below the minimum graduation CGPA'], 'max_years' => ['A', 'Past the maximum years of study'], 'boundary' => ['A', 'Near a class-of-degree boundary'],
        'withdraw' => ['A', 'Advised to withdraw'], 'probation' => ['C', 'On probation'],
        'unregistered' => ['B', 'Scored without registration (NR)'], 'amended' => ['B', 'Score changed after submission'], 'credit_load' => ['B', 'Credit load outside the limits'],
        'gpa_swing' => ['B', 'Sharp GPA change'], 'complaint' => ['B', 'Result query pending'], 'waiver' => ['B', 'Carry-over waived'],
        'fees' => ['C', 'Fees owed'], 'loan' => ['C', 'School loan owed'], 'library' => ['C', 'Library not cleared'], 'hostel' => ['C', 'Hostel not cleared'],
        'standing_history' => ['C', 'Probation / withdrawal history'], 'standing_waiver' => ['C', 'Allowed to register despite standing'],
        'pass_high' => ['D', 'Very high pass rate'], 'pass_low' => ['D', 'Very low pass rate'], 'many_a' => ['D', 'Many A grades'], 'many_f' => ['D', 'Many F grades'],
        'avg_gap' => ['D', 'Average far from the department\'s'], 'late' => ['D', 'Sheet submitted late'], 'returned' => ['D', 'Sheet returned for correction'],
        'short_chain' => ['D', 'Approved by fewer than every step'], 'not_approved' => ['D', 'Not yet through approval'], 'corrected' => ['D', 'Scores changed during approval'],
        'course_missing' => ['D', 'Students without a result'],
    ];

    public function __construct(public string $code, public string $text, public bool $blocks = false, public bool $info = false)
    {
    }

    public function group(): string
    {
        return self::CODES[$this->code][0] ?? 'B';
    }

    public function label(): string
    {
        return self::CODES[$this->code][1] ?? $this->code;
    }

    public function toArray(): array
    {
        return ['code' => $this->code, 'group' => $this->group(), 'text' => $this->text, 'blocks' => $this->blocks, 'info' => $this->info];
    }
}
