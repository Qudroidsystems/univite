<?php

namespace App\Data\Legacy;

/** What a check found (or an import did), as stored on the import and shown on its page. */
final readonly class ImportReport
{
    /**
     * @param  list<RowIssue>  $problems  (at most 2000 kept)
     * @param  list<RowIssue>  $warnings  (at most 500 kept)
     * @param  list<string>  $newSessions
     * @param  list<string>  $newCourses
     * @param  array<string, int>  $sessions  rows per session
     * @param  list<array>  $preview  the first good rows
     */
    public function __construct(
        public string $checkedAt,
        public int $rows,
        public int $ok,
        public int $existing,
        public array $problems,
        public int $problemCount,
        public array $warnings,
        public int $warningCount,
        public array $newSessions,
        public array $newCourses,
        public int $students,
        public array $sessions,
        public array $preview,
        public ?int $imported = null,
    ) {
    }

    public static function fromParsed(ParsedFile $p, ?int $imported = null): self
    {
        return new self(
            now()->toDateTimeString(), $p->rows, count($p->good), $p->existing,
            array_slice($p->problems, 0, 2000), count($p->problems), array_slice($p->warnings, 0, 500), count($p->warnings),
            array_values($p->newSessions), array_values(array_map(fn (NewCourse $c) => $c->label(), $p->newCourses)),
            count(array_unique(array_map(fn ($g) => $g->studentId, $p->good))),
            collect($p->good)->countBy(fn ($g) => $g->sessionName ?? 'Not given')->sortKeys()->all(),
            array_map(fn ($g) => $g->preview(), array_slice($p->good, 0, 10)), $imported,
        );
    }

    public static function fromArray(?array $a): ?self
    {
        if (! $a) {
            return null;
        }

        return new self(
            (string) $a['checked_at'], (int) $a['rows'], (int) $a['ok'], (int) $a['existing'],
            array_map([RowIssue::class, 'fromArray'], $a['problems'] ?? []), (int) $a['problem_count'],
            array_map([RowIssue::class, 'fromArray'], $a['warnings'] ?? []), (int) $a['warning_count'],
            $a['new_sessions'] ?? [], $a['new_courses'] ?? [], (int) $a['students'], $a['sessions'] ?? [], $a['preview'] ?? [],
            isset($a['imported']) ? (int) $a['imported'] : null,
        );
    }

    public function skipped(): int
    {
        return $this->problemCount + $this->existing;
    }

    public function withImported(int $n): self
    {
        return new self($this->checkedAt, $this->rows, $this->ok, $this->existing, $this->problems, $this->problemCount, $this->warnings, $this->warningCount,
            $this->newSessions, $this->newCourses, $this->students, $this->sessions, $this->preview, $n);
    }

    public function toArray(): array
    {
        return [
            'checked_at' => $this->checkedAt, 'rows' => $this->rows, 'ok' => $this->ok, 'existing' => $this->existing,
            'problems' => array_map(fn (RowIssue $i) => $i->toArray(), $this->problems), 'problem_count' => $this->problemCount,
            'warnings' => array_map(fn (RowIssue $i) => $i->toArray(), $this->warnings), 'warning_count' => $this->warningCount,
            'new_sessions' => $this->newSessions, 'new_courses' => $this->newCourses, 'students' => $this->students,
            'sessions' => $this->sessions, 'preview' => $this->preview, 'imported' => $this->imported,
        ];
    }
}
