<?php

namespace App\Data\Results;

use Illuminate\Http\Request;

/** A request to waive one carry-over course for one student, with the department's reason. */
final readonly class CarryoverWaiverRequest
{
    public function __construct(public int $studentId, public int $courseId, public string $reason)
    {
    }

    public static function fromRequest(Request $request): self
    {
        $d = $request->validate([
            'student_id' => ['required', 'integer', 'exists:students,id'],
            'course_id' => ['required', 'integer', 'exists:courses,id'],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ]);

        return new self((int) $d['student_id'], (int) $d['course_id'], trim($d['reason']));
    }
}
