<?php

namespace App\Services;

use App\Models\AcademicSession;
use App\Models\MatricFormat;
use App\Models\Programme;
use App\Models\Setting;
use App\Models\Student;
use Illuminate\Support\Facades\DB;

/**
 * Builds matric numbers from a pattern the administrator controls, e.g. {YEAR}/{PROGRAMME_CODE}/{SEQ:4}
 * gives 2025/BSC-CSC/0001, 2025/BSC-CSC/0002 ... The sequence restarts for every different prefix
 * (so per programme and entry year with the default pattern).
 */
class MatricNumberGenerator
{
    public const SETTING_KEY = 'matric_pattern';
    public const DEFAULT_PATTERN = '{YEAR}/{PROGRAMME_CODE}/{SEQ:4}';

    /** token => what it prints */
    public const TOKENS = [
        'YEAR'            => 'First year of the entry session, e.g. 2025 for 2025/2026',
        'SHORT_YEAR'      => 'Last two digits of that year, e.g. 25',
        'SESSION'         => 'The entry session as written, e.g. 2025/2026',
        'FACULTY_CODE'    => 'Code of the faculty, e.g. SCI',
        'DEPARTMENT_CODE' => 'Code of the department, e.g. CSC',
        'PROGRAMME_CODE'  => 'Code of the programme, e.g. BSC-CSC',
        'PREFIX'          => 'The prefix set on a department or category format, e.g. REL',
        'SUFFIX'          => 'The suffix set on a format, e.g. U for UTME or D for direct entry',
        'SEQ:n'           => 'Running number padded to n digits (1 to 8), e.g. {SEQ:4} gives 0001. Required.',
    ];

    /** When a matric number is issued: after clearance (the default) or straight at admission. */
    public static function issueAt(): string
    {
        return Setting::get('matric.issue_at', 'clearance') === 'admission' ? 'admission' : 'clearance';
    }

    /** After clearance: issue the number the moment the last item is cleared (true), or wait for staff to generate a batch (false). */
    public static function autoIssue(): bool
    {
        return Setting::get('matric.auto_issue', '1') === '1';
    }

    /** Numbers are reserved first and only published when a batch is approved (by Senate, a committee or the person in charge). */
    public static function requiresApproval(): bool
    {
        return Setting::get('matric.require_approval', '0') === '1';
    }

    /** The person who generated a batch may not approve it. */
    public static function separateApprover(): bool
    {
        return Setting::get('matric.separate_approver', '1') === '1';
    }

    public static function pattern(): string
    {
        return Setting::get(self::SETTING_KEY, self::DEFAULT_PATTERN) ?: self::DEFAULT_PATTERN;
    }

    /** Returns an error message, or null when the pattern is acceptable. */
    public static function validate(string $pattern): ?string
    {
        if (strlen($pattern) > 60) {
            return 'The pattern is too long (60 characters at most).';
        }

        preg_match_all('/\{([^}]*)\}/', $pattern, $m);
        $known = ['YEAR', 'SHORT_YEAR', 'SESSION', 'FACULTY_CODE', 'DEPARTMENT_CODE', 'PROGRAMME_CODE', 'PREFIX', 'SUFFIX'];
        $seq = 0;

        foreach ($m[1] as $token) {
            if (preg_match('/^SEQ:([1-8])$/', $token)) {
                $seq++;
            } elseif (! in_array($token, $known, true)) {
                return "Unknown part {{$token}}. Use only the parts listed below.";
            }
        }

        if ($seq !== 1) {
            return 'The pattern must contain exactly one running number such as {SEQ:4}.';
        }

        if (preg_match('/[{}]/', preg_replace('/\{[^}]*\}/', '', $pattern))) {
            return 'Unmatched curly bracket in the pattern.';
        }

        if (preg_match('/[\s\\\\]/', $pattern)) {
            return 'The pattern cannot contain spaces or backslashes.';
        }

        return null;
    }

    /**
     * The format for a student of this programme and entry mode: the most specific active format under
     * Matric Number Formats that matches, or null for the school-wide pattern.
     */
    public function formatFor(Programme $programme, ?string $entryMode = null): ?MatricFormat
    {
        return MatricFormat::where('is_active', true)->get()
            ->filter(fn (MatricFormat $f) => $f->matches($programme, $entryMode))
            ->sortByDesc(fn (MatricFormat $f) => $f->weight())->first();
    }

    /** What the next matric number would look like (not reserved). */
    public function preview(Programme $programme, AcademicSession $session, ?string $pattern = null, ?string $entryMode = null): string
    {
        $format = $pattern === null ? $this->formatFor($programme, $entryMode) : null;
        [$prefixKey, $width] = $this->resolve($pattern ?? $format?->pattern ?? self::pattern(), $programme, $session, $format);

        return $this->format($prefixKey, max($this->lastNumberFor($prefixKey), ($format?->serial_from ?? 1) - 1) + 1, $width);
    }

    /** Reserve and return the next matric number. Safe against two admissions at once. */
    public function next(Programme $programme, AcademicSession $session, ?string $entryMode = null): string
    {
        $format = $this->formatFor($programme, $entryMode);
        [$prefixKey, $width] = $this->resolve($format?->pattern ?? self::pattern(), $programme, $session, $format);

        return DB::transaction(function () use ($prefixKey, $width, $format) {
            $row = DB::table('matric_sequences')->where('key', $prefixKey)->lockForUpdate()->first();
            $number = max($row ? $row->last_number : $this->highestExisting($prefixKey, $width), ($format?->serial_from ?? 1) - 1);

            do {
                $number++;
                $matric = $this->format($prefixKey, $number, $width);
            } while ($this->taken($matric));
            if ($format?->serial_to && $number > $format->serial_to) {
                throw new \RuntimeException("The matric number range for \"{$format->name}\" ends at {$format->serial_to}. Raise it under Matric Number Formats.");
            }

            DB::table('matric_sequences')->updateOrInsert(
                ['key' => $prefixKey],
                ['last_number' => $number, 'updated_at' => now()] + ($row ? [] : ['created_at' => now()])
            );

            return $matric;
        });
    }

    /** Numbers that are never handed out again: cancelled ones, and ones replaced by a correction (they were printed). */
    public const RETIRED = ['cancelled', 'superseded'];

    /** A number is taken if a student holds it, or if it was cancelled or superseded. */
    public function taken(string $matric): bool
    {
        return Student::where('matric_number', $matric)->orWhere('provisional_matric', $matric)->exists()
            || \App\Models\MatricEvent::whereIn('action', self::RETIRED)->where('matric_number', $matric)->exists();
    }

    /**
     * The numbers next() would hand these students, in this order, without reserving anything.
     *
     * @param iterable<Student> $students
     * @return array<int, string> student id => number
     */
    public function planFor(iterable $students): array
    {
        $counters = [];
        $planned = [];
        foreach ($students as $student) {
            $student->loadMissing('programme.department.faculty', 'entrySession');
            $format = $this->formatFor($student->programme, $student->entry_mode);
            [$prefixKey, $width] = $this->resolve($format?->pattern ?? self::pattern(), $student->programme, $student->entrySession, $format);
            $number = $counters[$prefixKey] ??= max(DB::table('matric_sequences')->where('key', $prefixKey)->value('last_number') ?? $this->highestExisting($prefixKey, $width), ($format?->serial_from ?? 1) - 1);
            do {
                $number++;
                $matric = $this->format($prefixKey, $number, $width);
            } while ($this->taken($matric) || in_array($matric, $planned, true));
            $counters[$prefixKey] = $number;
            $planned[$student->id] = $matric;
        }

        return $planned;
    }

    /**
     * After numbers are undone, wind the sequence for this student's prefix back to the highest number still in use
     * so the freed numbers can be issued again.
     */
    public function rewind(Student $student): void
    {
        $student->loadMissing('programme.department.faculty', 'entrySession');
        $format = $this->formatFor($student->programme, $student->entry_mode);
        [$prefixKey, $width] = $this->resolve($format?->pattern ?? self::pattern(), $student->programme, $student->entrySession, $format);

        $highest = $this->highestExisting($prefixKey, $width);
        $regex = '/^' . str_replace('\\{SEQ\\}', '(\\d+)', preg_quote($prefixKey, '/')) . '$/';
        \App\Models\MatricEvent::whereIn('action', self::RETIRED)->pluck('matric_number')->each(function ($m) use ($regex, &$highest) {
            if (preg_match($regex, (string) $m, $x)) {
                $highest = max($highest, (int) $x[1]);
            }
        });

        DB::table('matric_sequences')->where('key', $prefixKey)->update(['last_number' => $highest, 'updated_at' => now()]);
    }

    /**
     * The running numbers for every active programme in an entry session: the prefix, the last number used (from the
     * saved sequence or, failing that, detected from numbers already issued, reserved or retired) and the next number.
     *
     * @return array<int, array{prefix: string, programmes: string[], last: int, highest: int, next: string}>
     */
    public function sequences(AcademicSession $session): array
    {
        $out = [];
        foreach (Programme::with('department.faculty')->where('is_active', true)->orderBy('name')->get() as $programme) {
            $format = $this->formatFor($programme);
            [$prefixKey, $width] = $this->resolve($format?->pattern ?? self::pattern(), $programme, $session, $format);
            if (isset($out[$prefixKey])) {
                $out[$prefixKey]['programmes'][] = $programme->name;
                continue;
            }
            $highest = $this->highestInUse($prefixKey, $width);
            $last = max($highest, $this->lastNumberFor($prefixKey));
            $out[$prefixKey] = ['prefix' => $prefixKey, 'programmes' => [$programme->name], 'last' => $last, 'highest' => $highest, 'next' => $this->format($prefixKey, $last + 1, $width)];
        }

        return array_values($out);
    }

    /**
     * Continue after a number used outside the portal (e.g. the last number issued before the portal), so the next
     * number is $last + 1. It can never go below a number already in use, so it cannot create a duplicate.
     */
    public function setLastUsed(string $prefixKey, int $last): void
    {
        $highest = $this->highestInUse($prefixKey, 1);
        if ($last < $highest) {
            throw new \RuntimeException("Numbers up to {$highest} are already in use for this prefix; the last number cannot be set lower.");
        }
        DB::table('matric_sequences')->updateOrInsert(['key' => $prefixKey], ['last_number' => $last, 'updated_at' => now(), 'created_at' => now()]);
    }

    /** Highest number in use for a prefix: held, reserved, cancelled or superseded. */
    private function highestInUse(string $prefixKey, int $width): int
    {
        $highest = $this->highestExisting($prefixKey, $width);
        $regex = '/^' . str_replace('\\{SEQ\\}', '(\\d+)', preg_quote($prefixKey, '/')) . '$/';
        \App\Models\MatricEvent::whereIn('action', self::RETIRED)->pluck('matric_number')->each(function ($m) use ($regex, &$highest) {
            if (preg_match($regex, (string) $m, $x)) {
                $highest = max($highest, (int) $x[1]);
            }
        });

        return $highest;
    }

    /** @return array{0: string, 1: int} the pattern with every token filled except {SEQ}, and the SEQ width */
    private function resolve(string $pattern, Programme $programme, AcademicSession $session, ?MatricFormat $format = null): array
    {
        $programme->loadMissing('department.faculty');
        $year = substr($session->name, 0, 4);

        $values = [
            '{YEAR}'            => $year,
            '{SHORT_YEAR}'      => substr($year, 2, 2),
            '{SESSION}'         => $session->name,
            '{FACULTY_CODE}'    => $programme->department->faculty->code,
            '{DEPARTMENT_CODE}' => $programme->department->code,
            '{PROGRAMME_CODE}'  => $programme->code,
            '{PREFIX}'          => (string) $format?->prefix,
            '{SUFFIX}'          => (string) $format?->suffix,
        ];

        preg_match('/\{SEQ:(\d)\}/', $pattern, $m);

        return [str_replace(array_keys($values), array_values($values), preg_replace('/\{SEQ:\d\}/', '{SEQ}', $pattern)), (int) ($m[1] ?? 4)];
    }

    private function format(string $prefixKey, int $number, int $width): string
    {
        return str_replace('{SEQ}', str_pad((string) $number, $width, '0', STR_PAD_LEFT), $prefixKey);
    }

    private function lastNumberFor(string $prefixKey): int
    {
        return (int) (DB::table('matric_sequences')->where('key', $prefixKey)->value('last_number') ?? 0);
    }

    /** First use of a prefix: carry on after any matric numbers that already follow this shape. */
    private function highestExisting(string $prefixKey, int $width): int
    {
        $regex = '/^' . str_replace('\{SEQ\}', '(\d+)', preg_quote($prefixKey, '/')) . '$/';
        [$like] = [str_replace('{SEQ}', '%', $prefixKey)];
        $max = 0;

        Student::where('matric_number', 'like', $like)->pluck('matric_number')
            ->merge(Student::where('provisional_matric', 'like', $like)->pluck('provisional_matric'))
            ->each(function ($matric) use ($regex, &$max) {
                if (preg_match($regex, $matric, $m)) {
                    $max = max($max, (int) $m[1]);
                }
            });

        return $max;
    }
}
