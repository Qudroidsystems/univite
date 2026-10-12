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

    public function __construct(private ScopedAccess $access)
    {
        parent::__construct();
        $this->middleware('scoped:View students')->only('show');
        $this->middleware('permission:Edit students')->only('photo');
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
        $q = parent::query($request)->with(['user', 'programme', 'entrySession'])->reorder()->orderBy('last_name')->orderBy('first_name');

        return $this->access->scope($request->user(), 'View students')->students($q);
    }

    protected function filters(): array
    {
        return [
            ['programme_id', 'Programme', Programme::orderBy('name')->pluck('name', 'id')->all()],
            ['current_level', 'Level', \App\Support\Levels::options()],
            ['academic_session_id', 'Entry session', AcademicSession::orderByDesc('name')->pluck('name', 'id')->all()],
            ['status', 'Status', \App\Models\StudentStatus::options()],
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
        ];
    }

    protected function fields(): array
    {
        [$bio, $details, $guardian] = $this->profileFieldGroups();

        $account = [
            ['name' => 'email', 'label' => 'Email (login)', 'type' => 'email', 'required' => true, 'col' => 8],
            ['name' => 'current_level', 'label' => 'Current level', 'type' => 'select', 'options' => \App\Support\Levels::options(), 'required' => true, 'col' => 4],
        ];

        return array_merge($bio, $account, $details, $guardian);
    }

    protected function columns(): array
    {
        return [
            ['Matric number', fn ($r) => new HtmlString('<a href="' . e(route('students.show', $r->id)) . '" class="fw-semibold">' . e($r->matric_label) . '</a>')],
            ['Student', fn ($r) => new HtmlString('<span class="fw-medium">' . e($r->full_name) . '</span><div class="text-muted fs-12">' . e($r->user?->email) . '</div>')],
            ['Programme', fn ($r) => new HtmlString(e($r->programme->name) . '<div class="text-muted fs-12">Entered ' . e($r->entrySession->name) . '</div>')],
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
        $student = Student::with(['user.roles', 'programme.department.faculty', 'entrySession', 'applicant'])->findOrFail($id);
        abort_unless($this->access->scope(request()->user(), 'View students')->coversStudent($student), 403);

        return view('students.show', [
            'pagetitle' => $student->full_name,
            'student'   => $student,
        ]);
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
