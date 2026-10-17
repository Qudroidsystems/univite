<?php

namespace App\Http\Controllers;

use App\Models\Student;
use App\Services\RegistrationService;
use App\Services\RegistrationSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

/** A student's own course registration: choose courses, save, submit, print the approved form. */
class StudentRegistrationController extends Controller
{
    public function __construct(private RegistrationService $registration)
    {
    }

    private function student(): Student
    {
        return auth()->user()->student ?? abort(403, 'This page is for students.');
    }

    public function index(): View
    {
        $student = $this->student()->load('programme');
        $ctx = $this->registration->context();
        $blockers = $this->registration->blockers($student, $ctx);
        $form = $ctx ? $this->registration->form($student, $ctx)->load('courses.course.department') : null;

        return view('registration.mine', [
            'pagetitle' => 'Course registration',
            'student'   => $student,
            'ctx'       => $ctx,
            'blockers'  => $blockers,
            'form'      => $form,
            'offered'   => ($ctx && ! $blockers) || ($form && ! $form->isEditable()) ? $this->registration->offered($student, $ctx) : collect(),
            'limits'    => $this->registration->limits($student, $ctx),
            'ceRule'    => $this->registration->compulsoryElectiveRule($student, $ctx, $form?->level),
            'problems'  => $form && $form->isEditable() && $form->courses->isNotEmpty() ? $this->registration->problems($form, $ctx) : [],
            'history'   => $student->registrationForms()->with('session', 'semesterType')->latest('id')->get(),
        ]);
    }

    public function save(Request $request): RedirectResponse
    {
        $data = $request->validate(['courses' => ['nullable', 'array'], 'courses.*' => ['integer'], 'submit' => ['nullable', 'boolean']]);
        $student = $this->student();
        $ctx = $this->registration->context() ?? abort(404);

        try {
            $form = $this->registration->select($this->registration->form($student, $ctx), $data['courses'] ?? [], $ctx);
            if ($request->boolean('submit')) {
                $form = $this->registration->submit($form, $ctx);

                return back()->with('success', $form->status === 'approved' ? 'Your registration is complete.' : 'Your registration has been sent for approval.');
            }
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }

        return back()->with('success', 'Your course selection has been saved. Submit it when you are ready.');
    }

    public function print(\App\Models\RegistrationForm $form): View
    {
        abort_unless($form->student_id === $this->student()->id && $form->status === 'approved', 404);

        return view('registration.slip', ['form' => $form->load('student.programme.department.faculty', 'session', 'semesterType', 'courses.course.department', 'decider')]);
    }
}
