<?php

namespace App\Http\Controllers\Academics;

use App\Http\Controllers\Concerns\HasProfileFields;
use App\Models\AcademicSession;
use App\Models\Applicant;
use App\Models\Programme;
use App\Services\AdmissionService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * People who applied but are not students yet. Admitting one creates the student record,
 * the matric number and the login account.
 */
class ApplicantController extends CrudController
{
    use HasProfileFields;

    protected string $permission = 'applicants';
    protected ?string $customTarget = 'applicant';
    protected string $title = 'Applicants';
    protected string $singular = 'Applicant';
    protected string $icon = 'ri-user-add-line';
    protected string $routeBase = 'applicants';
    protected string $intro = 'Record people who have applied, then admit them. Admitting creates their student record, generates a matric number from the format you set, and creates a login with a temporary password (shown once).';
    protected array $searchable = ['first_name', 'last_name', 'email', 'application_no', 'phone_number', 'jamb_reg_no'];
    protected ?string $extraView = 'applicants.partials.batch-admit';

    public function __construct(private \App\Services\ApplicationService $applications)
    {
        parent::__construct();
        $this->middleware('permission:View applicants')->only(['show', 'document']);
        $this->middleware('permission:Admit applicants')->only(['admit', 'admitBatch']);
        $this->middleware('permission:Reject applicants')->only('reject');
    }

    protected function model(): string
    {
        return Applicant::class;
    }

    protected function query(Request $request): Builder
    {
        return parent::query($request)->with(['programme', 'session', 'student']);
    }

    protected function viewData(Request $request): array
    {
        return [
            'pendingCount' => Applicant::where('status', 'pending')->count(),
            'programmeOptions' => Programme::orderBy('name')->pluck('name', 'id')->all(),
        ];
    }

    protected function filters(): array
    {
        return [
            ['status', 'Status', Applicant::STATUSES],
            ['programme_id', 'Programme', Programme::orderBy('name')->pluck('name', 'id')->all()],
            ['academic_session_id', 'Session', AcademicSession::orderByDesc('name')->pluck('name', 'id')->all()],
        ];
    }

    protected function rules(Request $request, ?Model $record): array
    {
        $programme = Programme::find($request->input('programme_id'));
        $mode = \App\Models\EntryMode::byCode($request->input('entry_mode'));

        return $this->profileRules() + [
            'email' => ['required', 'email', 'max:255', Rule::unique('applicants', 'email')->ignore($record?->id), Rule::unique('users', 'email')],
            'programme_id' => ['required', Rule::exists('programmes', 'id')->where('is_active', true)],
            'academic_session_id' => ['required', 'exists:academic_sessions,id'],
            'entry_mode' => ['required', Rule::in(array_keys(\App\Models\EntryMode::options()))],
            'entry_level' => [
                'required', 'integer',
                function ($attribute, $value, $fail) use ($programme, $mode) {
                    if ($programme && ! in_array((int) $value, $programme->levels(), true)) {
                        $fail("{$programme->name} runs levels " . implode(', ', $programme->levels()) . '.');
                    } elseif ($mode && $mode->fixed_level && (int) $value !== (int) $mode->entry_level) {
                        $fail("{$mode->name} candidates enter at {$mode->entry_level} level.");
                    } elseif ($mode && ! $mode->fixed_level && (int) $value < (int) $mode->entry_level) {
                        $fail("{$mode->name} candidates enter at {$mode->entry_level} level or above.");
                    }
                },
            ],
        ];
    }

    protected function fields(): array
    {
        [$bio, $details, $guardian] = $this->profileFieldGroups();

        $admission = [
            ['name' => 'email', 'label' => 'Email (becomes the login)', 'type' => 'email', 'required' => true, 'col' => 6],
            ['name' => 'programme_id', 'lookup' => 'programmes', 'lookup_active' => true, 'label' => 'Programme applied for', 'type' => 'select', 'options' => Programme::where('is_active', true)->orderBy('name')->get()->mapWithKeys(fn ($p) => [$p->id => $p->name . ' (' . $p->code . ')'])->all(), 'required' => true, 'col' => 6],
            ['name' => 'academic_session_id', 'lookup' => 'sessions', 'label' => 'Entry session', 'type' => 'select', 'options' => AcademicSession::orderByDesc('name')->pluck('name', 'id')->all(), 'required' => true, 'col' => 4, 'default' => AcademicSession::current()?->id],
            ['name' => 'entry_mode', 'lookup' => 'entry-modes', 'label' => 'Entry mode', 'type' => 'select', 'options' => \App\Models\EntryMode::options(), 'required' => true, 'col' => 4, 'default' => \App\Models\EntryMode::orderBy('sort_order')->value('code')],
            ['name' => 'entry_level', 'label' => 'Entry level', 'type' => 'select', 'options' => \App\Support\Levels::options(), 'required' => true, 'col' => 4, 'default' => 100],
        ];

        return array_merge($bio, $admission, $details, $guardian);
    }

    protected function columns(): array
    {
        return [
            ['Application no.', fn ($r) => new HtmlString('<span class="badge bg-light text-body border">' . e($r->application_no) . '</span>')],
            ['Applicant', fn ($r) => new HtmlString('<a href="' . e(route('applicants.show', $r->id)) . '" class="fw-semibold">' . e($r->full_name) . '</a><div class="text-muted fs-12">' . e($r->email) . '</div>' . ($r->jamb_reg_no ? '<div class="text-muted fs-12">JAMB ' . e($r->jamb_reg_no) . ($r->jamb_score ? ' · ' . $r->jamb_score : '') . '</div>' : ''))],
            ['Programme', fn ($r) => new HtmlString(e($r->programme->name) . '<div class="text-muted fs-12">' . e(\App\Models\EntryMode::label($r->entry_mode)) . ' · ' . $r->entry_level . 'L · ' . e($r->session->name) . '</div>')],
            ['Status', function ($r) {
                $cls = ['draft' => 'bg-secondary-subtle text-secondary', 'pending' => 'bg-warning-subtle text-warning', 'admitted' => 'bg-success-subtle text-success', 'rejected' => 'bg-danger-subtle text-danger'][$r->status];
                $extra = $r->student ? '<div class="text-muted fs-12 mt-1">' . e($r->student->matric_label) . '</div>' : '';

                return new HtmlString('<span class="badge ' . $cls . '">' . e(Applicant::STATUSES[$r->status]) . '</span>' . $extra);
            }],
        ];
    }

    protected function rowActions(): array
    {
        return [
            ['Admit', 'applicants.admit', 'Admit applicants', 'ri-user-follow-line', fn ($r) => in_array($r->status, ['pending', 'rejected'], true)],
            ['Reject', 'applicants.reject', 'Reject applicants', 'ri-close-circle-line', fn ($r) => $r->status === 'pending'],
        ];
    }

    protected function prepare(array $data, Request $request, ?Model $record): array
    {
        $data['email'] = strtolower($data['email']);

        return $data;
    }

    protected function saved(Model $record, Request $request, bool $created): void
    {
        if ($created) {
            $record->update(['application_no' => sprintf('APP/%s/%04d', substr($record->session->name, 0, 4), $record->id)]);
        }
    }

    public function update(Request $request, $id): RedirectResponse
    {
        if (Applicant::findOrFail($id)->status === 'admitted') {
            return back()->with('error', 'This applicant has already been admitted. Edit the student record instead.');
        }

        return parent::update($request, $id);
    }

    public function destroy($id): RedirectResponse
    {
        if (Applicant::findOrFail($id)->status === 'admitted') {
            return back()->with('error', 'An admitted applicant is part of the student\'s record and cannot be deleted.');
        }

        return parent::destroy($id);
    }

    public function admit(AdmissionService $admissions, $id): RedirectResponse
    {
        $applicant = Applicant::findOrFail($id);

        try {
            $admission = $admissions->admit($applicant);
            $student = $admission->student;
            $password = $admission->temporaryPassword;
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        $response = back()->with('success', "{$student->full_name} has been admitted." . ($student->matric_number ? " Matric number: {$student->matric_number}." : ' Their matric number is issued after clearance.') . ($password ? '' : ' They sign in with the password they chose when applying.'));

        return $password ? $response->with('generated_password', ['email' => $student->user->email, 'password' => $password]) : $response;
    }

    public function show($id): \Illuminate\View\View
    {
        $applicant = Applicant::with(['programme.department.faculty', 'session', 'results', 'documents', 'student', 'invoices'])->findOrFail($id);

        return view('applicants.show', [
            'pagetitle' => $applicant->full_name,
            'applicant' => $applicant,
            'checklist' => $this->applications->checklist($applicant),
        ]);
    }

    public function document($id)
    {
        $document = \App\Models\ApplicantDocument::findOrFail($id);

        return response()->file(\Illuminate\Support\Facades\Storage::disk('local')->path($document->path), ['Content-Disposition' => 'inline; filename="' . addslashes($document->original_name) . '"']);
    }

    /** Admit every submitted applicant who matches the filters, tagging them with a batch name. */
    public function admitBatch(Request $request, AdmissionService $admissions): RedirectResponse
    {
        $data = $request->validate([
            'batch'        => ['required', 'string', 'max:100'],
            'programme_id' => ['nullable', 'exists:programmes,id'],
        ]);

        $admitted = 0;
        $failed = [];
        Applicant::where('status', 'pending')
            ->when($data['programme_id'] ?? null, fn ($q, $p) => $q->where('programme_id', $p))
            ->orderBy('id')->each(function (Applicant $applicant) use ($admissions, $data, &$admitted, &$failed) {
                try {
                    $admissions->admit($applicant, $data['batch']);
                    $admitted++;
                } catch (RuntimeException $e) {
                    $failed[] = "{$applicant->application_no}: " . $e->getMessage();
                }
            });

        if (! $admitted && ! $failed) {
            return back()->with('error', 'There are no submitted applications waiting to be admitted.');
        }

        $message = "{$admitted} applicant(s) admitted in \"{$data['batch']}\".";
        $back = back()->with($admitted ? 'success' : 'error', $message . ($failed ? ' Not admitted: ' . implode(' | ', array_slice($failed, 0, 5)) . (count($failed) > 5 ? ' …' : '') : ''));

        return $back;
    }

    public function reject($id): RedirectResponse
    {
        $applicant = Applicant::findOrFail($id);

        if ($applicant->status !== 'pending') {
            return back()->with('error', 'Only pending applications can be rejected.');
        }

        $applicant->update(['status' => 'rejected', 'decided_at' => now()]);

        return back()->with('success', "{$applicant->full_name}'s application was rejected.");
    }
}
