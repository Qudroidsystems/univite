<?php

namespace App\Services\Campus;

use App\Data\Campus\Audience;
use App\Models\Department;
use App\Models\Faculty;
use App\Models\Programme;
use App\Models\StaffProfile;
use App\Models\Student;
use App\Models\StudentStatus;
use App\Models\User;
use App\Support\Lookups;
use Illuminate\Database\Eloquent\Builder;

/** Turns an Audience into the students and staff it covers, checks one person against it, and describes it in words. */
class AudienceResolver
{
    /** Enrolled students the audience covers (none when it is for staff only). */
    public function students(Audience $a): Builder
    {
        $q = Student::query()->whereIn('status', StudentStatus::codesWhere('is_enrolled'));
        if (! $a->includesStudents()) {
            return $q->whereRaw('1 = 0');
        }
        if ($a->programmes) {
            $q->whereIn('programme_id', $a->programmes);
        }
        if ($a->departments) {
            $q->whereHas('programme', fn ($p) => $p->whereIn('department_id', $a->departments));
        }
        if ($a->faculties) {
            $q->whereHas('programme.department', fn ($d) => $d->whereIn('faculty_id', $a->faculties));
        }
        if ($a->levels) {
            $q->whereIn('current_level', $a->levels);
        }
        if ($a->genders) {
            $q->whereIn('gender', $a->genders);
        }

        return $q;
    }

    /** Serving staff the audience covers. Programme and level limits are about students, so they do not narrow staff. */
    public function staff(Audience $a): Builder
    {
        $q = StaffProfile::query()->whereNull('exit_date');
        if (! $a->includesStaff()) {
            return $q->whereRaw('1 = 0');
        }
        if ($a->departments) {
            $q->whereIn('department_id', $a->departments);
        }
        if ($a->faculties) {
            $q->whereHas('department', fn ($d) => $d->whereIn('faculty_id', $a->faculties));
        }
        if ($a->genders) {
            $q->whereHas('user', fn ($u) => $u->whereIn('gender', $a->genders));
        }

        return $q;
    }

    /** User ids of everyone covered. */
    public function userIds(Audience $a): array
    {
        return $this->students($a)->pluck('user_id')->merge($this->staff($a)->pluck('user_id'))->filter()->unique()->values()->all();
    }

    public function count(Audience $a): int
    {
        return $this->students($a)->count() + $this->staff($a)->count();
    }

    public function includes(Audience $a, User $user): bool
    {
        if ($student = $user->student) {
            return $this->students($a)->whereKey($student->id)->exists();
        }
        if ($staff = $user->staffProfile) {
            return $this->staff($a)->whereKey($staff->id)->exists();
        }

        return false;
    }

    /** "Students of B.Ed. Mathematics, 300 and 400 level, female" */
    public function describe(Audience $a): string
    {
        $parts = [];
        if ($a->faculties) {
            $parts[] = Faculty::whereIn('id', $a->faculties)->orderBy('name')->pluck('name')->implode(', ');
        }
        if ($a->departments) {
            $parts[] = Department::whereIn('id', $a->departments)->orderBy('name')->pluck('name')->implode(', ');
        }
        if ($a->programmes && $a->includesStudents()) {
            $parts[] = Programme::whereIn('id', $a->programmes)->orderBy('name')->pluck('name')->implode(', ');
        }
        if ($a->levels && $a->includesStudents()) {
            $levels = collect($a->levels)->sort()->values();
            $parts[] = ($levels->count() > 1 ? $levels->slice(0, -1)->implode(', ') . ' and ' . $levels->last() : $levels->first()) . ' level';
        }
        if ($a->genders) {
            $parts[] = collect($a->genders)->map(fn ($g) => strtolower(Lookups::label('gender', $g)))->implode(' or ');
        }
        $who = ['students' => 'All students', 'staff' => 'All staff', 'everyone' => 'All students and staff'][$a->who];

        return $parts ? ucfirst(preg_replace('/^All /', '', $who)) . ': ' . implode('; ', $parts) : $who;
    }
}
