<?php

namespace App\Http\Controllers\Academics;

use App\Http\Controllers\Controller;
use App\Models\AcademicRule;
use App\Models\Department;
use App\Models\Faculty;
use App\Models\Programme;
use App\Models\SemesterType;
use App\Models\User;
use App\Support\AcademicRules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Academic rules per scope. "Edit academic rules" edits any scope; "Edit department academic rules" lets a head of
 * department or department administrator edit their department and its programmes, and a dean their faculty and
 * everything in it.
 */
class AcademicRuleController extends Controller
{
    public function __construct(private AcademicRules $rules)
    {
    }

    /** Can this user change the rules of this scope? */
    public static function canEdit(User $user, string $type, ?int $id): bool
    {
        if ($user->can('Edit academic rules')) {
            return true;
        }
        if (! $user->can('Edit department academic rules') || $type === 'university' || ! $id) {
            return false;
        }

        $department = match ($type) {
            'department' => Department::find($id),
            'programme' => Programme::find($id)?->department,
            default => null,
        };
        $faculty = $type === 'faculty' ? Faculty::find($id) : $department?->faculty;

        return ($faculty && $faculty->dean_id === $user->id)
            || ($department && ($department->hod_id === $user->id || $department->admins()->whereKey($user->id)->exists()));
    }

    /** [value "type:id" => label] of the scopes the page offers this user, grouped. */
    private function scopeOptions(User $user): array
    {
        $all = $user->can('View academic rules') || $user->can('Edit academic rules');
        $groups = [];
        if ($all) {
            $groups['University'] = ['university:0' => 'Whole university (defaults)'];
        }
        foreach ([['Faculties', 'faculty', Faculty::orderBy('name')->get()], ['Departments', 'department', Department::orderBy('name')->get()], ['Programmes', 'programme', Programme::orderBy('name')->get()]] as [$label, $type, $rows]) {
            foreach ($rows as $r) {
                if ($all || self::canEdit($user, $type, $r->id)) {
                    $groups[$label]["{$type}:{$r->id}"] = $r->name;
                }
            }
        }

        return $groups;
    }

    private function scopeFrom(Request $request, User $user, array $options): array
    {
        $type = $request->input('scope', 'university');
        $id = (int) $request->input('id', 0);
        $flat = collect($options)->collapse();
        if (! $flat->has("{$type}:{$id}")) {
            [$type, $id] = explode(':', (string) ($flat->keys()->first() ?? abort(403)));
            $id = (int) $id;
        }
        $level = $request->filled('level') ? (int) $request->input('level') : null;
        $sem = $request->filled('semester_type_id') ? (int) $request->input('semester_type_id') : null;

        return [$type, $id, $level, $sem];
    }

    public function index(Request $request): View
    {
        $user = $request->user();
        abort_unless($user->canAny(['View academic rules', 'Edit academic rules', 'Edit department academic rules']), 403);
        $options = $this->scopeOptions($user);
        [$type, $id, $level, $sem] = $this->scopeFrom($request, $user, $options);
        $term = $level || $sem;

        $inherited = $this->rules->resolve($type, $id ?: null, $level, $sem, includeOwn: false);
        $own = $type === 'university' && ! $term ? $this->rules->university() : $this->rules->own($type, $id, $level, $sem);
        $chain = AcademicRules::chain($type, $id ?: null);

        $overrides = AcademicRule::with('semesterType')->get()->map(function ($r) {
            $name = match ($r->scope_type) {
                'faculty' => Faculty::find($r->scope_id)?->name, 'department' => Department::find($r->scope_id)?->name,
                'programme' => Programme::find($r->scope_id)?->name, default => 'University',
            };

            return ['row' => $r, 'name' => $name, 'type' => AcademicRules::SCOPES[$r->scope_type] ?? $r->scope_type];
        })->filter(fn ($o) => $o['name'] && collect($options)->collapse()->has("{$o['row']->scope_type}:{$o['row']->scope_id}"))
            ->sortBy(fn ($o) => [array_search($o['row']->scope_type, array_keys(AcademicRules::SCOPES)), $o['name'], $o['row']->level, $o['row']->semester_type_id]);

        $department = $type === 'department' ? Department::with('admins', 'hod')->find($id) : null;
        $programme = $type === 'programme' ? Programme::with('studyCategory')->find($id) : null;
        $canEdit = self::canEdit($user, $type, $id ?: null);

        return view('academics.rules', [
            'pagetitle' => 'Academic rules', 'options' => $options, 'type' => $type, 'id' => $id, 'level' => $level, 'sem' => $sem,
            'term' => $term, 'inherited' => $inherited, 'own' => $own, 'chain' => $chain,
            'scopeName' => collect($options)->collapse()->get("{$type}:{$id}"), 'canEdit' => $canEdit,
            'levels' => \App\Support\Levels::options(), 'semesters' => SemesterType::orderBy('sort_order')->pluck('name', 'id'),
            'overrides' => $overrides, 'department' => $department, 'programme' => $programme,
            'advisers' => $programme ? \App\Models\CourseAdviser::with('user')->where('programme_id', $programme->id)->orderBy('level')->get()->groupBy('level') : collect(),
            'users' => ($department && $user->can('Edit academic rules')) || ($programme && $canEdit) ? User::whereDoesntHave('student')->orderBy('name')->pluck('name', 'id') : collect(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();
        $type = $request->input('scope');
        $id = (int) $request->input('id', 0);
        abort_unless(in_array($type, array_keys(AcademicRules::SCOPES), true), 404);
        abort_unless(self::canEdit($user, $type, $id ?: null), 403);
        $level = $request->filled('level') ? (int) $request->input('level') : null;
        $sem = $request->filled('semester_type_id') ? (int) $request->input('semester_type_id') : null;
        $term = $level || $sem;

        $rules = ['level' => ['nullable', 'integer', 'min:100'], 'semester_type_id' => ['nullable', 'exists:semester_types,id']];
        foreach (AcademicRules::DEFS as $key => $d) {
            $rules["rules.{$key}"] = match ($d[2]) {
                'int' => ['nullable', 'integer', 'min:0', 'max:1000'],
                'decimal' => ['nullable', 'numeric', 'min:0', 'max:10'],
                'bool' => ['nullable', 'in:0,1'],
                default => ['nullable', Rule::in(array_keys($d[6] ?? []))],
            };
        }
        $data = $request->validate($rules, [], collect(AcademicRules::DEFS)->mapWithKeys(fn ($d, $k) => ["rules.{$k}" => $d[1]])->all());
        $values = $data['rules'] ?? [];

        // The credit load that will apply must make sense.
        $after = array_map(fn ($r) => $r['value'], $this->rules->resolve($type, $id ?: null, $level, $sem, includeOwn: false));
        foreach ($values as $k => $v) {
            if ($v !== null && $v !== '') {
                $after[$k] = AcademicRules::cast($k, $v);
            }
        }
        if ($after['min_units'] !== null && $after['max_units'] !== null && $after['max_units'] < $after['min_units']) {
            return back()->withErrors(['rules.max_units' => 'The maximum units cannot be below the minimum (' . $after['min_units'] . ').'])->withInput();
        }
        if ($after['probation_cgpa'] !== null && $after['withdrawal_cgpa'] !== null && $after['withdrawal_cgpa'] > $after['probation_cgpa']) {
            return back()->withErrors(['rules.withdrawal_cgpa' => 'The withdrawal CGPA should be below the probation CGPA.'])->withInput();
        }

        if ($type === 'university' && ! $term) {
            $this->rules->saveUniversity(collect(AcademicRules::DEFS)->keys()->mapWithKeys(fn ($k) => [$k => $values[$k] ?? null])->all());
        } else {
            $this->rules->save($type, $id, $level, $sem, array_map(fn ($v) => $v === '' ? null : $v, $values), $user->id);
        }

        return redirect()->route('academics.rules.index', array_filter(['scope' => $type, 'id' => $id, 'level' => $level, 'semester_type_id' => $sem]))->with('success', 'Academic rules saved.');
    }

    /** Assign a course adviser to one level of a programme (by whoever may edit that programme's rules). */
    public function addAdviser(Request $request, Programme $programme): RedirectResponse
    {
        abort_unless(self::canEdit($request->user(), 'programme', $programme->id), 403);
        $d = $request->validate(['level' => ['required', 'integer', Rule::in($programme->levels())], 'user_id' => ['required', 'exists:users,id']]);
        \App\Models\CourseAdviser::firstOrCreate(['programme_id' => $programme->id, 'level' => $d['level'], 'user_id' => $d['user_id']]);

        return back()->with('success', 'Course adviser assigned. They can now approve this level\'s registration forms.');
    }

    public function removeAdviser(Request $request, \App\Models\CourseAdviser $adviser): RedirectResponse
    {
        abort_unless(self::canEdit($request->user(), 'programme', $adviser->programme_id), 403);
        $adviser->delete();

        return back()->with('success', 'Course adviser removed.');
    }

    /** Add or remove a department administrator. */
    public function admins(Request $request, Department $department): RedirectResponse
    {
        abort_unless($request->user()->can('Edit academic rules'), 403);
        $d = $request->validate(['user_id' => ['required', 'exists:users,id'], 'remove' => ['nullable', 'boolean']]);
        $request->boolean('remove') ? $department->admins()->detach($d['user_id']) : $department->admins()->syncWithoutDetaching([$d['user_id']]);

        return back()->with('success', $request->boolean('remove') ? 'Administrator removed.' : 'Administrator added. With "Edit department academic rules" they can now change this department\'s rules.');
    }
}
