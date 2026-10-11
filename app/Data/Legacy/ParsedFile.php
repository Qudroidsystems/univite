<?php

namespace App\Data\Legacy;

/** Everything learned from reading every row of a file: the good rows and why the others are left out. */
final class ParsedFile
{
    /** @var list<ResultRow|BalanceRow> */
    public array $good = [];

    /** @var list<RowIssue> */
    public array $problems = [];

    /** @var list<RowIssue> */
    public array $warnings = [];

    public int $existing = 0;

    /** @var array<string, string> session names to add */
    public array $newSessions = [];

    /** @var array<string, NewCourse> course key => course to add */
    public array $newCourses = [];

    public function __construct(public readonly int $rows)
    {
    }

    public function problem(int $row, string $matric, string $message): void
    {
        $this->problems[] = new RowIssue($row, $matric, $message);
    }

    public function warning(int $row, string $matric, string $message): void
    {
        $this->warnings[] = new RowIssue($row, $matric, $message);
    }
}
