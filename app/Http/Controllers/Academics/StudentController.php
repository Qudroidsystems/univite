<?php

namespace App\Http\Controllers\Academics;

use App\Http\Controllers\Concerns\HasProfileFields;
use App\Models\AcademicSession;
use App\Models\Programme;
use App\Models\Student;
use App\Models\User;
use App\Services\Access\ScopedAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Admitted students. Students are created by admitting an applicant, never directly,
 * so this page lists, edits and manages them (profile, photo, status, password).
 */
class StudentController extends CrudController
{
    use HasProfileFields;

    protected string $permission = 'students';
    protected ?string $customTarget = 'student';
    protected array $actions = ['edit'];
    protected string $title = 'Students';
    protected string $singular = 'Student';
    protected string $icon = 'ri-user-star-line';
    protected string $routeBase = 'students';
    protected string $intro = 'Everyone who has been admitted. Open a student to see their full profile, upload a photo, change their status or reset their password. New students come from admitting an applicant.';
    protected array $searchable = ['matric_number', 'first_name', 'last_name', 'phone_number'];

    public function __construct(private ScopedAccess $access, private \App\Services\Students\StudentRecordService $records, private \App\Support\StudentSettings $settings)
    {
        parent::__construct();
        $this->middleware('scoped:View students')->only('show');
        $this->middleware('permission:Edit students')->only(['photo', 'signature', 'referees']);
        $this->middleware('permission:Change student programmes')->only('transfer');
        $this->middleware('permission:Manage deferred admissions')->only('defer');
        $this->middleware('permission:Change student status')->only('status');
        $this->middleware('permission:Reset student passwords')->only('resetPassword');
    }

    protected function model(): string
    {
        return Student::class;
    }

    protected function viewMiddleware(): string
    {
        return 'scoped:View students';
    }

    protected function query(Request $request): Builder
    {
        $q = parent::query($request)->with(['user', 'programme', 'entrySession', 'courseOption', 'subDepartment'])->reorder()->orderBy('last_name')->orderBy('first_name');

        return $this->access->scope($request->user(), 'View students')->students($q);
    }

    protected function filters(): array
    {
        return [
            ['programme_id', 'Programme', Programme::orderBy('name')->pluck('name', 'id')->all()],
            ['current_level', 'Level', \App\Support\Levels::options()],
            ['academic_session_id', 'Entry session', AcademicSession::orderByDesc('name')->pluck('name', 'id')->all()],
            ['status', 'Status', \App\Models\StudentStatus::options()],
            ['sub_department_id', 'Sub-department', \App\Models\SubDepartment::orderBy('name')->pluck('name', 'id')->all()],
            ['course_option_id', 'Course option', \App\Models\CourseOption::orderBy('name')->pluck('name', 'id')->all()],
            ['entry_semester_type_id', 'Semester joined', \App\Models\SemesterType::orderBy('sort_order')->pluck('name', 'id')->all()],
            ['entry_mode', 'Entry mode', \App\Models\EntryMode::orderBy('sort_order')->pluck('name', 'code')->all()],
        ];
    }

    protected function rules(Request $request, ?Model $record): array
    {
        return $this->profileRules() + [
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($record?->user_id)],
            'current_level' => [
                'required', 'integer',
                function ($attribute, $value, $fail) use ($record) {
                    if ($record && ! in_array((int) $value, $record->programme->levels(), true)) {
                        $fail("{$record->programme->name} runs levels " . implode(', ', $record->programme->levels()) . '.');
                    }
                },
            ],
            'jamb_reg_no' => ['nullable', 'string', 'max:30'],
            'application_ref' => ['nullable', 'string', 'max:50'],
            'course_option_id' => ['nullable', Rule::exists('course_options', 'id')->where('programme_id', $record?->programme_id ?? 0)],
            'sub_department_id' => ['nullable', Rule::exists('sub_departments', 'id')->where('department_id', $record?->programme?->department_id ?? 0)],
            'current_semester_type_id' => ['nullable', 'exists:semester_types,id'],
        ];
    }

    protected function fields(): array
    {
        [$bio, $details, $guardian] = $this->profileFieldGroups();

        $account = [
            ['name' => 'email', 'label' => 'Email (login)', 'type' => 'email', 'required' => true, 'col' => 8],
            ['name' => 'current_level', 'label' => 'Current level', 'type' => 'select', 'options' => \App\Support\Levels::options(), 'required' => true, 'col' => 4],
            ['name' => 'jamb_reg_no', 'label' => 'JAMB registration number', 'type' => 'text', 'col' => 4, 'help' => 'Kept apart from the matric number.'],
            ['name' => 'application_ref', 'label' => 'Old portal application ID', 'type' => 'text', 'col' => 4],
            ['name' => 'current_semester_type_id', 'label' => 'Current semester', 'type' => 'select', 'options' => \App\Models\SemesterType::orderBy('sort_order')->pluck('name', 'id')->all(), 'blank' => 'Not set', 'col' => 4],
            ['name' => 'course_option_id', 'lookup' => 'course-options', 'label' => 'Course option', 'type' => 'select', 'options' => \App\Models\CourseOption::orderBy('name')->pluck('name', 'id')->all(), 'blank' => 'None', 'col' => 6, 'help' => 'Options of the student\'s own programme.'],
            ['name' => 'sub_department_id', 'lookup' => 'sub-departments', 'label' => 'Sub-department', 'type' => 'select', 'options' => \App\Models\SubDepartment::orderBy('name')->pluck('name', 'id')->all(), 'blank' => 'None', 'col' => 6],
        ];

        return array_merge($bio, $account, $details, $guardian);
    }

    protected function columns(): array
    {
        return [
            ['Matric number', fn ($r) => new HtmlString('<a href="' . e(route('students.show', $r->id)) . '" class="fw-semibold">' . e($r->matric_label) . '</a>')],
            ['Student', fn ($r) => new HtmlString('<span class="fw-medium">' . e($r->full_name) . '</span><div class="text-muted fs-12">' . e($r->user?->email) . '</div>')],
            ['Programme', fn ($r) => new HtmlString(e($r->programme->name) . ($r->courseOption ? ' <span class="badge bg-light text-body border">' . e($r->courseOption->name) . '</span>' : '')
                . '<div class="text-muted fs-12">' . ($r->subDepartment ? e($r->subDepartment->name) . ' · ' : '') . 'Entered ' . e($r->entrySession->name) . ($r->jamb_reg_no ? ' · JAMB ' . e($r->jamb_reg_no) : '') . '</div>')],
            ['Level', fn ($r) => $r->current_level . 'L'],
            ['Status', fn ($r) => new HtmlString('<span class="badge ' . \App\Models\StudentStatus::badgeClass($r->status) . '">' . e(\App\Models\StudentStatus::label($r->status)) . '</span>')],
        ];
    }

    protected function prepare(array $data, Request $request, ?Model $record): array
    {
        unset($data['email']); // the email belongs to the user account; see saved()

        return $data;
    }

    protected function saved(Model $record, Request $request, bool $created): void
    {
        $record->user->update([
            'name'          => trim($record->first_name . ' ' . $record->last_name),
            'email'         => strtolower($request->input('email')),
            'phone_number'  => $record->phone_number,
            'gender'        => $record->gender,
            'date_of_birth' => $record->date_of_birth,
        ]);
    }

    public function show($id): View
    {
        $student = Student::with(['user.roles', 'programme.department.faculty', 'entrySession', 'applicant', 'courseOption', 'subDepartment', 'referees', 'transfers.fromProgramme', 'transfers.toProgramme', 'transfers.by', 'deferrals.toSession', 'tracks.session', 'tracks.semesterType'])->findOrFail($id);
        abort_unless($this->access->scope(request()->user(), 'View students')->coversStudent($student), 403);

        return view('students.show', [
            'pagetitle' => $student->full_name,
            'student'   => $student,
            'settings'  => $this->settings,
            'programmes' => Programme::where('is_active', true)->orderBy('name')->get(['id', 'name', 'code']),
            'sessions'  => AcademicSession::orderByDesc('name')->pluck('name', 'id'),
        ]);
    }

    public function signature(Request $request, $id): RedirectResponse
    {
        $student = Student::findOrFail($id);
        $request->validate(['signature' => ['required', 'image', 'mimes:jpg,jpeg,png', 'max:1024']]);
        $path = $request->file('signature')->store('students/signatures', 'public');
        if ($student->signature) {
            Storage::disk('public')->delete($student->signature);
        }
        $student->update(['signature' => $path]);

        return back()->with('success', 'Signature updated.');
    }

    public function transfer(Request $request, $id): RedirectResponse
    {
        $student = Student::with('programme')->findOrFail($id);
        abort_unless($this->access->scope($request->user(), 'View students')->coversStudent($student), 403);
        $data = $request->validate([
            'programme_id' => ['required', 'exists:programmes,id'], 'level' => ['required', 'integer'],
            'course_option_id' => ['nullable', 'exists:course_options,id'], 'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ]);
        try {
            $this->records->transfer($student, \App\Data\Students\TransferRequest::fromArray($data), $request->user());
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Transferred to ' . $student->fresh()->programme->name . '.');
    }

    public function defer(Request $request, $id): RedirectResponse
    {
        $student = Student::findOrFail($id);
        abort_unless($this->access->scope($request->user(), 'View students')->coversStudent($student), 403);
        $data = $request->validate(['to_session_id' => ['required', 'exists:academic_sessions,id'], 'reason' => ['required', 'string', 'min:5', 'max:1000']]);
        try {
            $this->records->defer($student, (int) $data['to_session_id'], $data['reason'], $request->user());
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Deferred. Bring the student back under Deferred Admissions when their session comes.');
    }

    public function referees(Request $request, $id): RedirectResponse
    {
        $student = Student::findOrFail($id);
        $request->validate(self::refereeRules());
        $this->records->saveReferees($student, \App\Data\Students\RefereeData::listFrom($request->input('referees', []), 2));

        return back()->with('success', 'Referees saved.');
    }

    /** Rules for referees[1..2][name|phone|email|address|occupation]. */
    public static function refereeRules(): array
    {
        return [
            'referees' => ['nullable', 'array'], 'referees.*.name' => ['nullable', 'string', 'max:150'], 'referees.*.phone' => ['nullable', 'string', 'max:30'],
            'referees.*.email' => ['nullable', 'email', 'max:150'], 'referees.*.address' => ['nullable', 'string', 'max:500'], 'referees.*.occupation' => ['nullable', 'string', 'max:150'],
        ];
    }

    public function photo(Request $request, $id): RedirectResponse
    {
        $student = Student::findOrFail($id);
        $request->validate(['photo' => ['required', 'image', 'mimes:jpg,jpeg,png', 'max:2048']]);

        $path = $request->file('photo')->store('students/photos', 'public');

        if ($student->photo) {
            Storage::disk('public')->delete($student->photo);
        }
        $student->update(['photo' => $path]);

        return back()->with('success', 'Photo updated.');
    }

    public function status(Request $request, $id): RedirectResponse
    {
        $student = Student::with('user')->findOrFail($id);
        $data = $request->validate(['status' => ['required', Rule::in(array_keys(\App\Models\StudentStatus::options()))]]);

        $student->update(['status' => $data['status']]);
        // Whether a status lets the student sign in is a switch on the status itself.
        $student->user->update(['is_disabled' => ! in_array($data['status'], \App\Models\StudentStatus::codesWhere('can_login'), true)]);

        return back()->with('success', 'Status changed to ' . \App\Models\StudentStatus::label($data['status']) . '.');
    }

    public function resetPassword($id): RedirectResponse
    {
        $student = Student::with('user')->findOrFail($id);
        $plain = Str::password(10, symbols: false);

        $student->user->update(['password' => $plain, 'must_change_password' => true]);

        return back()
            ->with('success', 'A new temporary password has been generated.')
            ->with('generated_password', ['email' => $student->user->email, 'password' => $plain]);
    }
}
