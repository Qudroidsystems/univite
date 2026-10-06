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
                ['label' => 'Faculties',   'value' => Faculty::count(),    'icon' => 'ri-building-2-line',  'route' => 'academics.faculties.index',   'can' => 'View faculty'],
                ['label' => 'Departments', 'value' => Department::count(), 'icon' => 'ri-organization-chart', 'route' => 'academics.departments.index', 'can' => 'View department'],
                ['label' => 'Programmes',  'value' => Programme::count(),  'icon' => 'ri-graduation-cap-line', 'route' => 'academics.programmes.index',  'can' => 'View programme'],
                ['label' => 'Courses',     'value' => Course::count(),     'icon' => 'ri-book-open-line',   'route' => 'academics.courses.index',     'can' => 'View course'],
                ['label' => 'Users',       'value' => User::count(),       'icon' => 'ri-team-line',        'route' => 'users.index',                 'can' => 'View user'],
            ],
        ]);
    }
}
