<?php

namespace App\Services\Lookups;

use App\Data\Lookups\LookupOption;
use App\Data\Lookups\LookupQuery;
use App\Models\AcademicSession;
use App\Models\BillItem;
use App\Models\Course;
use App\Models\Department;
use App\Models\EntryMode;
use App\Models\Faculty;
use App\Models\Hostel;
use App\Models\LeaveType;
use App\Models\Programme;
use App\Models\SalaryStructure;
use App\Models\SemesterType;
use App\Models\StudyCategory;
use App\Models\TpSchool;
use App\Models\Unit;
use App\Models\Venue;
use Illuminate\Database\Eloquent\Builder;

/**
 * Reference lists that dropdowns fetch when they are opened, so a faculty, programme or session added on another
 * page shows up without reloading. Only reference data lives here; records about people never do.
 * To add a list, add a case to options() and use its name in a select's data-lookup attribute.
 */
class LookupService
{
    /** Most options one list returns. */
    public const LIMIT = 500;

    /** @return string[] */
    public function lists(): array
    {
        return ['sessions', 'semester-types', 'semester-orders', 'faculties', 'departments', 'programmes', 'courses', 'bill-items',
            'study-categories', 'entry-modes', 'sub-departments', 'course-options', 'units', 'leave-types', 'hostels', 'venues', 'tp-schools', 'salary-structures'];
    }

    /** @return LookupOption[]|null null when there is no such list */
    public function options(string $list, LookupQuery $query): ?array
    {
        return match ($list) {
            'sessions' => $this->pairs($this->search(AcademicSession::query(), $query, ['name'])->orderByDesc('name'), 'id', fn ($s) => $s->name),
            'semester-types' => $this->pairs($this->filter(SemesterType::query(), $query, ['name'])->orderBy('sort_order'), 'id', fn ($s) => $s->name),
            'semester-orders' => $this->pairs($this->search(SemesterType::query()->where('is_active', true), $query, ['name'])->orderBy('sort_order'), 'sort_order', fn ($s) => $s->name),
            'faculties' => $this->pairs($this->filter(Faculty::query(), $query, ['name'])->orderBy('name'), 'id', fn ($f) => $f->name),
            'departments' => $this->pairs(
                $this->filter(Department::query()->with('faculty'), $query, ['name'])
                    ->when($query->parent, fn ($q, $id) => $q->where('faculty_id', $id))->orderBy('name'),
                'id', fn ($d) => $d->name, fn ($d) => $d->faculty?->name, sortByGroup: true,
            ),
            'programmes' => $this->pairs(
                $this->filter(Programme::query(), $query, ['name', 'code'])
                    ->when($query->parent, fn ($q, $id) => $q->where('department_id', $id))->orderBy('name'),
                'id', fn ($p) => "{$p->name} ({$p->code})",
            ),
            'courses' => $this->pairs(
                $this->filter(Course::query(), $query, ['code', 'title'])
                    ->when($query->parent, fn ($q, $id) => $q->where('department_id', $id))->orderBy('code'),
                'id', fn ($c) => "{$c->code} · {$c->title}",
            ),
            'bill-items' => $this->pairs($this->filter(BillItem::query(), $query, ['name', 'code'])->orderBy('name'), 'id', fn ($b) => $b->name),
            'study-categories' => $this->pairs($this->filter(StudyCategory::query(), $query, ['name'])->orderBy('name'), 'id', fn ($s) => $s->name),
            'entry-modes' => $this->pairs($this->search(EntryMode::query()->where('is_active', true), $query, ['name'])->orderBy('sort_order'), 'code', fn ($e) => $e->name),
            'sub-departments' => $this->pairs(
                $this->filter(\App\Models\SubDepartment::query(), $query, ['name', 'code'])
                    ->when($query->parent, fn ($q, $id) => $q->where('department_id', $id))->orderBy('name'),
                'id', fn ($d) => $d->name,
            ),
            'course-options' => $this->pairs(
                $this->filter(\App\Models\CourseOption::query(), $query, ['name', 'code'])
                    ->when($query->parent, fn ($q, $id) => $q->where('programme_id', $id))->orderBy('name'),
                'id', fn ($o) => $o->name,
            ),
            'units' => $this->pairs($this->filter(Unit::query(), $query, ['name'])->orderBy('name'), 'id', fn ($u) => $u->name),
            'leave-types' => $this->pairs($this->filter(LeaveType::query(), $query, ['name'])->orderBy('sort_order'), 'id', fn ($t) => $t->name),
            'hostels' => $this->pairs($this->filter(Hostel::query(), $query, ['name'])->orderBy('sort_order')->orderBy('name'), 'id', fn ($h) => $h->name),
            'venues' => $this->pairs($this->filter(Venue::query(), $query, ['code', 'name'])->orderBy('code'), 'id', fn ($v) => $v->code),
            'tp-schools' => $this->pairs($this->filter(TpSchool::query(), $query, ['name'])->orderBy('name'), 'id', fn ($s) => $s->name),
            'salary-structures' => $this->pairs($this->filter(SalaryStructure::query(), $query, ['code', 'name'])->orderBy('code'), 'id', fn ($s) => $s->code),
            default => null,
        };
    }

    /** Active rows only when asked, then the search text. */
    private function filter(Builder $q, LookupQuery $query, array $columns): Builder
    {
        return $this->search($q->when($query->activeOnly, fn ($w) => $w->where('is_active', true)), $query, $columns);
    }

    private function search(Builder $q, LookupQuery $query, array $columns): Builder
    {
        if ($query->term === null) {
            return $q;
        }
        $like = '%' . addcslashes($query->term, '%_\\') . '%';

        return $q->where(function ($w) use ($columns, $like) {
            foreach ($columns as $column) {
                $w->orWhere($column, 'like', $like);
            }
        });
    }

    /** @return LookupOption[] */
    private function pairs(Builder $q, string $key, callable $label, ?callable $group = null, bool $sortByGroup = false): array
    {
        $rows = $q->limit(self::LIMIT)->get();
        if ($sortByGroup && $group) {
            $rows = $rows->sortBy(fn ($r) => [(string) $group($r), (string) $label($r)])->values();
        }

        return $rows->map(fn ($r) => new LookupOption((string) $r->{$key}, (string) $label($r), $group ? $group($r) : null))->all();
    }
}
