<?php

namespace App\Http\Controllers\Academics;

use App\Models\Student;
use App\Models\StudentStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\Rule;

class StudentStatusController extends CrudController
{
    protected string $permission = 'student statuses';
    protected string $title = 'Student Statuses';
    protected string $singular = 'Student status';
    protected string $icon = 'ri-user-settings-line';
    protected string $routeBase = 'academics.student-statuses';
    protected string $intro = 'The standings a student can have (Active, Deferred, Probation, Suspended ...). Rename them or add your own. The switches decide what each status allows: signing in, registering courses, paying fees, and whether the student counts as enrolled (and so gets a fees invoice). Exactly one status is the default given to new students.';
    protected array $searchable = ['name', 'code'];

    protected function model(): string
    {
        return StudentStatus::class;
    }

    protected function query(Request $request): Builder
    {
        return parent::query($request)->reorder()->orderBy('sort_order');
    }

    protected function rules(Request $request, ?Model $record): array
    {
        return [
            'code'         => $record ? ['prohibited'] : ['required', 'string', 'max:30', 'regex:/^[a-z0-9_]+$/', Rule::unique('student_statuses', 'code')],
            'name'         => ['required', 'string', 'max:100'],
            'badge'        => ['required', Rule::in(array_keys(StudentStatus::BADGES))],
            'sort_order'   => ['required', 'integer', 'between:0,999'],
            'can_login'    => ['nullable', 'boolean'],
            'can_register' => ['nullable', 'boolean'],
            'can_pay'      => ['nullable', 'boolean'],
            'is_enrolled'  => ['nullable', 'boolean'],
            'is_default'   => ['nullable', 'boolean'],
            'is_active'    => ['nullable', 'boolean'],
        ];
    }

    protected function fields(): array
    {
        return [
            ['name' => 'name', 'label' => 'Name', 'type' => 'text', 'required' => true, 'col' => 6, 'placeholder' => 'On probation'],
            ['name' => 'code', 'label' => 'Code (cannot change later)', 'type' => 'text', 'required' => true, 'col' => 6, 'only' => 'create', 'placeholder' => 'probation', 'help' => 'Lowercase letters, numbers and underscores.'],
            ['name' => 'badge', 'label' => 'Colour', 'type' => 'select', 'options' => StudentStatus::BADGES, 'required' => true, 'col' => 6, 'default' => 'secondary'],
            ['name' => 'sort_order', 'label' => 'Order', 'type' => 'number', 'required' => true, 'col' => 6, 'default' => 10],
            ['name' => 'can_login', 'label' => 'Can sign in', 'type' => 'switch', 'col' => 6, 'default' => true],
            ['name' => 'can_register', 'label' => 'Can register courses', 'type' => 'switch', 'col' => 6],
            ['name' => 'can_pay', 'label' => 'Can pay fees', 'type' => 'switch', 'col' => 6, 'default' => true],
            ['name' => 'is_enrolled', 'label' => 'Counts as enrolled (gets a fees invoice)', 'type' => 'switch', 'col' => 6],
            ['name' => 'is_default', 'label' => 'Default for new students', 'type' => 'switch', 'col' => 6],
            ['name' => 'is_active', 'label' => 'Active (can be chosen)', 'type' => 'switch', 'col' => 6, 'default' => true],
        ];
    }

    protected function columns(): array
    {
        $yes = fn ($v) => new HtmlString($v ? '<i class="ri-check-line text-success"></i>' : '<span class="text-muted">—</span>');

        return [
            ['Status', fn ($r) => new HtmlString('<span class="badge ' . StudentStatus::badgeClass($r->code) . '">' . e($r->name) . '</span>' . ($r->is_default ? ' <span class="badge bg-light text-body border ms-1">Default</span>' : '') . ($r->is_active ? '' : ' <span class="badge bg-secondary-subtle text-secondary ms-1">Inactive</span>'))],
            ['Code', fn ($r) => $r->code],
            ['Sign in', fn ($r) => $yes($r->can_login)],
            ['Register', fn ($r) => $yes($r->can_register)],
            ['Pay', fn ($r) => $yes($r->can_pay)],
            ['Enrolled', fn ($r) => $yes($r->is_enrolled)],
        ];
    }

    protected function saved(Model $record, Request $request, bool $created): void
    {
        // Only one default; if this one just became the default, clear the others.
        if ($record->is_default) {
            StudentStatus::where('id', '!=', $record->id)->update(['is_default' => false]);
            StudentStatus::flush();
        }
        // Sign-in rights follow the status for students already holding it.
        \App\Models\User::whereIn('id', Student::where('status', $record->code)->pluck('user_id'))->update(['is_disabled' => ! $record->can_login]);
    }

    protected function blockDelete(Model $record): ?string
    {
        if ($record->is_default) {
            return 'This is the default status for new students. Make another status the default first.';
        }

        return Student::where('status', $record->code)->exists() ? 'Students currently hold this status. Move them to another status, or mark this one inactive.' : null;
    }
}
