<?php

namespace App\View\Components;

use App\Models\Course;
use App\Support\CourseType;
use Illuminate\View\Component;
use Illuminate\View\View;

/**
 * The marks shown beside a course wherever it appears: its type (C, CE or E, named in full on hover) and the
 * department that owns it. Pass the curriculum's status as type where the programme's own type applies.
 */
class CourseTag extends Component
{
    public string $typeCode;

    public function __construct(public Course $course, ?string $type = null, public bool $department = true, public bool $full = false, public bool $faculty = false)
    {
        $this->typeCode = $type ?: ($course->course_type ?: CourseType::COMPULSORY);
    }

    public function render(): View
    {
        return view('components.course-tag', [
            'short' => CourseType::short($this->typeCode), 'label' => CourseType::label($this->typeCode), 'badge' => CourseType::BADGES[$this->typeCode] ?? 'bg-light text-body',
            'dept' => $this->department ? $this->course->department : null,
            'fac' => $this->department && $this->faculty ? $this->course->department?->faculty : null,
        ]);
    }
}
