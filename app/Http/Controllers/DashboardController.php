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
    public function __construct()
    {
        $this->middleware('auth');
    }

    public function index()
    {
        $user = auth()->user();

        // Students have no staff permissions; they get their own landing page.
        if (! $user->can('View dashboard') && ($student = $user->student()->with('programme')->first())) {
            return view('student-home', ['pagetitle' => 'Welcome', 'student' => $student, 'checklist' => $student->needs_onboarding ? app(\App\Services\OnboardingService::class)->checklist($student) : null]);
        }

        return view('dashboard', [
            'pagetitle'       => 'Dashboard',
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
