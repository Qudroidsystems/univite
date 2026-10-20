<?php

namespace App\Http\Controllers;

use App\Models\AcademicSession;
use App\Models\Course;
use App\Models\Department;
use App\Models\Faculty;
use App\Models\Applicant;
use App\Models\Programme;
use App\Models\Student;
use App\Models\Semester;
use App\Models\User;

class DashboardController extends Controller
{
    public function __construct(
        private \App\Services\OnboardingService $onboarding,
        private \App\Support\Navigation\Menu $menu,
        private \App\Services\RegistrationService $registration,
        private \App\Services\Fees\FeeAccess $fees,
        private \App\Services\Olevel\OlevelService $olevel,
        private \App\Services\ResultService $results,
    ) {
        $this->middleware('auth');
    }

    public function index()
    {
        $user = auth()->user();

        // Students have no staff permissions; they get their own landing page.
        $sections = $this->menu->for($user);
        if (! $user->can('View dashboard') && ($student = $user->student()->with('programme.department')->first())) {
            $ctx = $this->registration->context();
            $form = $ctx ? $student->registrationForms()->where('academic_session_id', $ctx->session->id)->where('semester_type_id', $ctx->type->id)->first() : null;
            $olevel = $this->olevel->evaluate($student);

            return view('student-home', [
                'pagetitle' => 'Welcome', 'student' => $student, 'checklist' => $student->needs_onboarding ? $this->onboarding->checklist($student) : null,
                'tiles' => collect($sections)->firstWhere('title', 'My Portal')?->items ?? [],
                'outstanding' => $this->registration->outstanding($student, true, $ctx),
                'standing' => \App\Services\Fees\FeeAccess::anyOn() ? $this->fees->standing($student) : null,
                'olevel' => $olevel->requirement && $olevel->applies ? $olevel : null,
                'ctx' => $ctx, 'form' => $form, 'transcript' => $this->results->transcript($student),
            ]);
        }

        return view('dashboard', [
            'pagetitle'       => 'Dashboard',
            // the menu, as cards: every group of pages this person may open
            'cardSections'    => collect($sections)->reject(fn ($s) => $s->title === 'Menu')->values()->all(),
            'currentSession'  => AcademicSession::current(),
            'currentSemester' => Semester::current(),
            'stats' => [
                ['label' => 'Faculties',   'value' => Faculty::count(),    'icon' => 'ri-building-2-line',  'route' => 'academics.faculties.index',   'can' => 'View faculties'],
                ['label' => 'Departments', 'value' => Department::count(), 'icon' => 'ri-organization-chart', 'route' => 'academics.departments.index', 'can' => 'View departments'],
                ['label' => 'Programmes',  'value' => Programme::count(),  'icon' => 'ri-graduation-cap-line', 'route' => 'academics.programmes.index',  'can' => 'View programmes'],
                ['label' => 'Courses',     'value' => Course::count(),     'icon' => 'ri-book-open-line',   'route' => 'academics.courses.index',     'can' => 'View courses'],
                ['label' => 'Pending applicants', 'value' => Applicant::where('status', 'pending')->count(), 'icon' => 'ri-user-add-line', 'route' => 'applicants.index', 'can' => 'View applicants'],
                ['label' => 'Students',    'value' => Student::count(),    'icon' => 'ri-user-star-line',   'route' => 'students.index',              'can' => 'View students'],
                ['label' => 'Users',       'value' => User::count(),       'icon' => 'ri-team-line',        'route' => 'users.index',                 'can' => 'View users'],
            ],
        ]);
    }
}
