<?php

namespace App\Services;

use App\Data\Legacy\BalanceRow;
use App\Data\Legacy\ColumnMapping;
use App\Data\Legacy\ImportField;
use App\Data\Legacy\ImportOptions;
use App\Data\Legacy\ImportReport;
use App\Data\Legacy\NewCourse;
use App\Data\Legacy\ParsedFile;
use App\Data\Legacy\ResultRow;
use App\Models\AcademicSession;
use App\Models\Course;
use App\Models\CourseResult;
use App\Models\GradeBand;
use App\Models\LegacyBalance;
use App\Models\LegacyImport;
use App\Models\SemesterType;
use App\Models\Student;
use App\Models\User;
use App\Support\GradeScale;
use App\Support\SpreadsheetReader;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Imports results from before the portal, from whatever spreadsheet the old system exports. The columns are matched
 * to the portal's fields (guessed from the headings, then confirmed by a person), every row is checked without saving
 * anything, and only then are the good rows imported as published results, or as opening balances (previous TCP / TNU)
 * that count towards the CGPA. Nothing already in the portal is overwritten, and a whole import can be undone.
 */
class LegacyImportService
{
    public const KINDS = ['results', 'balances'];

    public function __construct(private OperationAudit $audit, private SpreadsheetReader $reader)
    {
    }

    public function upload(string $kind, UploadedFile $file, User $by): LegacyImport
    {
        if (! in_array($kind, self::KINDS, true)) {
            throw new RuntimeException('Unknown kind of import.');
        }
        $ext = strtolower($file->getClientOriginalExtension());
        $path = $file->storeAs('legacy-imports', Str::uuid() . '.' . $ext, 'local');
        $rows = $this->reader->read(Storage::disk('local')->path($path), $ext);
        if (count($rows) < 2) {
            Storage::disk('local')->delete($path);
            throw new RuntimeException('The file needs a row of column headings followed by at least one row of data.');
        }
        $headers = array_map(fn ($h) => trim((string) $h), $rows[0]);

        return LegacyImport::create([
            'kind' => $kind, 'file_name' => $file->getClientOriginalName(), 'path' => $path, 'headers' => $headers,
            'mapping' => $this->guess($kind, $headers)->toArray(), 'options' => (new ImportOptions())->toArray(),
            'rows' => count($rows) - 1, 'created_by' => $by->id,
        ]);
    }

    /** Match each field to the first heading that is one of its known names. */
    public function guess(string $kind, array $headers): ColumnMapping
    {
        $keys = array_map([ImportField::class, 'normalise'], $headers);
        $map = [];
        $used = [];
        foreach (ImportField::for($kind) as $field) {
            foreach ([ImportField::normalise($field->label), ...$field->synonyms] as $n) {
                $i = array_search($n, $keys, true);
                if ($i !== false && ! in_array($i, $used, true)) {
                    $map[$field->key] = $i;
                    $used[] = $i;
                    break;
                }
            }
        }

        return new ColumnMapping($map);
    }

    private function rows(LegacyImport $import): array
    {
        $path = Storage::disk('local')->path($import->path);
        if (! is_file($path)) {
            throw new RuntimeException('The uploaded file is no longer on the server. Upload it again.');
        }
        $rows = $this->reader->read($path, pathinfo($import->path, PATHINFO_EXTENSION));
        array_shift($rows);

        return $rows;
    }

    /** Check every row and save the report; nothing else changes. */
    public function check(LegacyImport $import, ColumnMapping $mapping, ImportOptions $options): ImportReport
    {
        if ($import->status === 'imported') {
            throw new RuntimeException('This file has already been imported.');
        }
        foreach (ImportField::for($import->kind) as $field) {
            if ($field->required && ! $mapping->has($field->key)) {
                throw new RuntimeException("Choose the column that holds the {$field->label}.");
            }
        }
        $mapped = array_keys($mapping->columns);
        if ($import->kind === 'results' && ! array_intersect(['total', 'grade', 'ca', 'exam'], $mapped)) {
            throw new RuntimeException('Choose at least one column with the score (total, or CA and exam) or the grade.');
        }
        if ($import->kind === 'balances' && ! array_intersect(['tcp', 'cgpa'], $mapped)) {
            throw new RuntimeException('Choose the TCP column, or the CGPA column so the TCP can be worked out.');
        }
        $import->update(['mapping' => $mapping->toArray(), 'options' => $options->toArray()]);

        $report = ImportReport::fromParsed($this->parse($import, $mapping, $options));
        $import->update(['report' => $report->toArray(), 'status' => 'checked']);

        return $report;
    }

    /** Read and check every row of the file: the good rows ready to save, and why each other row is left out. */
    private function parse(LegacyImport $import, ColumnMapping $map, ImportOptions $opt): ParsedFile
    {
        @set_time_limit(600);
        $rows = $this->rows($import);
        $out = new ParsedFile(count($rows));

        $students = collect();
        foreach (collect($rows)->map(fn ($l) => strtoupper($map->value($l, 'matric')))->filter()->unique()->chunk(1000) as $chunk) {
            $students = $students->merge(Student::with('programme')->whereIn(DB::raw('UPPER(matric_number)'), $chunk->values()->all())->get()->keyBy(fn ($s) => strtoupper($s->matric_number)));
        }
        $sessions = AcademicSession::pluck('id', 'name')->all();

        $import->kind === 'balances'
            ? $this->parseBalances($rows, $map, $opt, $students, $sessions, $out)
            : $this->parseResults($rows, $map, $opt, $students, $sessions, $out);

        return $out;
    }

    /** The session a row names, checked against the portal (or noted for adding); null with a problem recorded when unusable. */
    private function session(string $raw, array $sessions, ImportOptions $opt, ParsedFile $out, int $n, string $matric): ?string
    {
        $name = self::sessionName($raw);
        if (! $name) {
            $out->problem($n, $matric, "\"{$raw}\" is not a session like 2018/2019.");

            return null;
        }
        if (! isset($sessions[$name])) {
            if (! $opt->createSessions) {
                $out->problem($n, $matric, "Session {$name} is not in the portal.");

                return null;
            }
            $out->newSessions[$name] = $name;
        }

        return $name;
    }

    private function parseBalances(array $rows, ColumnMapping $map, ImportOptions $opt, Collection $students, array $sessions, ParsedFile $out): void
    {
        $have = LegacyBalance::whereIn('student_id', $students->pluck('id'))->pluck('student_id')->flip();
        $max = GradeScale::maxPoints() ?: 5;
        $seen = [];
        foreach ($rows as $i => $line) {
            $n = $i + 2;
            $matric = strtoupper($map->value($line, 'matric'));
            if ($matric === '') {
                $out->problem($n, $matric, 'No matric number.');
                continue;
            }
            $s = $students->get($matric);
            if (! $s) {
                $out->problem($n, $matric, 'No student has this matric number in the portal.');
                continue;
            }
            if (isset($seen[$s->id])) {
                $out->problem($n, $matric, "Appears again (first on row {$seen[$s->id]}).");
                continue;
            }
            $seen[$s->id] = $n;
            if (isset($have[$s->id])) {
                $out->existing++;
                continue;
            }
            [$tnu, $tcp, $cgpa, $passed] = [$map->value($line, 'tnu'), $map->value($line, 'tcp'), $map->value($line, 'cgpa'), $map->value($line, 'passed')];
            if (! is_numeric($tnu) || (int) $tnu <= 0 || (float) $tnu != (int) $tnu) {
                $out->problem($n, $matric, "TNU \"{$tnu}\" is not a whole number of units.");
                continue;
            }
            $tnu = (int) $tnu;
            if ($tcp === '' && ! is_numeric($cgpa)) {
                $out->problem($n, $matric, 'No TCP, and no CGPA to work it out from.');
                continue;
            }
            if ($tcp !== '' && ! is_numeric($tcp)) {
                $out->problem($n, $matric, "TCP \"{$tcp}\" is not a number.");
                continue;
            }
            $tcpValue = $tcp !== '' ? round((float) $tcp, 2) : round((float) $cgpa * $tnu, 2);
            if ($tcpValue < 0 || $tcpValue > $tnu * $max + 0.001) {
                $out->problem($n, $matric, "TCP {$tcpValue} is impossible for {$tnu} units on a {$max}-point scale.");
                continue;
            }
            if ($passed !== '' && (! is_numeric($passed) || (int) $passed < 0 || (int) $passed > $tnu)) {
                $out->problem($n, $matric, "Units passed \"{$passed}\" must be a whole number no larger than the TNU.");
                continue;
            }
            $asOf = null;
            if (($raw = $map->value($line, 'as_of')) !== '' && ! ($asOf = $this->session($raw, $sessions, $opt, $out, $n, $matric))) {
                continue;
            }
            if ($tcp !== '' && is_numeric($cgpa) && abs($tcpValue / $tnu - (float) $cgpa) > 0.015) {
                $out->warning($n, $matric, sprintf('TCP %s ÷ TNU %d = %.2f, but the file says CGPA %s. The TCP is used.', $tcpValue, $tnu, $tcpValue / $tnu, $cgpa));
            }
            $note = Str::limit($map->value($line, 'note'), 250, '');
            $out->good[] = new BalanceRow($n, $s->id, $s->matric_number, $s->full_name, $tnu, $tcpValue, $passed !== '' ? (int) $passed : null, $asOf, $note !== '' ? $note : null);
        }
    }

    private function parseResults(array $rows, ColumnMapping $map, ImportOptions $opt, Collection $students, array $sessions, ParsedFile $out): void
    {
        $types = SemesterType::orderBy('sort_order')->get();
        $courses = Course::get(['id', 'code', 'title', 'credit_units'])->keyBy(fn ($c) => self::courseKey($c->code));
        $bands = GradeBand::get()->keyBy(fn ($b) => strtoupper($b->letter));
        $labels = ImportField::for('results');
        $existing = CourseResult::whereIn('student_id', $students->pluck('id'))->get(['student_id', 'course_id', 'academic_session_id', 'semester_type_id'])
            ->mapWithKeys(fn ($r) => ["{$r->student_id}|{$r->course_id}|{$r->academic_session_id}|{$r->semester_type_id}" => true])->all();
        $seen = [];
        foreach ($rows as $i => $line) {
            $n = $i + 2;
            $matric = strtoupper($map->value($line, 'matric'));
            if ($matric === '') {
                $out->problem($n, $matric, 'No matric number.');
                continue;
            }
            $s = $students->get($matric);
            if (! $s) {
                $out->problem($n, $matric, 'No student has this matric number in the portal.');
                continue;
            }
            $sessionName = $this->session($map->value($line, 'session'), $sessions, $opt, $out, $n, $matric);
            if (! $sessionName) {
                continue;
            }
            $type = self::semesterType($map->value($line, 'semester'), $types);
            if (! $type) {
                $out->problem($n, $matric, '"' . $map->value($line, 'semester') . '" is not a semester the portal knows.');
                continue;
            }
            $code = $map->value($line, 'course');
            $ckey = self::courseKey($code);
            if ($ckey === '') {
                $out->problem($n, $matric, 'No course code.');
                continue;
            }
            $course = $courses->get($ckey);
            $unitsRaw = $map->value($line, 'units');
            if ($unitsRaw !== '' && (! is_numeric($unitsRaw) || (int) $unitsRaw < 0 || (int) $unitsRaw > 30)) {
                $out->problem($n, $matric, "Credit units \"{$unitsRaw}\" must be a whole number from 0 to 30.");
                continue;
            }
            if (! $course && ! isset($out->newCourses[$ckey])) {
                $title = $map->value($line, 'title');
                if (! $opt->createCourses) {
                    $out->problem($n, $matric, "Course {$code} is not in the portal. Add it under Courses, or tick \"Add courses\".");
                    continue;
                }
                if ($title === '' || $unitsRaw === '') {
                    $out->problem($n, $matric, "Course {$code} is not in the portal; to add it the row needs its title and credit units.");
                    continue;
                }
                if (! $s->programme?->department_id) {
                    $out->problem($n, $matric, "Course {$code} is not in the portal and the student has no department to add it to.");
                    continue;
                }
                $out->newCourses[$ckey] = new NewCourse($ckey, self::tidyCode($code), Str::limit($title, 250, ''), (int) $unitsRaw, $s->programme->department_id);
            }
            $units = $unitsRaw !== '' ? (int) $unitsRaw : (int) ($course?->credit_units ?? $out->newCourses[$ckey]->units);

            $nums = [];
            foreach (['ca', 'exam', 'total', 'points'] as $f) {
                $v = $map->value($line, $f);
                if ($v !== '' && ! is_numeric($v)) {
                    $out->problem($n, $matric, $labels[$f]->label . " \"{$v}\" is not a number.");
                    continue 2;
                }
                $nums[$f] = $v === '' ? null : (float) $v;
            }
            $total = $nums['total'] ?? ($nums['ca'] !== null || $nums['exam'] !== null ? ($nums['ca'] ?? 0) + ($nums['exam'] ?? 0) : null);
            if ($total !== null && ($total < 0 || $total > 100)) {
                $out->problem($n, $matric, "Score {$total} is outside 0 to 100.");
                continue;
            }
            $letter = strtoupper($map->value($line, 'grade'));
            if ($letter !== '') {
                $band = $bands->get($letter);
                $points = $nums['points'] ?? $band?->points;
                if ($points === null) {
                    $out->problem($n, $matric, "Grade {$letter} is not on today's grading scale; add a grade point column for it.");
                    continue;
                }
                $pass = $band ? $band->is_pass : $points > 0;
                if ($total !== null && ($now = GradeScale::grade($total)) && $now['grade'] !== $letter) {
                    $out->warning($n, $matric, "{$code}: score {$total} is {$now['grade']} on today's scale; the old grade {$letter} is kept.");
                }
            } elseif ($total !== null) {
                $g = GradeScale::grade($total);
                if (! $g) {
                    $out->problem($n, $matric, "Score {$total} falls in no grade on the grading scale.");
                    continue;
                }
                [$letter, $points, $pass] = [$g['grade'], $g['points'], $g['is_pass']];
            } else {
                $out->problem($n, $matric, 'No score and no grade.');
                continue;
            }

            $dupe = $s->id . '|' . ($course?->id ?? 'new:' . $ckey) . "|{$sessionName}|{$type->id}";
            if (isset($seen[$dupe])) {
                $out->problem($n, $matric, "{$code} in {$sessionName} {$type->name} appears again (first on row {$seen[$dupe]}).");
                continue;
            }
            $seen[$dupe] = $n;
            if ($course && isset($sessions[$sessionName]) && isset($existing["{$s->id}|{$course->id}|{$sessions[$sessionName]}|{$type->id}"])) {
                $out->existing++;
                continue;
            }
            $out->good[] = new ResultRow(
                $n, $s->id, $s->matric_number, $s->full_name, $course?->id, $ckey, $course?->code ?? self::tidyCode($code), $sessionName, $type->id, $type->name,
                $units, $nums['ca'], $nums['exam'], $total, $letter, (float) $points, (bool) $pass,
            );
        }
    }

    /** Import the rows that passed the check, as one operation that can be undone. */
    public function import(LegacyImport $import, User $by): ImportReport
    {
        if ($import->status !== 'checked') {
            throw new RuntimeException($import->status === 'imported' ? 'This file has already been imported.' : 'Check the file before importing it.');
        }
        $parsed = $this->parse($import, ColumnMapping::fromArray($import->mapping), ImportOptions::fromArray($import->options));
        if (! $parsed->good) {
            throw new RuntimeException('No row is ready to import. Fix the problems in the file and upload it again.');
        }

        return DB::transaction(function () use ($import, $by, $parsed) {
            $op = $this->audit->begin('legacy_import', 'results', $by, null, null, ['file' => $import->file_name, 'kind' => $import->kind]);
            $sessions = AcademicSession::pluck('id', 'name')->all();
            foreach ($parsed->newSessions as $name) {
                $session = AcademicSession::create(['name' => $name, 'is_current' => false]);
                $sessions[$name] = $session->id;
                $this->audit->record($op, 'session added', $session, null, ['name' => $name], null, null, $name);
            }
            $now = now();
            $n = 0;
            if ($import->kind === 'balances') {
                foreach ($parsed->good as $g) {
                    /** @var BalanceRow $g */
                    LegacyBalance::create(['student_id' => $g->studentId, 'tcp' => $g->tcp, 'tnu' => $g->tnu, 'units_passed' => $g->unitsPassed, 'as_of_session_id' => $g->sessionName ? $sessions[$g->sessionName] : null, 'note' => $g->note, 'legacy_import_id' => $import->id]);
                    $n++;
                }
            } else {
                $courseIds = [];
                foreach ($parsed->newCourses as $c) {
                    $course = Course::create(['department_id' => $c->departmentId, 'code' => $c->code, 'title' => $c->title, 'credit_units' => $c->units, 'is_active' => false]);
                    $courseIds[$c->key] = $course->id;
                    $this->audit->record($op, 'course added', $course, null, ['code' => $c->code, 'title' => $c->title], null, null, $c->code);
                }
                foreach (array_chunk($parsed->good, 500) as $chunk) {
                    CourseResult::insert(array_map(fn (ResultRow $g) => [
                        'student_id' => $g->studentId, 'course_id' => $g->courseId ?? $courseIds[$g->courseKey], 'academic_session_id' => $sessions[$g->sessionName],
                        'semester_type_id' => $g->semesterTypeId, 'ca' => $g->ca, 'exam' => $g->exam, 'total' => $g->total, 'grade' => $g->grade, 'points' => $g->points,
                        'is_pass' => $g->isPass, 'credit_units' => $g->units, 'status' => 'published', 'legacy_import_id' => $import->id, 'created_at' => $now, 'updated_at' => $now,
                    ], $chunk));
                    $n += count($chunk);
                }
            }
            $report = ImportReport::fromParsed($parsed, $n);
            $this->audit->record($op, 'imported', $import, null, ['rows' => $n, 'students' => $report->students, 'skipped' => $report->skipped()], null, null, $import->file_name);
            $this->audit->finish($op, $n, 0, $report->skipped());
            $import->update(['status' => 'imported', 'imported' => $n, 'imported_at' => $now, 'operation_batch_id' => $op->id, 'report' => $report->toArray()]);

            return $report;
        });
    }

    // ---- normalising the old records' spelling --------------------------------------------------------

    /** "2018/2019", "2018-2019", "2018/19", "2018 / 2019" -> "2018/2019"; anything else -> null. */
    public static function sessionName(string $raw): ?string
    {
        if (! preg_match('/^\s*(\d{4})\s*[\/\-–_ ]\s*(\d{2}|\d{4})\s*$/u', $raw, $m)) {
            return null;
        }
        $end = strlen($m[2]) === 2 ? substr($m[1], 0, 2) . $m[2] : $m[2];
        if ((int) $end !== (int) $m[1] + 1) {
            return null;
        }

        return $m[1] . '/' . $end;
    }

    public static function semesterType(string $raw, $types): ?SemesterType
    {
        $k = ImportField::normalise($raw);
        $order = match (true) {
            in_array($k, ['1', '1st', 'first', 'firstsemester', '1stsemester', 'harmattan', 'harmattansemester', 'sem1', 'semester1'], true) => 1,
            in_array($k, ['2', '2nd', 'second', 'secondsemester', '2ndsemester', 'rain', 'rainsemester', 'sem2', 'semester2'], true) => 2,
            in_array($k, ['3', '3rd', 'third', 'thirdsemester', 'summer', 'longvacation', 'sem3', 'semester3'], true) => 3,
            default => null,
        };

        return $order ? $types->firstWhere('sort_order', $order) : $types->first(fn ($t) => ImportField::normalise($t->name) === $k);
    }

    public static function courseKey(string $code): string
    {
        return strtoupper(preg_replace('/\s+/', '', $code));
    }

    /** "csc101" -> "CSC 101" (letters, a space, then the number). */
    public static function tidyCode(string $code): string
    {
        $c = strtoupper(trim(preg_replace('/\s+/', ' ', $code)));

        return preg_match('/^([A-Z]+)\s*(\d+[A-Z]?)$/', $c, $m) ? "{$m[1]} {$m[2]}" : $c;
    }
}
