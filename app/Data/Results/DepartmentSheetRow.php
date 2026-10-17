<?php

namespace App\Data\Results;

use App\Models\ComputedResult;
use App\Models\CourseResult;
use Illuminate\Support\Collection;

/** One student on the department master sheet: the computed result and this semester's course scores. */
final readonly class DepartmentSheetRow
{
    /** @param  Collection<int, CourseResult>  $courses */
    public function __construct(public ComputedResult $computed, public Collection $courses)
    {
    }

    /** "BOT202" => ["BOT", "202"], so the sheet can print the subject over the number as the school does. */
    public static function split(string $code): array
    {
        return preg_match('/^([A-Za-z]+)\s*(.+)$/', trim($code), $m) ? [strtoupper($m[1]), $m[2]] : [$code, ''];
    }
}
