<?php

namespace App\Http\Controllers\Academics;

use App\Data\Access\AccessScope;
use App\Models\AcademicSession;
use App\Models\Course;
use App\Models\CourseAssignment;
use App\Models\SemesterType;
use App\Models\User;
use App\Services\Access\ScopedAccess;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * "Define courses": which lecturer teaches which course. Holders of the course assignment permissions define for any
 * department; a Head of Department (or anyone given "Define courses for lecturers" for a department or faculty) defines
 * only that department's courses, and only for its own staff.
 */
class CourseAssignmentController extends CrudController
{
    private const DEFINE = 'Define courses for lecturers';

    protected string $permission = 'course assignments';
    protected string $title = 'Define Courses';
    protected string $singular = 'Course Definition';
    protected string $icon = 'ri-user-voice-line';
    protected string $routeBase = 'academics.course-assignments';
    protected string $intro = 'Define which lecturer teaches which course in a session and semester. A lecturer can enter and upload scores only for the courses defined for them, unless they hold the permission to enter results for any course. Heads of Department define their own department\'s courses for their own staff.';
    protected array $snapshotSections = ['assignments'];
    protected array $copyCategories = ['assignments'];
    protected array $searchable = [];

    public function __construct(private ScopedAccess $access, private Guard $auth)
    {
        parent::__construct();
    }

    protected function viewMiddleware(): string
    {
        return 'scoped:View course assignments|' . self::DEFINE;
    }

    protected function actionMiddleware(string $verb): string
    {
        return "scoped:{$verb} course assignments|" . self::DEFINE;
    }

    /** Where this person may define courses: everywhere with the plain permission, else their departments. */
    private function defineScope(string $verb = 'Create'): AccessScope
    {
        $user = $this->auth->user();

        return $user?->can("{$verb} course assignments") ? AccessScope::everything() : $this->access->scope($user, self::DEFINE);
    }

    /** Rows listed: a head who defines for some departments sees those, even when they may also view everyone's. */
    private function viewScope(): AccessScope
    {
        $user = $this->auth->user();
        $define = $this->access->scope($user, self::DEFINE);
        if (! $define->all && ! $define->isEmpty() && ! $user->can('Edit course assignments')) {
            return $define;
        }

        return $user?->can('View course assignments') ? AccessScope::everything() : $define;
    }

    private function courses(AccessScope $scope): Builder
    {
        return $scope->all ? Course::query() : Course::whereIn('department_id', $scope->departmentIds ?: [0]);
    }

    /** Staff who can be given a course: everyone with a staff record, or only the department's own staff for a head. */
    private function lecturers(AccessScope $scope): Builder
    {
        return User::whereHas('staffProfile', fn ($p) => $scope->staffProfiles($p))->orderBy('name');
    }

    protected function model(): string
    {
        return CourseAssignment::class;
    }

    protected function query(Request $request): Builder
    {
        $scope = $this->viewScope();
        $q = parent::query($request)->with('user.staffProfile', 'course.department', 'session', 'semesterType');

        return $scope->all ? $q : $q->whereHas('course', fn ($c) => $c->whereIn('department_id', $scope->departmentIds ?: [0]));
    }

    protected function viewData(Request $request): array
    {
        $user = $this->auth->user();
        $define = $this->access->scope($user, self::DEFINE);

        return ['abilities' => [
            'create' => $user->can('Create course assignments') || ! $define->isEmpty(),
            'edit' => $user->can('Edit course assignments') || ! $define->isEmpty(),
            'delete' => $user->can('Delete course assignments') || ! $define->isEmpty(),
        ]];
    }

    protected function filters(): array
    {
        return [
            ['academic_session_id', 'Session', AcademicSession::orderByDesc('name')->pluck('name', 'id')->all()],
            ['semester_type_id', 'Semester', SemesterType::orderBy('sort_order')->pluck('name', 'id')->all()],
        ];
    }

    protected function rules(Request $request, ?Model $record): array
    {
        $scope = $this->defineScope($record ? 'Edit' : 'Create');
        if ($record) {
            abort_unless($scope->coversDepartment($record->course?->department_id), 403, 'That course belongs to another department.');
        }

        return [
            'user_id' => ['required', Rule::in($this->lecturers($scope)->pluck('id')->all())],
            'course_id' => ['required', Rule::in($this->courses($scope)->pluck('id')->all())],
            'academic_session_id' => ['required', 'exists:academic_sessions,id'],
            'semester_type_id' => ['required', 'exists:semester_types,id',
                Rule::unique('course_assignments')->where(fn ($q) => $q->where('user_id', $request->input('user_id'))->where('course_id', $request->input('course_id'))
                    ->where('academic_session_id', $request->input('academic_session_id')))->ignore($record?->id)],
        ];
    }

    protected function messages(): array
    {
        return ['semester_type_id.unique' => 'This lecturer is already defined for this course in this session and semester.'];
    }

    public function destroy($id): RedirectResponse
    {
        $record = CourseAssignment::with('course')->findOrFail($id);
        abort_unless($this->defineScope('Delete')->coversDepartment($record->course?->department_id), 403, 'That course belongs to another department.');

        return parent::destroy($id);
    }

    protected function fields(): array
    {
        $scope = $this->defineScope();
        $courseField = ['name' => 'course_id', 'label' => 'Course', 'type' => 'select', 'required' => true, 'col' => 12,
            'options' => $this->courses($scope)->where('is_active', true)->orderBy('code')->get()->mapWithKeys(fn ($c) => [$c->id => $c->code . ' - ' . $c->title])->all()];
        if ($scope->all) {
            $courseField += ['lookup' => 'courses', 'lookup_active' => true];   // a head's list stays limited to the department
        }

        return [
            ['name' => 'user_id', 'label' => 'Lecturer', 'type' => 'select', 'required' => true, 'col' => 12,
                'options' => $this->lecturers($scope)->with('staffProfile')->get()->mapWithKeys(fn ($u) => [$u->id => $u->name . ($u->staffProfile?->staff_number ? " ({$u->staffProfile->staff_number})" : '')])->all()],
            $courseField,
            ['name' => 'academic_session_id', 'lookup' => 'sessions', 'label' => 'Session', 'type' => 'select', 'options' => AcademicSession::orderByDesc('name')->pluck('name', 'id')->all(), 'required' => true, 'col' => 6, 'default' => AcademicSession::current()?->id],
            ['name' => 'semester_type_id', 'lookup' => 'semester-types', 'label' => 'Semester', 'type' => 'select', 'options' => SemesterType::orderBy('sort_order')->pluck('name', 'id')->all(), 'required' => true, 'col' => 6],
        ];
    }

    protected function columns(): array
    {
        return [
            ['Lecturer', fn ($r) => new \Illuminate\Support\HtmlString(e($r->user->name) . '<div class="text-muted fs-12">' . e($r->user->staffProfile?->staff_number ?? '') . '</div>')],
            ['Course', fn ($r) => new \Illuminate\Support\HtmlString(e($r->course->code . ' - ' . $r->course->title) . '<div class="mt-1">' . \Illuminate\Support\Facades\Blade::renderComponent(new \App\View\Components\CourseTag($r->course)) . '</div>')],
            ['Department', fn ($r) => $r->course->department?->name],
            ['Session', fn ($r) => $r->session->name],
            ['Semester', fn ($r) => $r->semesterType->name],
        ];
    }
}
