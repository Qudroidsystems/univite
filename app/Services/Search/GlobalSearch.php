<?php

namespace App\Services\Search;

use App\Data\Search\SearchHit;
use App\Models\Applicant;
use App\Models\Course;
use App\Models\Department;
use App\Models\Hostel;
use App\Models\Invoice;
use App\Models\LibraryItem;
use App\Models\Programme;
use App\Models\StaffProfile;
use App\Models\Student;
use App\Models\User;
use App\Models\Venue;
use App\Services\Access\ScopedAccess;
use Illuminate\Database\Eloquent\Builder;

/**
 * The records behind the search box: people, courses, invoices, books and places. Each kind is only searched for people
 * who may see it, and heads with a department or unit role only find records in their own department or unit.
 */
class GlobalSearch
{
    private const PER_KIND = 6;

    public function __construct(private ScopedAccess $scoped)
    {
    }

    /** @return array<int, SearchHit> */
    public function find(User $u, string $term): array
    {
        $term = trim($term);
        if (mb_strlen($term) < 2) {
            return [];
        }
        $like = '%' . addcslashes($term, '%_\\') . '%';

        return array_merge(
            $this->students($u, $like),
            $this->applicants($u, $like),
            $this->staff($u, $like),
            $this->users($u, $like),
            $this->courses($u, $like),
            $this->programmes($u, $like),
            $this->invoices($u, $like),
            $this->books($u, $like),
            $this->places($u, $like),
        );
    }

    /** Match any of the columns. */
    private function matching(Builder $q, string $like, array $columns): Builder
    {
        return $q->where(function (Builder $w) use ($like, $columns) {
            foreach ($columns as $c) {
                $w->orWhere($c, 'like', $like);
            }
        });
    }

    private function students(User $u, string $like): array
    {
        $scope = $this->scoped->scope($u, 'View students');
        if ($scope->isEmpty()) {
            return [];
        }

        return $scope->students($this->matching(Student::query(), $like, ['first_name', 'last_name', 'other_names', 'matric_number', 'provisional_matric']))
            ->with('programme')->limit(self::PER_KIND)->get()
            ->map(fn (Student $s) => new SearchHit($s->full_name, trim(($s->matric_number ?: $s->provisional_matric ?: 'No matric number yet') . ' · ' . $s->programme?->name, ' ·'), route('students.show', $s), 'ph-student', 'Students'))
            ->all();
    }

    private function applicants(User $u, string $like): array
    {
        if (! $u->can('View applicants')) {
            return [];
        }

        return $this->matching(Applicant::query(), $like, ['first_name', 'last_name', 'application_no', 'jamb_reg_no', 'email'])
            ->with('programme')->latest('id')->limit(self::PER_KIND)->get()
            ->map(fn (Applicant $a) => new SearchHit($a->full_name, trim($a->application_no . ' · ' . $a->programme?->name, ' ·'), route('applicants.show', $a), 'ph-user-plus', 'Applicants'))
            ->all();
    }

    private function staff(User $u, string $like): array
    {
        $scope = $this->scoped->scope($u, 'View staff records');
        if ($scope->isEmpty()) {
            return [];
        }
        $q = StaffProfile::query()->where(fn (Builder $w) => $w->where('staff_number', 'like', $like)
            ->orWhereHas('user', fn (Builder $x) => $x->where('name', 'like', $like)->orWhere('email', 'like', $like)));

        return $scope->staffProfiles($q)->with('user', 'department')->limit(self::PER_KIND)->get()
            ->map(fn (StaffProfile $p) => new SearchHit((string) $p->user?->name, trim($p->staff_number . ' · ' . ($p->designation ?: $p->department?->name), ' ·'), route('staff.show', $p), 'ph-identification-badge', 'Staff'))
            ->all();
    }

    private function users(User $u, string $like): array
    {
        if (! $u->can('View users')) {
            return [];
        }

        return $this->matching(User::query(), $like, ['name', 'email'])->with('roles')->limit(self::PER_KIND)->get()
            ->map(fn (User $x) => new SearchHit($x->name, trim($x->email . ' · ' . $x->roles->pluck('name')->take(2)->implode(', '), ' ·'), route('users.show', $x), 'ph-user-circle', 'User Accounts'))
            ->all();
    }

    private function courses(User $u, string $like): array
    {
        if (! $u->can('View courses')) {
            return [];
        }

        return $this->matching(Course::query(), $like, ['code', 'title'])->orderBy('code')->limit(self::PER_KIND)->get()
            ->map(fn (Course $c) => new SearchHit("{$c->code} {$c->title}", "{$c->credit_units} units · {$c->level} level", route('academics.courses.index', ['q' => $c->code]), 'ph-book-open', 'Courses'))
            ->all();
    }

    private function programmes(User $u, string $like): array
    {
        $hits = [];
        if ($u->can('View programmes')) {
            foreach ($this->matching(Programme::query(), $like, ['name', 'code'])->with('department')->limit(self::PER_KIND)->get() as $p) {
                $hits[] = new SearchHit($p->name, trim($p->code . ' · ' . $p->department?->name, ' ·'), route('academics.programmes.index', ['q' => $p->code ?: $p->name]), 'ph-graduation-cap', 'Programmes');
            }
        }
        if ($u->can('View departments')) {
            foreach ($this->matching(Department::query(), $like, ['name', 'code'])->with('faculty')->limit(self::PER_KIND)->get() as $d) {
                $hits[] = new SearchHit($d->name, trim($d->code . ' · ' . $d->faculty?->name, ' ·'), route('academics.departments.index', ['q' => $d->code ?: $d->name]), 'ph-buildings', 'Departments');
            }
        }

        return $hits;
    }

    private function invoices(User $u, string $like): array
    {
        if (! $u->can('View invoices')) {
            return [];
        }

        return Invoice::where(fn (Builder $w) => $w->where('reference', 'like', $like)
            ->orWhereHas('payments', fn (Builder $p) => $p->where('reference', 'like', $like)->orWhere('gateway_reference', 'like', $like)))
            ->with('payable')->latest('id')->limit(self::PER_KIND)->get()
            ->map(fn (Invoice $i) => new SearchHit($i->reference . ' · ' . $i->title, trim($i->payable?->full_name . ' · ' . (Invoice::STATUSES[$i->status] ?? $i->status), ' ·'), route('invoices.show', $i), 'ph-receipt', 'Invoices & Payments'))
            ->all();
    }

    private function books(User $u, string $like): array
    {
        if (! $u->canAny(['Edit library catalogue', 'Issue library loans', 'View library loans'])) {
            return [];
        }

        return $this->matching(LibraryItem::query(), $like, ['title', 'authors', 'isbn'])->limit(self::PER_KIND)->get()
            ->map(fn (LibraryItem $b) => new SearchHit($b->title, trim((string) $b->authors . ($b->year ? " · {$b->year}" : ''), ' ·') ?: 'Library catalogue', route('library.items.show', $b), 'ph-books', 'Library'))
            ->all();
    }

    private function places(User $u, string $like): array
    {
        $hits = [];
        if ($u->can('View venues')) {
            foreach ($this->matching(Venue::query(), $like, ['name', 'code', 'building'])->limit(self::PER_KIND)->get() as $v) {
                $hits[] = new SearchHit($v->name, trim($v->code . ' · capacity ' . $v->capacity, ' ·'), route('venues.show', $v), 'ph-map-pin', 'Venues');
            }
        }
        if ($u->canAny(['View accommodation', 'Set accommodation policy', 'Allocate accommodation', 'Check students in and out of hostels'])) {
            foreach ($this->matching(Hostel::query(), $like, ['name', 'code'])->limit(self::PER_KIND)->get() as $h) {
                $hits[] = new SearchHit($h->name, ucfirst((string) $h->gender) . ' hostel', route('hostels.show', $h), 'ph-bed', 'Hostels');
            }
        }

        return $hits;
    }
}
