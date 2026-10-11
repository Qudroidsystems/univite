<?php

namespace App\Data\Legacy;

/** A field an import can fill, the headings that usually hold it, and whether the file must have it. */
final readonly class ImportField
{
    /** @param list<string> $synonyms headings reduced to lower-case letters and digits */
    public function __construct(public string $key, public string $label, public array $synonyms, public bool $required = false, public ?string $help = null)
    {
    }

    /** @return array<string, self> */
    public static function for(string $kind): array
    {
        $matric = ['matricnumber', 'matricno', 'matric', 'regno', 'regnumber', 'registrationnumber', 'studentid'];
        $fields = match ($kind) {
            'results' => [
                new self('matric', 'Matric number', $matric, true),
                new self('session', 'Session', ['session', 'academicsession', 'sessionname', 'year', 'academicyear'], true, 'For example 2018/2019, 2018-2019 or 2018/19.'),
                new self('semester', 'Semester', ['semester', 'sem', 'term'], true, '1 or 2, First or Second, Harmattan or Rain.'),
                new self('course', 'Course code', ['coursecode', 'code', 'course', 'courseno'], true),
                new self('title', 'Course title', ['coursetitle', 'title', 'coursename'], false, 'Only needed to add courses the portal does not have yet.'),
                new self('units', 'Credit units', ['units', 'unit', 'creditunits', 'creditunit', 'cu', 'credit', 'credits', 'load'], false, 'Blank = the units the course has in the portal.'),
                new self('ca', 'CA score', ['ca', 'continuousassessment', 'test', 'testscore', 'cascore']),
                new self('exam', 'Exam score', ['exam', 'examscore', 'examination']),
                new self('total', 'Total score', ['total', 'totalscore', 'score', 'mark', 'marks'], false, 'Blank = CA + exam.'),
                new self('grade', 'Grade', ['grade', 'lettergrade'], false, 'When given, the grade from the old records is kept even if today\'s scale would give another.'),
                new self('points', 'Grade point', ['gp', 'gradepoint', 'point', 'points'], false, 'Needed only for grades today\'s scale does not have.'),
            ],
            'balances' => [
                new self('matric', 'Matric number', $matric, true),
                new self('tnu', 'Total units (TNU)', ['tnu', 'totalunits', 'totalnumberofunits', 'cumunits', 'cumulativeunits', 'ctnu'], true),
                new self('tcp', 'Total credit points (TCP)', ['tcp', 'totalcreditpoints', 'cumpoints', 'cumulativepoints', 'ctcp', 'tqp', 'totalqualitypoints'], false, 'Blank = CGPA × TNU.'),
                new self('cgpa', 'CGPA', ['cgpa', 'cumgpa', 'cumulativegpa'], false, 'Used to check the TCP, or to work it out when there is no TCP column.'),
                new self('passed', 'Units passed (TNUP)', ['tnup', 'unitspassed', 'totalunitspassed', 'cup', 'earned']),
                new self('as_of', 'Up to session', ['session', 'asat', 'asof', 'lastsession', 'uptosession'], false, 'The last session these totals cover.'),
                new self('note', 'Note', ['note', 'remark', 'remarks', 'comment']),
            ],
            default => [],
        };

        return collect($fields)->keyBy('key')->all();
    }

    /** A heading reduced to lower-case letters and digits, as the synonyms are written. */
    public static function normalise(?string $heading): string
    {
        return strtolower(preg_replace('/[^a-z0-9]/i', '', (string) $heading));
    }
}
