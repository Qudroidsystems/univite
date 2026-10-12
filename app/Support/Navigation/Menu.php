<?php

namespace App\Support\Navigation;

use App\Data\Navigation\MenuGroup;
use App\Data\Navigation\MenuLink;
use App\Data\Navigation\MenuSection;
use App\Models\CourseAdviser;
use App\Models\Department;
use App\Models\Faculty;
use App\Models\LeaveApprovalStep;
use App\Models\Payslip;
use App\Models\StaffProfile;
use App\Models\TimetableInvigilator;
use App\Models\TpPosting;
use App\Models\TpSupervisor;
use App\Models\User;
use App\Services\Access\ScopedAccess;
use App\Services\Polls\PollService;
use Illuminate\Routing\Router;

/**
 * The portal's menu, built once for the signed-in person: the sidebar draws it and the search box searches its pages.
 * A page only appears when the person may open it (by permission, or by a role held for one department or unit).
 */
class Menu
{
    public function __construct(private ScopedAccess $scoped, private PollService $polls, private Router $router)
    {
    }

    /** @return array<int, MenuSection> */
    public function for(User $u): array
    {
        $sections = [
            new MenuSection('Menu', array_values(array_filter([
                $u->can('View dashboard') ? $this->link('Dashboard', 'dashboard', 'ph-gauge', ['dashboard'], ['home', 'overview']) : null,
            ]))),
            new MenuSection('My Portal', $this->personal($u)),
        ];

        foreach ($this->groups() as $title => $groups) {
            $items = [];
            foreach ($groups as [$id, $label, $icon, $pages]) {
                $links = [];
                foreach ($pages as $p) {
                    if ($u->canAny((array) $p[2]) || $this->scoped->any($u, ...(array) $p[2])) {
                        $links[] = $this->link($p[0], $p[1], $icon, array_slice($p, 3), [$label]);
                    }
                }
                if ($links) {
                    $items[] = new MenuGroup($id, $label, $icon, $links);
                }
            }
            $sections[] = new MenuSection($title, $items);
        }

        return array_values(array_filter($sections, fn (MenuSection $s) => $s->items !== []));
    }

    /**
     * Every page of a built menu as a flat list, for the search box.
     *
     * @param  array<int, MenuSection>  $sections
     * @return array<int, array{title: string, url: string, icon: string, category: string, keywords: array<int, string>}>
     */
    public function pages(array $sections): array
    {
        $pages = [];
        foreach ($sections as $section) {
            foreach ($section->items as $item) {
                $links = $item instanceof MenuGroup ? $item->links : [$item];
                $category = $item instanceof MenuGroup ? $item->label : $section->title;
                foreach ($links as $l) {
                    $pages[] = ['title' => $l->label, 'url' => $l->url, 'icon' => $l->icon, 'category' => $category, 'keywords' => $l->keywords];
                }
            }
        }

        return $pages;
    }

    private function link(string $label, string $route, string $icon, array $patterns, array $keywords = [], ?int $badge = null): MenuLink
    {
        return new MenuLink($label, route($route), $icon, $this->router->is(...($patterns ?: [$route])), $badge, $keywords);
    }

    /** Pages about the person themselves: their courses, fees, leave, library and so on. */
    private function personal(User $u): array
    {
        $student = $u->student;
        $settled = $student && (! $student->needs_onboarding || $student->cleared_at);
        $links = [];
        $add = function (bool $show, string $label, string $route, string $icon, array $patterns = [], array $keywords = [], ?int $badge = null) use (&$links) {
            if ($show) {
                $links[] = $this->link($label, $route, $icon, $patterns, $keywords, $badge);
            }
        };

        $add((bool) $student?->needs_onboarding, 'Getting Started', 'my.onboarding', 'ph-flag', ['my.onboarding*'], ['admission', 'clearance', 'acceptance']);
        $add($settled, 'Course Registration', 'my.registration', 'ph-note-pencil', ['my.registration*'], ['courses', 'register']);
        $add($this->scoped->scope($u, 'View course registrations')->isEmpty() && CourseAdviser::where('user_id', $u->id)->exists(), 'My Advisees', 'registrations.index', 'ph-users-three', ['registrations.*'], ['course adviser']);
        $add($u->courseAssignments()->exists() && ! $u->can('View result sheets'), 'My Courses & Scores', 'results.sheets.index', 'ph-list-checks', ['results.*'], ['score sheet', 'results']);
        $add($settled, 'My Results', 'my.results', 'ph-medal', ['my.results*'], ['grades', 'gpa', 'cgpa', 'transcript']);
        $add((bool) $student, 'My Fees', 'my.fees', 'ph-wallet', ['my.fees*', 'my.receipt'], ['pay', 'invoice', 'receipt', 'school fees']);
        $add($settled, 'My Timetable', 'my.timetable', 'ph-calendar', ['my.timetable'], ['lectures', 'classes']);
        $add($settled, 'My Examinations', 'my.exams', 'ph-exam', ['my.exams*'], ['exam', 'seat number']);
        if (! $student) {
            $add($u->courseAssignments()->exists() || TimetableInvigilator::where('user_id', $u->id)->exists(), 'My Teaching & Duties', 'my.teaching', 'ph-chalkboard-teacher', ['my.teaching'], ['invigilation', 'lectures']);
            $add($u->courseAssignments()->exists() && ! $u->can('Manage course materials'), 'Course Materials', 'campus.materials', 'ph-folder-open', ['campus.materials*'], ['notes', 'slides', 'handouts']);
            $add((bool) $u->staffProfile?->department_id && ! $u->can('Post to the campus feed') && ! $u->can('Moderate the campus feed'), 'Department Feed', 'campus.feed', 'ph-megaphone', ['campus.feed*'], ['announcement', 'post', 'event']);
            $add(Payslip::where('user_id', $u->id)->exists(), 'My Payslips', 'my.payslips', 'ph-money', ['my.payslips*'], ['salary', 'pay']);
            $add(true, 'My Leave', 'leave.index', 'ph-suitcase', ['leave.index', 'leave.create', 'leave.edit', 'leave.show', 'leave.calendar'], ['holiday', 'annual leave']);
            $approver = $u->can('Approve leave for any staff') || Department::where('hod_id', $u->id)->exists() || Faculty::where('dean_id', $u->id)->exists()
                || StaffProfile::where('supervisor_id', $u->id)->exists() || ($u->roles->isNotEmpty() && LeaveApprovalStep::whereIn('role_name', $u->roles->pluck('name'))->exists());
            $add($approver, 'Leave Approvals', 'leave.approvals', 'ph-tray', ['leave.approvals'], ['approve leave']);
        }
        $add(true, 'Polls & Elections', 'my.polls', 'ph-check-square', ['my.polls*'], ['vote', 'election'], $this->polls->waiting($u) ?: null);
        $add($student && TpPosting::where('student_id', $student->id)->exists(), 'Teaching Practice', 'my.tp', 'ph-presentation', ['my.tp*'], ['tp', 'posting']);
        $add($u->staffProfile && TpSupervisor::where('user_id', $u->id)->exists(), 'TP Supervision', 'tp.supervise', 'ph-presentation', ['tp.supervise', 'tp.assess*'], ['teaching practice']);
        $add((bool) $student, 'My Accommodation', 'my.accommodation', 'ph-bed', ['my.accommodation*'], ['hostel', 'bed']);
        $add($student || $u->staffProfile, 'My ID Card', 'my.id-card', 'ph-identification-card', ['my.id-card*'], ['identity card']);
        $add($student || $u->staffProfile, 'My Library', 'my.library', 'ph-books', ['my.library*'], ['books', 'loans']);
        $add($student || $u->staffProfile, 'My Health', 'my.health', 'ph-heartbeat', ['my.health*'], ['clinic', 'medical']);

        return $links;
    }

    /**
     * Staff pages, by sidebar section and group. Each page: [label, route, permission(s), route patterns that mark it active ...].
     *
     * @return array<string, array<int, array>> section => [id, label, icon, pages]
     */
    private function groups(): array
    {
        return [
            'Academics' => [
                ['sidebarAcademic', 'Academic Setup', 'ph-graduation-cap', [
                    ['Institution Profile', 'institution.edit', 'View institution profile', 'institution.*'],
                    ['Academic Sessions', 'academics.sessions.index', 'View academic sessions', 'academics.sessions.*'],
                    ['Semesters', 'academics.semesters.index', 'View semesters', 'academics.semesters.*'],
                    ['Faculties', 'academics.faculties.index', 'View faculties', 'academics.faculties.*'],
                    ['Departments', 'academics.departments.index', 'View departments', 'academics.departments.*'],
                    ['Programmes', 'academics.programmes.index', 'View programmes', 'academics.programmes.*'],
                    ['Courses', 'academics.courses.index', 'View courses', 'academics.courses.*'],
                    ['Curriculum', 'academics.curriculum.index', 'View programme curriculum', 'academics.curriculum.*'],
                    ['Study Categories', 'academics.study-categories.index', 'View study categories', 'academics.study-categories.*'],
                    ['Semester Types', 'academics.semester-types.index', 'View semester types', 'academics.semester-types.*'],
                    ['Student Statuses', 'academics.student-statuses.index', 'View student statuses', 'academics.student-statuses.*'],
                    ['Entry Modes', 'academics.entry-modes.index', 'View entry modes', 'academics.entry-modes.*'],
                    ['Clearance Items', 'academics.clearance-items.index', 'View clearance items', 'academics.clearance-items.*'],
                    ['Custom Form Fields', 'academics.custom-fields.index', 'View custom fields', 'academics.custom-fields.*'],
                    ['Grading Scale', 'academics.grade-bands.index', 'View grade bands', 'academics.grade-bands.*'],
                    ['Classes of Degree', 'academics.degree-classes.index', 'View degree classes', 'academics.degree-classes.*'],
                    ['Academic Rules', 'academics.rules.index', ['View academic rules', 'Edit academic rules', 'Edit department academic rules'], 'academics.rules.*'],
                    ['Course Lecturers', 'academics.course-assignments.index', 'View course assignments', 'academics.course-assignments.*'],
                    ['Assessment Schemes', 'academics.assessments.index', 'View assessment schemes', 'academics.assessments.*'],
                    ['Result Approval Steps', 'academics.result-stages.index', 'View result stages', 'academics.result-stages.*'],
                    ['Reference Lists', 'academics.lists.index', 'View reference lists', 'academics.lists.*'],
                    ['Copy Session Setup', 'sessions.clone', 'Copy session setup', 'sessions.clone*'],
                    ['Session Rules', 'sessions.rules', 'View session rules', 'sessions.rules*'],
                ]],
                ['sidebarStudents', 'Admissions & Students', 'ph-student', [
                    ['Applicants', 'applicants.index', 'View applicants', 'applicants.index', 'applicants.show'],
                    ['New Student Verification', 'verification.index', 'View students', 'verification.*'],
                    ['Clearance', 'clearance.index', 'View clearance', 'clearance.*'],
                    ['Application Settings', 'applicants.settings', 'View application settings', 'applicants.settings*'],
                    ['Students', 'students.index', 'View students', 'students.index', 'students.show'],
                    ['Course Registrations', 'registrations.index', 'View course registrations', 'registrations.index', 'registrations.show'],
                    ['Registration Settings', 'registrations.settings', 'View registration settings', 'registrations.settings*'],
                    ['Matric Numbers', 'matric.index', 'View matric numbers', 'matric.*'],
                    ['Matric Number Format', 'students.settings', 'View matric number format', 'students.settings*'],
                ]],
                ['sidebarResults', 'Results', 'ph-chart-bar', [
                    ['Score Sheets', 'results.sheets.index', 'View result sheets', 'results.sheets.*', 'results.open'],
                    ['Broadsheet', 'results.broadsheet', 'View student results', 'results.broadsheet'],
                    ['Result Summary', 'results.summary', ['View student results', 'Edit academic rules', 'Edit department academic rules'], 'results.summary*'],
                    ['Result Queries', 'results.complaints', 'View result complaints', 'results.complaints*'],
                    ['Legacy Results', 'legacy.index', 'Import legacy results', 'legacy.*'],
                    ['Graduation Lists', 'graduation.index', 'View graduation lists', 'graduation.*'],
                    ['Result Settings', 'results.settings', 'View result settings', 'results.settings*'],
                ]],
                ['sidebarPlanning', 'Venues & Timetables', 'ph-calendar-check', [
                    ['Venues', 'venues.index', 'View venues', 'venues.index', 'venues.show', 'venues.create', 'venues.edit'],
                    ['Venue Availability', 'venues.availability', 'View venues', 'venues.availability'],
                    ['Venue Usage', 'venues.usage', 'View venues', 'venues.usage'],
                    ['Timetables', 'timetables.index', 'View timetables', 'timetables.*', 'seating.*'],
                    ['Staff Availability', 'staff-availability.index', 'Set staff availability', 'staff-availability.*'],
                ]],
            ],
            'Bursary & Finance' => [
                ['sidebarBills', 'School Bills', 'ph-receipt', [
                    ['Bill Items', 'bills.items.index', 'View bill items', 'bills.items.*'],
                    ['School Bills', 'bills.structure.index', 'View school bills', 'bills.structure.*'],
                ]],
                ['sidebarFees', 'Fees & Payments', 'ph-wallet', [
                    ['Invoices & Payments', 'invoices.index', 'View invoices', 'invoices.*'],
                    ['Payment Settings', 'payments.settings', 'Edit payment settings', 'payments.settings*'],
                ]],
            ],
            'Staff' => [
                ['sidebarStaff', 'Staff & Payroll', 'ph-users-three', [
                    ['Staff Records', 'staff.index', ['View staff records', 'Edit staff profiles'], 'staff.index', 'staff.show'],
                    ['Retirements Due', 'staff.retirements', ['View staff records', 'Edit staff profiles'], 'staff.retirements'],
                    ['Units', 'units.index', 'View units', 'units.*'],
                    ['Payroll', 'payroll.runs.index', ['View payroll', 'Prepare payroll', 'Approve payroll'], 'payroll.runs.*', 'payroll.payslips.*'],
                    ['Salary Structures', 'payroll.structures.index', ['Set payroll policy', 'View payroll'], 'payroll.structures.*', 'payroll.scales.*'],
                    ['Allowances & Deductions', 'payroll.elements.index', ['Set payroll policy', 'View payroll'], 'payroll.elements.*'],
                    ['Payroll Settings', 'payroll.settings', ['Set payroll policy', 'View payroll'], 'payroll.settings*'],
                ]],
                ['sidebarLeave', 'Staff Leave', 'ph-suitcase', [
                    ['Leave Register', 'leave.register', ['View leave applications', 'Approve leave for any staff', 'Record staff resumption'], 'leave.register'],
                    ['Leave Balances', 'leave.balances', ['View leave applications', 'Change leave balances'], 'leave.balances*'],
                    ['Leave Settings', 'leave.settings', 'Set leave policy', 'leave.settings', 'leave.types.*', 'leave.entitlements.*'],
                    ['Holiday Calendar', 'leave.holidays.index', ['Set leave policy', 'Create timetables'], 'leave.holidays.*'],
                ]],
            ],
            'Campus Life' => [
                ['sidebarCampus', 'Campus Services', 'ph-buildings', [
                    ['Polls & Elections', 'polls.index', ['View polls', 'Create polls', 'Publish polls'], 'polls.*'],
                    ['Identity Cards', 'id-cards.index', ['View identity cards', 'Issue identity cards'], 'id-cards.index', 'id-cards.print'],
                    ['ID Card Design', 'id-cards.settings', 'Set identity card design', 'id-cards.settings*'],
                ]],
                ['sidebarLibrary', 'Library', 'ph-books', [
                    ['Library Desk', 'library.desk', 'Issue library loans', 'library.desk'],
                    ['Catalogue', 'library.items.index', ['Edit library catalogue', 'Issue library loans', 'View library loans'], 'library.items.*', 'library.import*'],
                    ['Loans', 'library.loans', ['View library loans', 'Issue library loans'], 'library.loans'],
                    ['Fines', 'library.fines', ['View library loans', 'Issue library loans'], 'library.fines*'],
                    ['Library Rules', 'library.settings', 'Set library policy', 'library.settings*'],
                ]],
                ['sidebarHostels', 'Accommodation', 'ph-bed', [
                    ['Hostels', 'hostels.index', ['View accommodation', 'Set accommodation policy', 'Allocate accommodation', 'Check students in and out of hostels'], 'hostels.*'],
                    ['Allocation', 'accommodation.allocations', ['View accommodation', 'Allocate accommodation', 'Check students in and out of hostels'], 'accommodation.allocations'],
                    ['Accommodation Rules', 'accommodation.settings', 'Set accommodation policy', 'accommodation.settings*'],
                ]],
                ['sidebarTp', 'Teaching Practice', 'ph-presentation', [
                    ['Exercises', 'tp.exercises.index', ['View teaching practice', 'Set up teaching practice', 'Post students for teaching practice', 'Record teaching practice scores', 'Publish teaching practice scores'], 'tp.exercises.*', 'tp.letter'],
                    ['Partner Schools', 'tp.schools.index', ['View teaching practice', 'Set up teaching practice'], 'tp.schools.*'],
                ]],
                ['sidebarHealth', 'Health Centre', 'ph-heartbeat', [
                    ['Health Centre', 'clinic.index', ['View health records', 'Record clinic visits', 'Record medical examinations'], 'clinic.index', 'clinic.patient', 'clinic.excuse'],
                    ['Clinic Statistics', 'clinic.stats', 'View clinic statistics', 'clinic.stats'],
                ]],
            ],
            'Administration' => [
                ['sidebarAccess', 'Users & Access', 'ph-shield-check', [
                    ['Users', 'users.index', 'View users', 'users.*'],
                    ['Roles', 'roles.index', 'View roles', 'roles.*'],
                    ['Scoped Roles & Heads', 'scoped-roles.index', ['View scoped roles', 'Assign scoped roles'], 'scoped-roles.*'],
                    ['Permissions', 'permissions.index', 'View permissions', 'permissions.*'],
                ]],
                ['sidebarCampus', 'Campus Life', 'ph-device-mobile', [
                    ['Mobile App', 'campus.settings', 'Edit mobile app settings', 'campus.settings*'],
                    ['Campus Feed', 'campus.feed', ['Post to the campus feed', 'Moderate the campus feed'], 'campus.feed*'],
                    ['Marketplace', 'campus.market', 'Moderate the marketplace', 'campus.market*'],
                    ['Reports', 'campus.reports', 'Handle reports', 'campus.reports*'],
                    ['Course Materials', 'campus.materials', 'Manage course materials', 'campus.materials*'],
                ]],
                ['sidebarMessaging', 'Messaging', 'ph-chat-circle-text', [
                    ['Messaging Settings', 'messaging.settings', 'Edit messaging settings', 'messaging.settings*'],
                    ['Message Log', 'messaging.log', 'View message log', 'messaging.log'],
                ]],
                ['sidebarAudit', 'Records & Audit', 'ph-clock-counter-clockwise', [
                    ['Operation History', 'operations.index', 'View operation history', 'operations.*'],
                    ['Emailed Documents', 'emails.index', 'View email log', 'emails.*'],
                ]],
            ],
        ];
    }
}
