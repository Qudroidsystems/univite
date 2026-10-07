<?php

namespace App\Http\Controllers\Academics;

use App\Http\Controllers\Controller;
use App\Models\AcademicSession;
use App\Models\Programme;
use App\Models\Setting;
use App\Services\MatricNumberGenerator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MatricSettingsController extends Controller
{
    public function __construct(private MatricNumberGenerator $generator)
    {
        $this->middleware('permission:View matric number format')->only('edit');
        $this->middleware('permission:Set the matric number format')->only('update');
    }

    public function edit(Request $request): View
    {
        $pattern = trim((string) $request->query('pattern', MatricNumberGenerator::pattern()));
        $error = MatricNumberGenerator::validate($pattern);

        $programme = Programme::with('department.faculty')->where('is_active', true)->orderBy('id')->first();
        $session = AcademicSession::current() ?? AcademicSession::orderByDesc('name')->first();
        $preview = ($programme && $session && ! $error) ? $this->generator->preview($programme, $session, $pattern) : null;

        return view('students.matric-settings', [
            'pagetitle' => 'Matric Number Format',
            'pattern'   => $pattern,
            'saved'     => MatricNumberGenerator::pattern(),
            'error'     => $error,
            'preview'   => $preview,
            'tokens'    => MatricNumberGenerator::TOKENS,
            'sample'    => $programme ? $programme->name . ' (' . $programme->code . '), ' . ($session?->name ?? 'no session yet') : null,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $pattern = trim((string) $request->input('pattern'));

        if ($error = MatricNumberGenerator::validate($pattern)) {
            return back()->withInput()->withErrors(['pattern' => $error]);
        }

        Setting::put(MatricNumberGenerator::SETTING_KEY, $pattern);

        return redirect()->route('students.settings')->with('success', 'Matric number format saved. It applies to students admitted from now on; existing matric numbers do not change.');
    }
}
