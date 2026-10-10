<?php

namespace App\Http\Controllers;

use App\Support\Institution;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** The institution's profile: name, address, contacts, principal officers, logo and branding. */
class InstitutionController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:View institution profile')->only('edit');
        $this->middleware('permission:Edit institution profile')->only('update');
    }

    public function edit(): View
    {
        return view('institution.edit', [
            'pagetitle' => 'Institution profile', 'values' => Institution::all(),
            'groups' => collect(Institution::FIELDS)->groupBy(fn ($f) => $f[0], true),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $rules = [];
        foreach (Institution::FIELDS as $key => [, $label, $type]) {
            $rules[$key] = match ($type) {
                'email' => ['nullable', 'email', 'max:150'],
                'url' => ['nullable', 'url', 'max:255'],
                'color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
                'year' => ['nullable', 'integer', 'between:1800,' . now()->year],
                'textarea' => ['nullable', 'string', 'max:2000'],
                'image' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp,ico', 'max:2048'],
                default => ['nullable', 'string', 'max:255'],
            };
        }
        $rules['name'] = ['required', 'string', 'max:255'];
        $data = $request->validate($rules, [], collect(Institution::FIELDS)->map(fn ($f) => $f[1])->all());

        $before = Institution::raw();
        foreach (Institution::FIELDS as $key => [, , $type]) {
            if ($type !== 'image') {
                Institution::put($key, isset($data[$key]) && $data[$key] !== '' ? trim((string) $data[$key]) : null);
                continue;
            }
            // Replaced images are kept on disk so the change can be reversed from the operation history.
            if ($request->hasFile($key)) {
                Institution::put($key, $request->file($key)->store('institution', 'public'));
            } elseif ($request->boolean("remove_{$key}")) {
                Institution::put($key, null);
            }
        }
        $after = Institution::raw();
        $changed = array_keys(array_filter($after, fn ($v, $k) => ($before[$k] ?? null) !== $v, ARRAY_FILTER_USE_BOTH));
        if ($changed) {
            $audit = app(\App\Services\OperationAudit::class);
            $op = $audit->begin('institution_update', 'settings', $request->user());
            foreach ($changed as $k) {
                $audit->record($op, 'field_changed', null, ['key' => $k, 'value' => $before[$k] ?? null], ['key' => $k, 'value' => $after[$k]], null, null, Institution::FIELDS[$k][1]);
            }
            $audit->finish($op, count($changed));
        }

        return back()->with('success', 'Institution profile saved.');
    }
}
