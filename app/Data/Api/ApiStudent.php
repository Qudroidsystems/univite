<?php

namespace App\Data\Api;

use App\Models\Student;

/** A student as the mobile app sees them. */
final readonly class ApiStudent
{
    public function __construct(
        public int $id, public ?string $matricNumber, public string $name, public ?string $programme, public ?string $department,
        public ?string $faculty, public ?int $level, public ?string $entrySession, public ?string $status, public ?string $photoUrl,
        public ?string $courseOption = null, public ?string $subDepartment = null, public ?string $jambRegNo = null, public ?string $semester = null,
    ) {
    }

    public static function from(Student $s): self
    {
        $s->loadMissing('programme.department.faculty', 'entrySession', 'courseOption', 'subDepartment', 'currentSemesterType');
        $showJamb = \App\Models\Setting::get('students.show_jamb', '1') === '1';

        return new self($s->id, $s->matric_number ?: $s->provisional_matric, $s->full_name, $s->programme?->name, $s->programme?->department?->name,
            $s->programme?->department?->faculty?->name, $s->current_level, $s->entrySession?->name, $s->status, $s->photo_url,
            $s->courseOption?->name, $s->subDepartment?->name, $showJamb ? $s->jamb_reg_no : null, $s->currentSemesterType?->name);
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id, 'matric_number' => $this->matricNumber, 'name' => $this->name, 'programme' => $this->programme,
            'department' => $this->department, 'faculty' => $this->faculty, 'level' => $this->level, 'entry_session' => $this->entrySession,
            'status' => $this->status, 'photo_url' => $this->photoUrl, 'course_option' => $this->courseOption, 'sub_department' => $this->subDepartment,
            'jamb_reg_no' => $this->jambRegNo, 'semester' => $this->semester,
        ];
    }
}
