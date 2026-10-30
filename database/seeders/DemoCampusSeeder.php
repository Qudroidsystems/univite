<?php

namespace Database\Seeders;

use App\Models\AcademicSession;
use App\Models\BedAllocation;
use App\Models\Department;
use App\Models\Hostel;
use App\Models\HostelRate;
use App\Models\HostelRoom;
use App\Models\LibraryCopy;
use App\Models\LibraryItem;
use App\Models\LibraryLoan;
use App\Models\Student;
use App\Models\TpSchool;
use App\Models\User;
use App\Models\Venue;
use Database\Seeders\Demo\DemoPeople;
use Illuminate\Database\Seeder;

/**
 * Sample data, part 6: lecture and examination venues, hostels with rooms and this session's bed allocations,
 * a small library with copies on loan (a few overdue), and partner schools for teaching practice.
 */
class DemoCampusSeeder extends Seeder
{
    /** code => [name, type, building, capacity, exam capacity, facilities] */
    private const VENUES = [
        'AUD'   => ['University Auditorium', 'auditorium', 'Auditorium Complex', 1200, 600, ['public_address', 'generator', 'cctv', 'air_conditioning']],
        'EXH1'  => ['Examination Hall 1', 'exam_hall', 'Examination Complex', 500, 400, ['cctv', 'generator']],
        'EXH2'  => ['Examination Hall 2', 'exam_hall', 'Examination Complex', 350, 280, ['cctv', 'generator']],
        'LT1'   => ['Lecture Theatre 1', 'lecture_hall', 'Faculty of Education Block', 300, 150, ['projector', 'public_address']],
        'LT2'   => ['Lecture Theatre 2', 'lecture_hall', 'Faculty of Science Block', 250, 120, ['projector', 'public_address']],
        'LT3'   => ['Lecture Theatre 3', 'lecture_hall', 'Faculty of Arts Block', 200, 100, ['projector']],
        'CR101' => ['Classroom 101', 'classroom', 'New Classroom Block', 80, 40, ['projector']],
        'CR102' => ['Classroom 102', 'classroom', 'New Classroom Block', 80, 40, []],
        'CR201' => ['Classroom 201', 'classroom', 'New Classroom Block', 60, 30, ['smart_board']],
        'CBT'   => ['CBT Centre', 'computer_lab', 'ICT Centre', 250, 250, ['computers', 'internet', 'air_conditioning', 'generator', 'cctv']],
        'CHMLB' => ['Chemistry Laboratory', 'laboratory', 'Faculty of Science Block', 60, 0, ['science_lab_equipment']],
        'BIOLB' => ['Biology Laboratory', 'laboratory', 'Faculty of Science Block', 60, 0, ['science_lab_equipment']],
        'SEM1'  => ['Senate Seminar Room', 'seminar_room', 'Senate Building', 40, 0, ['projector', 'air_conditioning']],
    ];

    /** [name, code, gender, blocks, rooms per block, beds per room, rate] */
    private const HOSTELS = [
        ['Obafemi Awolowo Hall', 'OAH', 'male', ['A', 'B'], 10, 4, 45000],
        ['Queen Amina Hall', 'QAH', 'female', ['A', 'B', 'C'], 10, 4, 45000],
        ['Postgraduate Lodge', 'PGL', 'mixed', ['A'], 8, 2, 70000],
    ];

    /** [title, authors, publisher, year, subject, kind] */
    private const BOOKS = [
        ['Introduction to Educational Management', 'Peretomode, V. F.', 'Joja Press', 2014, 'Education', 'book'],
        ['Guidance and Counselling: Principles and Practice', 'Egbochuku, E. O.', 'University of Benin Press', 2008, 'Education', 'book'],
        ['Sociology of Education', 'Ezewu, E.', 'Longman Nigeria', 2003, 'Education', 'book'],
        ['Child Development and Early Learning', 'Oduolowu, E.', 'Franco-Ola Publishers', 2011, 'Education', 'book'],
        ['Fundamentals of Computer Science', 'Adeyemo, A. B.', 'Spectrum Books', 2016, 'Computer Science', 'book'],
        ['Data Structures and Algorithms', 'Aho, A.; Hopcroft, J.; Ullman, J.', 'Addison-Wesley', 1983, 'Computer Science', 'book'],
        ['Further Mathematics', 'Stroud, K. A.', 'Palgrave', 2013, 'Mathematics', 'book'],
        ['Elementary Linear Algebra', 'Anton, H.', 'Wiley', 2014, 'Mathematics', 'book'],
        ['Modern Biology for Senior Secondary Schools', 'Ramalingam, S. T.', 'Africana First Publishers', 2009, 'Biology', 'book'],
        ['Organic Chemistry', 'Morrison, R. T.; Boyd, R. N.', 'Prentice Hall', 2010, 'Chemistry', 'book'],
        ['Things Fall Apart', 'Achebe, C.', 'Heinemann', 1958, 'English', 'book'],
        ['A Grammar of Contemporary English', 'Quirk, R. et al.', 'Longman', 1972, 'English', 'reference'],
        ['A History of Nigeria', 'Falola, T.; Heaton, M.', 'Cambridge University Press', 2008, 'History', 'book'],
        ['Groundwork of Nigerian History', 'Ikime, O. (ed.)', 'Heinemann', 1980, 'History', 'book'],
        ['Principles of Economics', 'Mankiw, N. G.', 'Cengage', 2020, 'Economics', 'book'],
        ['Government and Politics in Nigeria', 'Ojo, E. O.', 'John Archers', 2012, 'Political Science', 'book'],
        ['Nigerian Journal of Educational Research', 'Faculty of Education', 'University Press', 2024, 'Education', 'journal'],
        ['Oxford Advanced Learner\'s Dictionary', 'Hornby, A. S.', 'Oxford University Press', 2020, 'English', 'reference'],
    ];

    /** [name, category, town, LGA] */
    private const TP_SCHOOLS = [
        ['Ondo State Government Model School', 'secondary', 'Ondo', 'Ondo West'],
        ['St Joseph\'s College', 'secondary', 'Ondo', 'Ondo West'],
        ['Adeyemi College Staff School', 'primary', 'Ondo', 'Ondo West'],
        ['Methodist High School', 'secondary', 'Ondo', 'Ondo East'],
        ['Fiwasaye Girls\' Grammar School', 'secondary', 'Akure', 'Akure South'],
        ['Oyemekun Grammar School', 'secondary', 'Akure', 'Akure South'],
        ['St Monica\'s Primary School', 'primary', 'Ondo', 'Ondo West'],
        ['Community High School', 'secondary', 'Ile-Oluji', 'Ile-Oluji/Okeigbo'],
        ['Owo High School', 'secondary', 'Owo', 'Owo'],
        ['Christ the King Nursery and Primary School', 'primary', 'Akure', 'Akure North'],
    ];

    public function run(): void
    {
        $people = new DemoPeople(6601);
        $current = AcademicSession::current() ?? AcademicSession::orderByDesc('name')->first();

        $this->venues();
        $this->hostels($people, $current);
        $this->library($people);
        $this->tpSchools($people);
    }

    private function venues(): void
    {
        $science = Department::where('code', 'CHM')->first();
        foreach (self::VENUES as $code => [$name, $type, $building, $capacity, $exam, $facilities]) {
            $uses = $type === 'laboratory' ? ['practical'] : ($type === 'exam_hall' ? ['exam'] : ($type === 'seminar_room' ? ['meeting'] : ['lecture', 'exam']));
            Venue::updateOrCreate(['code' => $code], [
                'name' => $name, 'type' => $type, 'campus' => 'Main campus', 'building' => $building,
                'department_id' => $type === 'laboratory' ? $science?->id : null,
                'capacity' => $capacity, 'teaching_capacity' => $type === 'exam_hall' ? 0 : $capacity, 'exam_capacity' => $exam,
                'seat_rows' => $exam ? (int) ceil($exam / 20) : null, 'seats_per_row' => $exam ? 20 : null,
                'uses' => $uses, 'facilities' => $facilities, 'shared_exams' => $exam >= 250,
                'wheelchair_access' => $type !== 'laboratory', 'status' => 'available', 'is_active' => true,
            ]);
        }
        // Every school has its own block of classrooms, so the whole lecture timetable fits with no class left out.
        foreach (\App\Models\Faculty::orderBy('id')->get() as $school) {
            foreach ([1 => 150, 2 => 120, 3 => 100, 4 => 80, 5 => 60, 6 => 60, 7 => 40, 8 => 40] as $room => $capacity) {
                Venue::updateOrCreate(['code' => "{$school->code}{$room}"], [
                    'name' => "{$school->name} Room {$room}", 'type' => 'classroom', 'campus' => 'Main campus', 'building' => "{$school->name} Block",
                    'capacity' => $capacity, 'teaching_capacity' => $capacity, 'exam_capacity' => (int) floor($capacity / 2),
                    'seat_rows' => (int) ceil($capacity / 2 / 10), 'seats_per_row' => 10, 'uses' => ['lecture', 'exam'], 'facilities' => $room <= 2 ? ['projector'] : [],
                    'shared_exams' => false, 'wheelchair_access' => $room <= 4, 'status' => 'available', 'is_active' => true,
                ]);
            }
        }
    }

    private function hostels(DemoPeople $people, AcademicSession $session): void
    {
        $warden = User::role('Hostel Officer')->orderBy('id')->first();
        $rooms = [];
        foreach (self::HOSTELS as $i => [$name, $code, $gender, $blocks, $perBlock, $beds, $rate]) {
            $hostel = Hostel::updateOrCreate(['code' => $code], [
                'name' => $name, 'gender' => $gender, 'warden_id' => $warden?->id, 'is_active' => true, 'sort_order' => $i + 1,
                'description' => "{$name}: " . count($blocks) * $perBlock . " rooms of {$beds} beds.",
            ]);
            HostelRate::updateOrCreate(['hostel_id' => $hostel->id, 'academic_session_id' => $session->id], ['amount' => $rate]);
            foreach ($blocks as $block) {
                for ($r = 1; $r <= $perBlock; $r++) {
                    $room = HostelRoom::updateOrCreate(['hostel_id' => $hostel->id, 'block' => "Block {$block}", 'name' => $block . str_pad((string) $r, 2, '0', STR_PAD_LEFT)],
                        ['floor' => $r <= $perBlock / 2 ? 0 : 1, 'capacity' => $beds, 'is_active' => true]);
                    if ($gender !== 'mixed') {
                        $rooms[$gender][] = $room;
                    }
                }
            }
        }

        // About a third of this session's students have a bed: most checked in, some confirmed, a few still reserved.
        $taken = [];
        foreach (Student::where('needs_onboarding', false)->with('user')->orderBy('id')->get() as $student) {
            $gender = $student->user?->gender;
            if (! isset($rooms[$gender]) || ! $people->chance(35)) {
                continue;
            }
            $room = collect($rooms[$gender])->first(fn ($r) => ($taken[$r->id] ?? 0) < $r->capacity);
            if (! $room) {
                continue;
            }
            $bed = ($taken[$room->id] ?? 0) + 1;
            $taken[$room->id] = $bed;
            $roll = $people->int(1, 100);
            $status = $roll <= 70 ? 'checked_in' : ($roll <= 90 ? 'confirmed' : 'reserved');
            BedAllocation::updateOrCreate(['student_id' => $student->id, 'academic_session_id' => $session->id], [
                'hostel_room_id' => $room->id, 'bed' => $bed, 'status' => $status, 'allocated_by' => $warden?->id,
                'reserved_until' => $status === 'reserved' ? now()->addDays(7) : null,
                'confirmed_at' => $status !== 'reserved' ? now()->subWeeks(3) : null,
                'checked_in_at' => $status === 'checked_in' ? now()->subWeeks(2) : null,
                'checked_in_by' => $status === 'checked_in' ? $warden?->id : null,
            ]);
        }
    }

    private function library(DemoPeople $people): void
    {
        $librarian = User::role('Librarian')->orderBy('id')->first();
        $copies = [];
        foreach (self::BOOKS as $i => [$title, $authors, $publisher, $year, $subject, $kind]) {
            $item = LibraryItem::updateOrCreate(['title' => $title], [
                'kind' => $kind, 'authors' => $authors, 'publisher' => $publisher, 'year' => $year, 'subject' => $subject,
                'isbn' => '978' . str_pad((string) (100000000 + $i * 7919), 10, '0', STR_PAD_LEFT),
                'call_number' => strtoupper(substr($subject, 0, 2)) . ' ' . (100 + $i * 11) . '.' . ($i % 9 + 1),
                'is_reference' => $kind === 'reference', 'created_by' => $librarian?->id,
            ]);
            $count = $kind === 'reference' ? 1 : $people->int(2, 4);
            for ($c = 1; $c <= $count; $c++) {
                $copy = LibraryCopy::updateOrCreate(['accession_number' => sprintf('ACC%04d%02d', $i + 1, $c)], [
                    'library_item_id' => $item->id, 'location' => $subject . ' shelf', 'status' => 'available',
                    'acquired_on' => now()->subYears($people->int(1, 8))->toDateString(), 'price' => $people->int(4, 25) * 500,
                ]);
                if (! $item->is_reference) {
                    $copies[] = $copy;
                }
            }
        }

        // Loans: some returned, some out now, a few overdue.
        $borrowers = User::role('Student')->orderBy('id')->limit(60)->pluck('id')->all();
        foreach ($copies as $n => $copy) {
            if (LibraryLoan::where('library_copy_id', $copy->id)->exists() || ! $people->chance(60)) {
                continue;
            }
            $issued = now()->subDays($people->int(3, 40));
            $returned = $people->chance(40) ? $issued->copy()->addDays($people->int(2, 12)) : null;
            LibraryLoan::create([
                'library_copy_id' => $copy->id, 'user_id' => $borrowers[$n % count($borrowers)], 'issued_at' => $issued,
                'due_at' => $issued->copy()->addDays(14), 'returned_at' => $returned, 'renewals' => 0,
                'issued_by' => $librarian?->id, 'returned_to' => $returned ? $librarian?->id : null,
                'lost' => false, 'fine' => 0, 'fine_status' => 'none',
            ]);
            if (! $returned) {
                $copy->update(['status' => 'on_loan']);
            }
        }
    }

    private function tpSchools(DemoPeople $people): void
    {
        foreach (self::TP_SCHOOLS as [$name, $category, $town, $lga]) {
            $head = $people->person();
            TpSchool::updateOrCreate(['name' => $name], [
                'category' => $category, 'address' => $people->int(1, 40) . ' School Road, ' . $town, 'town' => $town,
                'lga' => $lga, 'state' => 'Ondo', 'phone' => $people->phone(), 'head_name' => "{$head['first']} {$head['last']}",
                'default_capacity' => $category === 'primary' ? 6 : 10, 'is_active' => true,
            ]);
        }
    }
}
