<?php

namespace App\Http\Controllers;

use App\Services\Study\GpaPlanner;
use App\Support\GradeScale;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** The GPA / CGPA target planner on the portal: the same planner as the app, for students on a computer. */
class MyGpaPlannerController extends Controller
{
    public function __invoke(Request $request, GpaPlanner $planner): View
    {
        $student = $request->user()->student ?? abort(403, 'This page is for students.');
        $ctx = $planner->context($student);
        $plan = null;
        if ($request->filled('target')) {
            $d = $request->validate([
                'target' => ['required', 'numeric', 'min:0.01', 'max:' . max(1, GradeScale::maxPoints())],
                'kind' => ['required', Rule::in(['gpa', 'cgpa'])],
                'strengths' => ['nullable', 'array'], 'strengths.*' => ['nullable', 'integer', 'between:1,5'],
            ]);
            $plan = $planner->plan($ctx, (float) $d['target'], $d['kind'], array_map('intval', array_filter($d['strengths'] ?? [])));
        }

        return view('my.gpa-planner', ['pagetitle' => 'GPA Planner', 'ctx' => $ctx, 'plan' => $plan]);
    }
}
