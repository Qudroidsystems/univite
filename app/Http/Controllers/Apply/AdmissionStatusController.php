<?php

namespace App\Http\Controllers\Apply;

use App\Http\Controllers\Controller;
use App\Models\Applicant;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Public page where an applicant checks the outcome of their application without signing in. */
class AdmissionStatusController extends Controller
{
    public function form(): View
    {
        return view('apply.status', ['result' => null]);
    }

    public function check(Request $request): View
    {
        $data = $request->validate([
            'jamb_reg_no' => ['required', 'string', 'max:20'],
            'last_name'   => ['required', 'string', 'max:100'],
        ]);

        // Both must match, so a JAMB number alone reveals nothing about anyone.
        $applicant = Applicant::with('programme', 'student')
            ->where('jamb_reg_no', strtoupper(trim($data['jamb_reg_no'])))
            ->whereRaw('LOWER(last_name) = ?', [mb_strtolower(trim($data['last_name']))])
            ->first();

        return view('apply.status', ['result' => $applicant ?? false, 'old' => $data]);
    }
}
