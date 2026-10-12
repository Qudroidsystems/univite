<?php

namespace App\Http\Controllers\Payments;

use App\Http\Controllers\Controller;
use App\Services\OperationAudit;
use App\Services\Payments\Gateways;
use App\Services\Payments\PaymentSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Which online gateways payers may use and their keys. */
class PaymentSettingsController extends Controller
{
    public function __construct(private PaymentSettings $settings, private Gateways $gateways, private OperationAudit $audit)
    {
        $this->middleware('permission:Edit payment settings');
    }

    public function edit(): View
    {
        return view('payments.settings', [
            'pagetitle' => 'Payment settings', 'group' => $this->settings, 'values' => $this->settings->all(),
            'gateways' => collect($this->gateways->all())->filter->isOnline()->map(fn ($g) => ['label' => $g->label(), 'on' => $this->settings->on($g->name() . '_enabled'), 'ready' => $g->isConfigured()]),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $changed = $this->settings->save($request->validate($this->settings->rules()));
        foreach ($this->gateways->all() as $g) {
            if ($g->isOnline() && $this->settings->on($g->name() . '_enabled') && ! $g->isConfigured()) {
                return back()->with('warning', "Saved, but {$g->label()} is switched on without all its keys, so payers will not see it yet.");
            }
        }
        if ($changed) {
            $this->audit->note('payment_settings', 'payments', $request->user(), 'settings_changed', null, array_map(fn ($c) => $c[0], $changed), array_map(fn ($c) => $c[1], $changed));
        }

        return back()->with('success', 'Payment settings saved.');
    }
}
