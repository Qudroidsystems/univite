<?php

namespace App\Services\Api;

use App\Data\Academics\DepartmentLeaders;
use App\Data\Academics\Leader;
use App\Models\Course;
use App\Models\ResultStage;

/**
 * Who owns a course and approves its scores, for the app: the department and faculty, the head of department and the
 * dean, and the result approval steps. Scoped to the request, so a long course list looks each department up once.
 */
class CourseOwners
{
    /** @var array<int, array|null> department id => owner block */
    private array $owners = [];

    private ?array $chain = null;

    public function owner(Course $c): ?array
    {
        $id = $c->department_id;
        if (! $id) {
            return null;
        }
        if (! array_key_exists($id, $this->owners)) {
            $l = DepartmentLeaders::of($c->department);
            $d = $c->department;
            $this->owners[$id] = $l ? [
                'department' => ['id' => $d->id, 'name' => $d->name, 'code' => $d->code],
                'faculty' => $d->faculty ? ['id' => $d->faculty->id, 'name' => $d->faculty->name, 'code' => $d->faculty->code] : null,
                'hod' => $this->person($l->hod, $l->hodTitle),
                'dean' => $this->person($l->dean, $l->deanTitle),
            ] : null;
        }

        return $this->owners[$id];
    }

    private function person(?Leader $p, string $title): array
    {
        return $p
            ? ['title' => $p->role, 'name' => $p->name, 'staff_number' => $p->staffNumber, 'designation' => $p->designation, 'email' => $p->email, 'photo_url' => $p->photoUrl, 'initials' => $p->initials, 'assigned' => true]
            : ['title' => $title, 'name' => null, 'assigned' => false];
    }

    /** The result approval steps in order (Result Approval Steps), e.g. HOD, Dean, Senate. */
    public function chain(): array
    {
        return $this->chain ??= ResultStage::chain()->values()->map(fn ($s, $i) => ['step' => $i + 1, 'name' => $s->name, 'role' => $s->office_role])->all();
    }
}
