<?php

namespace App\Http\Controllers;

use App\Models\AcademicSession;
use App\Models\Course;
use App\Models\Department;
use App\Models\Faculty;
use App\Models\Programme;
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
        return view('dashboard', [
            'pagetitle'       => 'Dashboard',
            'currentSession'  => AcademicSession::current(),
            'currentSemester' => Semester::current(),
            'stats' => [
                ['label' => 'Faculties',   'value' => Faculty::count(),    'icon' => 'ri-building-2-line',  'route' => 'academics.faculties.index',   'can' => 'View faculties'],
                ['label' => 'Departments', 'value' => Department::count(), 'icon' => 'ri-organization-chart', 'route' => 'academics.departments.index', 'can' => 'View departments'],
                ['label' => 'Programmes',  'value' => Programme::count(),  'icon' => 'ri-graduation-cap-line', 'route' => 'academics.programmes.index',  'can' => 'View programmes'],
                ['label' => 'Courses',     'value' => Course::count(),     'icon' => 'ri-book-open-line',   'route' => 'academics.courses.index',     'can' => 'View courses'],
                ['label' => 'Users',       'value' => User::count(),       'icon' => 'ri-team-line',        'route' => 'users.index',                 'can' => 'View users'],
            ],
        ]);
    }
}
