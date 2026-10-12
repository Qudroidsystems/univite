<?php

namespace App\Http\Controllers\Payroll;

use App\Http\Controllers\Controller;
use App\Models\Payslip;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** A member of staff's own payslips, from approved payrolls only. */
class MyPayslipController extends Controller
{
    public function index(Request $request): View
    {
        return view('payroll.mine', [
            'pagetitle' => 'My payslips',
            'slips' => Payslip::with('run')->where('user_id', $request->user()->id)->whereHas('run', fn ($q) => $q->whereIn('status', ['approved', 'paid']))
                ->get()->sortByDesc(fn ($s) => $s->run->period)->values(),
        ]);
    }

    public function show(Request $request, Payslip $payslip): View
    {
        abort_unless($payslip->user_id === $request->user()->id && in_array($payslip->run->status, ['approved', 'paid'], true), 404);

        return view('payroll.payslip', ['pagetitle' => 'Payslip · ' . $payslip->run->label(), 'slip' => $payslip->load('run', 'lines'), 'mine' => true]);
    }
}
