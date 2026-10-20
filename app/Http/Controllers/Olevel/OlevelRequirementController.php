<?php

namespace App\Http\Controllers\Olevel;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\OlevelRequirement;
use App\Models\Programme;
use App\Models\Setting;
use App\Models\StudentOlevelSitting;
use App\Services\Access\AcademicRuleAccess;
use App\Services\Olevel\OlevelService;
use App\Services\OperationAudit;
use App\Support\Lookups;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Each department's (or programme's) O Level requirement for course registration. "Set O Level requirements" edits any;
 * a head or department administrator who may edit the department's academic rules may edit their own department's.
 */
class OlevelRequirementController extends Controller
{
    public function __construct(private OlevelService $olevel, private OperationAudit $audit, private AcademicRuleAccess $ruleAccess)
    {
    }

    private function mayEdit(Request $request, string $type, int $id): bool
    {
        return $request->user()->can('Set O Level requirements') || $this->ruleAccess->canEdit($request->user(), $type, $id);
    }

    public function index(Request $request): View
    {
        $user = $request->user();
        $departments = Department::with('faculty')->orderBy('name')->get()->filter(fn ($d) => $user->can('Set O Level requirements') || $user->can('View O Level results') || $this->ruleAccess->canEdit($user, 'department', $d->id));
        abort_if($departments->isEmpty(), 403);
        $type = $request->query('scope') === 'programme' ? 'programme' : 'department';
        $id = (int) $request->query('id', $departments->first()->id);
        $programme = $type === 'programme' ? Programme::with('department')->findOrFail($id) : null;
        $department = $programme?->department ?? $departments->firstWhere('id', $id) ?? abort(404);
        abort_unless($departments->contains('id', $department->id), 403);
        $req = OlevelRequirement::with('subjects', 'editor')->where('scope_type', $type)->where('scope_id', $id)->first();
        $inherited = $programme ? OlevelRequirement::with('subjects')->where('scope_type', 'department')->where('scope_id', $department->id)->first() : null;

        return view('olevel.requirements', [
            'pagetitle' => 'O Level Requirements', 'departments' => $departments, 'department' => $department, 'programme' => $programme,
            'type' => $type, 'id' => $id, 'req' => $req, 'inherited' => $inherited, 'canEdit' => $this->mayEdit($request, $type, $id),
            'programmes' => Programme::where('department_id', $department->id)->orderBy('name')->get(),
            'all' => OlevelRequirement::with('subjects')->get(), 'subjects' => Lookups::options('olevel_subject'), 'grades' => StudentOlevelSitting::CREDIT_GRADES,
            'settings' => collect(OlevelService::DEFAULTS)->mapWithKeys(fn ($v, $k) => [substr($k, 7) => Setting::get($k, $v) === '1'])->all(),
            'canSettings' => $user->can('Set O Level requirements'),
        ]);
    }

    public function save(Request $request): RedirectResponse
    {
        $d = $request->validate([
            'scope' => ['required', Rule::in(['department', 'programme'])], 'id' => ['required', 'integer'],
            'is_active' => ['nullable', 'boolean'], 'min_credits' => ['required', 'integer', 'between:0,9'], 'max_sittings' => ['required', 'integer', 'between:1,3'],
            'optional_needed' => ['nullable', 'integer', 'between:0,9'], 'applies_to' => ['required', Rule::in(array_keys(OlevelRequirement::APPLIES))],
            'note' => ['nullable', 'string', 'max:1000'],
            'subjects' => ['nullable', 'array', 'max:20'], 'subjects.*.subject' => ['nullable', 'string', 'max:100'],
            'subjects.*.min_grade' => ['nullable', Rule::in(StudentOlevelSitting::GRADES)], 'subjects.*.kind' => ['nullable', Rule::in(['required', 'optional'])],
            'remove' => ['nullable', 'boolean'],
        ]);
        abort_unless($d['scope'] === 'department' ? Department::whereKey($d['id'])->exists() : Programme::whereKey($d['id'])->exists(), 404);
        abort_unless($this->mayEdit($request, $d['scope'], (int) $d['id']), 403);
        $req = OlevelRequirement::where('scope_type', $d['scope'])->where('scope_id', $d['id'])->first();
        $before = $req ? ['summary' => $req->summary(), 'active' => $req->is_active] : null;

        if ($request->boolean('remove')) {
            $req?->delete();
            $this->audit->note('olevel_requirement', 'admissions', $request->user(), 'requirement_removed', null, $before, null, null);

            return redirect()->route('olevel.requirements', ['scope' => $d['scope'], 'id' => $d['id']])->with('success', $d['scope'] === 'programme' ? 'The programme now follows its department\'s requirement.' : 'Requirement removed.');
        }

        $rows = collect($d['subjects'] ?? [])->filter(fn ($r) => trim((string) ($r['subject'] ?? '')) !== '')
            ->map(fn ($r) => ['subject' => trim($r['subject']), 'min_grade' => $r['min_grade'] ?? 'C6', 'kind' => $r['kind'] ?? 'required'])
            ->unique(fn ($r) => OlevelService::key($r['subject']))->values();
        $optional = $rows->where('kind', 'optional')->count();
        if (($d['optional_needed'] ?? 0) > $optional) {
            return back()->withInput()->withErrors(['optional_needed' => "Only {$optional} optional subjects are listed."]);
        }

        DB::transaction(function () use ($d, $rows, $request, &$req) {
            $req = OlevelRequirement::updateOrCreate(['scope_type' => $d['scope'], 'scope_id' => $d['id']], [
                'is_active' => $request->boolean('is_active'), 'min_credits' => $d['min_credits'], 'max_sittings' => $d['max_sittings'],
                'optional_needed' => $d['optional_needed'] ?? 0, 'applies_to' => $d['applies_to'], 'note' => $d['note'] ?? null, 'updated_by' => $request->user()->id,
            ]);
            $req->subjects()->delete();
            foreach ($rows as $i => $r) {
                $req->subjects()->create($r + ['sort_order' => $i]);
            }
        });
        $req->refresh();
        $this->audit->note('olevel_requirement', 'admissions', $request->user(), 'requirement_saved', $req, $before, ['summary' => $req->summary(), 'active' => $req->is_active]);

        return redirect()->route('olevel.requirements', ['scope' => $d['scope'], 'id' => $d['id']])->with('success', 'O Level requirement saved.');
    }

    public function settings(Request $request): RedirectResponse
    {
        abort_unless($request->user()->can('Set O Level requirements'), 403);
        foreach (array_keys(OlevelService::DEFAULTS) as $key) {
            Setting::put($key, $request->boolean(substr($key, 7)) ? '1' : '0');
        }

        return back()->with('success', 'O Level settings saved.');
    }
}
