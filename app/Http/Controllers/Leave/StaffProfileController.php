<?php

namespace App\Http\Controllers\Leave;

use App\Models\Department;
use App\Models\StaffProfile;
use App\Models\User;
use App\Support\Lookups;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\Rule;

class StaffProfileController extends PolicyCrud
{
    protected string $permission = 'staff profiles';
    protected string $gate = 'Edit staff profiles';
    protected array $viewers = ['View leave applications'];
    protected string $title = 'Staff Profiles';
    protected string $singular = 'Staff profile';
    protected string $icon = 'ri-id-card-line';
    protected string $routeBase = 'leave.staff';
    protected string $intro = 'Employment details used by leave: department (for the head of department and coverage), category, grade, appointment, first appointment date (years of service), supervisor, and whether the role must be covered while the person is away.';
        protected int $perPage = 50;
    protected array $actions = ['create', 'edit'];

    protected function model(): string
    {
        return StaffProfile::class;
    }

    protected function query(Request $request): \Illuminate\Database\Eloquent\Builder
    {
        $q = parent::query($request)->with('user', 'department', 'supervisor');
        if (($s = trim((string) $request->query('q'))) !== '') {
            $q->where(fn ($w) => $w->where('staff_number', 'like', "%{$s}%")->orWhere('designation', 'like', "%{$s}%")
                ->orWhereHas('user', fn ($u) => $u->where('name', 'like', "%{$s}%")->orWhere('email', 'like', "%{$s}%")));
        }

        return $q;
    }

    protected function filters(): array
    {
        return [['department_id', 'Department', Department::orderBy('name')->pluck('name', 'id')->all()], ['category', 'Category', Lookups::options('staff_category')]];
    }

    private function staffOptions(): array
    {
        return User::whereDoesntHave('student')->orderBy('name')->pluck('name', 'id')->all();
    }

    protected function rules(Request $request, ?Model $record): array
    {
        return [
            'user_id' => $record ? ['nullable'] : ['required', 'exists:users,id', Rule::unique('staff_profiles', 'user_id')],
            'staff_number' => ['nullable', 'string', 'max:40', Rule::unique('staff_profiles', 'staff_number')->ignore($record?->id)],
            'department_id' => ['nullable', 'exists:departments,id'], 'unit' => ['nullable', 'string', 'max:150'], 'designation' => ['nullable', 'string', 'max:150'],
            'category' => ['nullable', 'string', 'max:40'], 'appointment_type' => ['nullable', 'string', 'max:40'], 'grade_level' => ['nullable', 'integer', 'between:1,20'],
            'first_appointment_date' => ['nullable', 'date', 'before_or_equal:today'], 'supervisor_id' => ['nullable', 'exists:users,id'], 'critical_role' => ['nullable', 'boolean'],
        ];
    }

    protected function fields(): array
    {
        return [
            ['name' => 'user_id', 'label' => 'Member of staff', 'type' => 'select', 'options' => $this->staffOptions(), 'required' => true, 'col' => 6, 'only' => 'create'],
            ['name' => 'staff_number', 'label' => 'Staff number', 'col' => 3],
            ['name' => 'grade_level', 'label' => 'Grade level', 'type' => 'number', 'col' => 3],
            ['name' => 'department_id', 'label' => 'Department', 'type' => 'select', 'options' => Department::orderBy('name')->pluck('name', 'id')->all(), 'blank' => 'None (non-teaching unit)', 'col' => 6],
            ['name' => 'unit', 'label' => 'Unit / office', 'col' => 6, 'placeholder' => 'Bursary, Registry …'],
            ['name' => 'designation', 'label' => 'Designation', 'col' => 6, 'placeholder' => 'Senior Lecturer'],
            ['name' => 'category', 'label' => 'Category', 'type' => 'select', 'options' => Lookups::options('staff_category'), 'blank' => 'Not set', 'col' => 3],
            ['name' => 'appointment_type', 'label' => 'Appointment', 'type' => 'select', 'options' => Lookups::options('appointment_type'), 'blank' => 'Not set', 'col' => 3],
            ['name' => 'first_appointment_date', 'label' => 'First appointment', 'type' => 'date', 'col' => 4],
            ['name' => 'supervisor_id', 'label' => 'Supervisor', 'type' => 'select', 'options' => $this->staffOptions(), 'blank' => 'None', 'col' => 5],
            ['name' => 'critical_role', 'label' => 'Must be covered when away', 'type' => 'switch', 'col' => 3],
        ];
    }

    protected function viewData(Request $request): array
    {
        return parent::viewData($request) + ['canSearch' => true];
    }

    protected function prepare(array $data, Request $request, ?Model $record): array
    {
        if ($record) {
            unset($data['user_id']); // the person a profile belongs to never changes
        }

        return $data;
    }

    protected function columns(): array
    {
        return [
            ['Staff', fn ($r) => new HtmlString('<strong>' . e($r->user?->name) . '</strong><div class="text-muted fs-12">' . e($r->staff_number ?: 'no staff number') . ($r->designation ? ' · ' . e($r->designation) : '') . '</div>')],
            ['Department / unit', fn ($r) => $r->department?->name ?? $r->unit ?? '—'],
            ['Category', fn ($r) => collect([$r->category ? Lookups::label('staff_category', $r->category) : null, $r->grade_level ? 'GL ' . $r->grade_level : null, $r->appointment_type ? Lookups::label('appointment_type', $r->appointment_type) : null])->filter()->implode(' · ') ?: '—'],
            ['Service', fn ($r) => $r->first_appointment_date ? intdiv($r->serviceMonths(), 12) . ' yr since ' . $r->first_appointment_date->format('Y') : '—'],
            ['Supervisor', fn ($r) => $r->supervisor?->name ?? '—'],
        ];
    }
}
