<?php

namespace App\Http\Controllers\TeachingPractice;

use App\Http\Controllers\Controller;
use App\Models\TpSchool;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** The register of partner schools for teaching practice. */
class SchoolController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:View teaching practice|Set up teaching practice')->only('index');
        $this->middleware('permission:Set up teaching practice')->except('index');
    }

    public function index(Request $request): View
    {
        $schools = TpSchool::when(trim((string) $request->query('q')), fn ($q, $t) => $q->where(fn ($w) => $w->where('name', 'like', "%{$t}%")->orWhere('town', 'like', "%{$t}%")->orWhere('lga', 'like', "%{$t}%")))
            ->orderBy('name')->paginate(50)->withQueryString();

        return view('tp.schools', ['pagetitle' => 'Teaching practice schools', 'schools' => $schools, 'editing' => $request->query('edit') ? TpSchool::find($request->query('edit')) : null]);
    }

    public function store(Request $request): RedirectResponse
    {
        TpSchool::create($this->validated($request));

        return back()->with('success', 'School added.');
    }

    public function update(Request $request, TpSchool $school): RedirectResponse
    {
        $school->update($this->validated($request));

        return redirect()->route('tp.schools.index')->with('success', 'Saved.');
    }

    public function destroy(TpSchool $school): RedirectResponse
    {
        if (\App\Models\TpPosting::where('tp_school_id', $school->id)->exists()) {
            return back()->with('error', 'Students have been posted to this school, so it is kept. Switch it off instead.');
        }
        $school->delete();

        return back()->with('success', 'School removed.');
    }

    private function validated(Request $request): array
    {
        $d = $request->validate([
            'name' => ['required', 'string', 'max:255'], 'category' => ['required', Rule::in(array_keys(TpSchool::CATEGORIES))], 'address' => ['nullable', 'string', 'max:255'],
            'town' => ['nullable', 'string', 'max:255'], 'lga' => ['nullable', 'string', 'max:255'], 'state' => ['nullable', 'string', 'max:255'], 'phone' => ['nullable', 'string', 'max:30'],
            'head_name' => ['nullable', 'string', 'max:255'], 'default_capacity' => ['required', 'integer', 'min:1', 'max:1000'], 'is_active' => ['nullable', 'boolean'],
        ]);

        return $d + ['is_active' => (bool) ($d['is_active'] ?? true)];
    }
}
