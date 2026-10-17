<?php

namespace App\Http\Controllers;

use App\Models\AcademicSession;
use App\Models\PeriodSetting;
use App\Models\Semester;
use App\Models\SemesterType;
use App\Models\Setting;
use App\Services\RegistrationSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * Course registration settings: the university's defaults, and what a session or one of its semesters sets for
 * itself (open or closed, approval, clearance, fee check, late registration) together with its registration windows.
 */
class RegistrationSettingsController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:View registration settings')->only('index');
        $this->middleware('permission:Edit registration settings')->only('update');
    }

    public function index(Request $request): View
    {
        $sessions = AcademicSession::orderByDesc('name')->get();
        $types = SemesterType::orderBy('sort_order')->get();
        $session = $sessions->firstWhere('id', (int) $request->query('session'));
        $type = $session ? $types->firstWhere('id', (int) $request->query('semester')) : null;

        return view('registration.settings', [
            'pagetitle' => 'Registration settings', 's' => RegistrationSettings::all(), 'feeRules' => RegistrationSettings::FEE_RULES,
            'sessions' => $sessions, 'types' => $types, 'session' => $session, 'type' => $type,
            'period' => $session ? RegistrationSettings::period($session->id, $type?->id) : null,
            'parent' => $session && $type ? RegistrationSettings::period($session->id) : null,
            'semesters' => $session ? $session->semesters()->orderBy('sort_order')->get()
                ->when($type, fn ($c) => $c->where('sort_order', $type->sort_order)) : collect(),
            'overridden' => PeriodSetting::with('session', 'semesterType')->where('group', 'registration')->get()
                ->filter(fn ($p) => ! empty($p->values))->sortByDesc(fn ($p) => $p->session?->name . '|' . ($p->semesterType?->sort_order ?? 0)),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        if ($request->filled('session')) {
            return $this->updatePeriod($request);
        }

        $data = $request->validate([
            'min_units' => ['required', 'integer', 'between:0,60'],
            'max_units' => ['required', 'integer', 'between:1,80', 'gte:min_units'],
            'fee_rule'  => ['required', 'in:' . implode(',', array_keys(RegistrationSettings::FEE_RULES))],
        ]);

        foreach (['open', 'require_approval', 'require_clearance', 'force_carryovers', 'check_prerequisites', 'carryover_exempt'] as $flag) {
            Setting::put("registration.$flag", $request->boolean($flag) ? '1' : '0');
        }
        foreach ($data as $k => $v) {
            Setting::put("registration.$k", (string) $v);
        }

        return back()->with('success', 'Registration settings saved.');
    }

    /** Save what a session or semester sets for itself; a setting left on "Use …" is inherited. */
    private function updatePeriod(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'session' => ['required', 'exists:academic_sessions,id'],
            'semester' => ['nullable', 'exists:semester_types,id'],
            'own' => ['nullable', 'array'], 'own.*' => ['in:1'],
            'fee_rule' => ['nullable', 'in:' . implode(',', array_keys(RegistrationSettings::FEE_RULES))],
            'late_registration_days' => ['nullable', 'required_with:own.late_registration_days', 'integer', 'between:1,120'],
            'windows' => ['nullable', 'array'],
            'windows.*.opens' => ['nullable', 'date'],
            'windows.*.closes' => ['nullable', 'date', 'after:windows.*.opens'],
        ], ['windows.*.closes.after' => 'A registration window must close after it opens.']);

        $values = [];
        foreach (RegistrationSettings::PERIOD as $name => [, , $kind]) {
            if (empty($data['own'][$name])) {
                continue;
            }
            $values[$name] = match ($kind) {
                'bool' => $request->boolean($name) ? '1' : '0',
                'fee' => (string) ($data['fee_rule'] ?? 'none'),
                default => (string) (int) $request->input($name),
            };
        }

        $keys = ['academic_session_id' => (int) $data['session'], 'semester_type_id' => $data['semester'] ?? null, 'group' => 'registration'];
        if ($values) {
            PeriodSetting::updateOrCreate($keys, ['values' => $values, 'updated_by' => $request->user()->id]);
        } else {
            PeriodSetting::for($keys['academic_session_id'], $keys['semester_type_id'])->delete();
        }

        foreach ($data['windows'] ?? [] as $id => $w) {
            Semester::where('academic_session_id', $keys['academic_session_id'])->whereKey((int) $id)->update([
                'registration_opens_at' => filled($w['opens'] ?? null) ? Carbon::parse($w['opens']) : null,
                'registration_closes_at' => filled($w['closes'] ?? null) ? Carbon::parse($w['closes']) : null,
            ]);
        }

        return back()->with('success', $values ? count($values) . ' setting(s) saved for this period; the rest are inherited.' : 'This period now follows the settings above it.');
    }
}
