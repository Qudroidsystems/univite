<?php

namespace App\Http\Middleware;

use App\Models\Applicant;
use Closure;
use Illuminate\Http\Request;

/** Lets an applicant who signed in on the public application pages through. */
class ApplicantAuth
{
    public function handle(Request $request, Closure $next)
    {
        $applicant = ($id = $request->session()->get('applicant_id')) ? Applicant::find($id) : null;

        if (! $applicant) {
            $request->session()->forget('applicant_id');

            return redirect()->route('apply.login');
        }

        $request->attributes->set('applicant', $applicant);

        return $next($request);
    }
}
