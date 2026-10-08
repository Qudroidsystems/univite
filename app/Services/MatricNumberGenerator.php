<?php

namespace App\Services;

use App\Models\AcademicSession;
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
        $known = ['YEAR', 'SHORT_YEAR', 'SESSION', 'FACULTY_CODE', 'DEPARTMENT_CODE', 'PROGRAMME_CODE'];
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

    /** What the next matric number would look like (not reserved). */
    public function preview(Programme $programme, AcademicSession $session, ?string $pattern = null): string
    {
        [$prefixKey, $width] = $this->resolve($pattern ?? self::pattern(), $programme, $session);

        return $this->format($prefixKey, $this->lastNumberFor($prefixKey) + 1, $width);
    }

    /** Reserve and return the next matric number. Safe against two admissions at once. */
    public function next(Programme $programme, AcademicSession $session): string
    {
        [$prefixKey, $width] = $this->resolve(self::pattern(), $programme, $session);

        return DB::transaction(function () use ($prefixKey, $width) {
            $row = DB::table('matric_sequences')->where('key', $prefixKey)->lockForUpdate()->first();
            $number = $row ? $row->last_number : $this->highestExisting($prefixKey, $width);

            do {
                $number++;
                $matric = $this->format($prefixKey, $number, $width);
            } while ($this->taken($matric));

            DB::table('matric_sequences')->updateOrInsert(
                ['key' => $prefixKey],
                ['last_number' => $number, 'updated_at' => now()] + ($row ? [] : ['created_at' => now()])
            );

            return $matric;
        });
    }

    /** A number is taken if a student holds it, or if it was cancelled (cancelled numbers are never reused). */
    public function taken(string $matric): bool
    {
        return Student::where('matric_number', $matric)->exists()
            || \App\Models\MatricEvent::where('action', 'cancelled')->where('matric_number', $matric)->exists();
    }

    /**
     * After numbers are undone, wind the sequence for this student's prefix back to the highest number still in use
     * so the freed numbers can be issued again.
     */
    public function rewind(Student $student): void
    {
        $student->loadMissing('programme.department.faculty', 'entrySession');
        [$prefixKey, $width] = $this->resolve(self::pattern(), $student->programme, $student->entrySession);

        $highest = $this->highestExisting($prefixKey, $width);
        $regex = '/^' . str_replace('\\{SEQ\\}', '(\\d+)', preg_quote($prefixKey, '/')) . '$/';
        \App\Models\MatricEvent::where('action', 'cancelled')->pluck('matric_number')->each(function ($m) use ($regex, &$highest) {
            if (preg_match($regex, (string) $m, $x)) {
                $highest = max($highest, (int) $x[1]);
            }
        });

        DB::table('matric_sequences')->where('key', $prefixKey)->update(['last_number' => $highest, 'updated_at' => now()]);
    }

    /** @return array{0: string, 1: int} the pattern with every token filled except {SEQ}, and the SEQ width */
    private function resolve(string $pattern, Programme $programme, AcademicSession $session): array
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

        Student::where('matric_number', 'like', $like)->pluck('matric_number')->each(function ($matric) use ($regex, &$max) {
            if (preg_match($regex, $matric, $m)) {
                $max = max($max, (int) $m[1]);
            }
        });

        return $max;
    }
}
