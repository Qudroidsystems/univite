<?php

namespace App\Http\Controllers\Academics;

use App\Models\AcademicSession;
use App\Models\Course;
use App\Models\CourseAssignment;
use App\Models\SemesterType;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CourseAssignmentController extends CrudController
{
    protected string $permission = 'course assignments';
    protected string $title = 'Course Lecturers';
    protected string $singular = 'Assignment';
    protected string $icon = 'ri-user-voice-line';
    protected string $routeBase = 'academics.course-assignments';
    protected string $intro = 'Who teaches which course in a session and semester. An assigned lecturer can enter and upload scores for that course; nobody else can unless they hold the permission to enter results for any course.';
    protected array $searchable = [];

    protected function model(): string
    {
        return CourseAssignment::class;
    }

    protected function query(Request $request): Builder
    {
        return parent::query($request)->with('user', 'course', 'session', 'semesterType');
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
        return [
            'user_id' => ['required', 'exists:users,id'],
            'course_id' => ['required', 'exists:courses,id'],
            'academic_session_id' => ['required', 'exists:academic_sessions,id'],
            'semester_type_id' => ['required', 'exists:semester_types,id',
                Rule::unique('course_assignments')->where(fn ($q) => $q->where('user_id', $request->input('user_id'))->where('course_id', $request->input('course_id'))
                    ->where('academic_session_id', $request->input('academic_session_id')))->ignore($record?->id)],
        ];
    }

    protected function fields(): array
    {
        return [
            ['name' => 'user_id', 'label' => 'Lecturer', 'type' => 'select', 'options' => User::orderBy('name')->pluck('name', 'id')->all(), 'required' => true, 'col' => 12],
            ['name' => 'course_id', 'lookup' => 'courses', 'lookup_active' => true, 'label' => 'Course', 'type' => 'select', 'options' => Course::where('is_active', true)->orderBy('code')->get()->mapWithKeys(fn ($c) => [$c->id => $c->code . ' - ' . $c->title])->all(), 'required' => true, 'col' => 12],
            ['name' => 'academic_session_id', 'lookup' => 'sessions', 'label' => 'Session', 'type' => 'select', 'options' => AcademicSession::orderByDesc('name')->pluck('name', 'id')->all(), 'required' => true, 'col' => 6, 'default' => AcademicSession::current()?->id],
            ['name' => 'semester_type_id', 'lookup' => 'semester-types', 'label' => 'Semester', 'type' => 'select', 'options' => SemesterType::orderBy('sort_order')->pluck('name', 'id')->all(), 'required' => true, 'col' => 6],
        ];
    }

    protected function columns(): array
    {
        return [
            ['Lecturer', fn ($r) => $r->user->name],
            ['Course', fn ($r) => $r->course->code . ' - ' . $r->course->title],
            ['Session', fn ($r) => $r->session->name],
            ['Semester', fn ($r) => $r->semesterType->name],
        ];
    }
}
