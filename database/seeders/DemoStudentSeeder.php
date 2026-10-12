<?php

namespace Database\Seeders;

use App\Models\AcademicSession;
use App\Models\Applicant;
use App\Models\Programme;
use App\Models\Student;
use App\Models\User;
use App\Services\MatricNumberGenerator;
use Database\Seeders\Demo\DemoPeople;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

/**
 * Sample data, part 3: six students in every level of every programme (each admitted in the session that puts them at
 * that level now, with a matric number), plus applicants waiting for a decision this session. A few new students
 * are still completing onboarding. Every account's password is "password".
 */
class DemoStudentSeeder extends Seeder
{
    public const PER_LEVEL = 6;

    public function run(MatricNumberGenerator $matric): void
    {
        $people = new DemoPeople(3301);
        $role = Role::firstOrCreate(['name' => 'Student', 'guard_name' => 'web'], ['badge' => 'badge bg-info']);
        $sessions = AcademicSession::orderBy('name')->get()->values();
        $current = AcademicSession::current() ?? $sessions->last();
        $currentIndex = $sessions->search(fn ($s) => $s->id === $current->id);
        $n = 0;

        foreach (Programme::orderBy('id')->get() as $programme) {
            foreach ([100, 200, 300, 400] as $level) {
                for ($i = 0; $i < self::PER_LEVEL; $i++) {
                    $n++;
                    $direct = $level === 200 && $i === 5;   // one direct-entry student per programme, admitted straight into 200 level this session
                    $entry = $direct ? $current : $sessions[max(0, $currentIndex - ($level / 100 - 1))];
                    $onboarding = $level === 100 && $i === 5;
                    $p = $people->person();
                    [$state, $lga] = $people->origin();
                    $year = (int) substr($entry->name, 0, 4);
                    $email = $people->slug($p['first'], $p['last'], $n) . '@student.univite.test';
                    if (User::where('email', $email)->exists()) {
                        continue;
                    }
                    $profile = [
                        'first_name' => $p['first'], 'last_name' => $p['last'], 'other_names' => $p['other'], 'gender' => $p['gender'], 'phone_number' => $people->phone(),
                        'date_of_birth' => now()->setYear($year - $people->int(17, 21))->subDays($people->int(0, 360))->toDateString(),
                        'state_of_origin' => $state, 'lga' => $lga, 'nationality' => 'Nigerian', 'address' => $people->int(1, 120) . ' ' . $people->pick(['Oba Adesida Road', 'Hospital Road', 'Ijoka Road', 'Oke-Ijebu Street', 'Alagbaka Estate', 'Adebayo Street']) . ', ' . $lga,
                        'guardian_name' => ($p['gender'] === 'male' ? 'Mrs ' : 'Mr ') . $people->person()['first'] . ' ' . $p['last'], 'guardian_relationship' => $people->pick(['Father', 'Mother', 'Uncle', 'Guardian']),
                        'guardian_phone' => $people->phone(), 'guardian_address' => $state . ' State',
                    ];
                    $applicant = Applicant::create($profile + [
                        'application_no' => sprintf('APP/%d/%05d', $year, $n), 'email' => $email, 'jamb_reg_no' => sprintf('%d%07d%s', $year, $people->int(0, 9999999), $people->pick(['AB', 'CD', 'EF', 'GH'])),
                        'jamb_score' => $direct ? null : $people->int(180, 290), 'programme_id' => $programme->id, 'academic_session_id' => $entry->id,
                        'entry_mode' => $direct ? 'direct_entry' : 'utme', 'entry_level' => $direct ? 200 : 100, 'status' => 'admitted', 'decided_at' => now()->setYear($year)->setMonth(9),
                        'submitted_at' => now()->setYear($year)->setMonth(7), 'admission_batch' => 'First Batch', 'password' => DemoStaffSeeder::passwordHash(),
                    ]);
                    $number = $onboarding ? null : $matric->next($programme, $entry);
                    $user = User::create([
                        'name' => "{$p['first']} {$p['last']}", 'email' => $email, 'username' => $number ?? $applicant->application_no, 'phone_number' => $profile['phone_number'],
                        'gender' => $p['gender'], 'date_of_birth' => $profile['date_of_birth'], 'password' => DemoStaffSeeder::passwordHash(),
                    ]);
                    $user->assignRole($role);
                    Student::create($profile + [
                        'user_id' => $user->id, 'applicant_id' => $applicant->id, 'matric_number' => $number, 'programme_id' => $programme->id, 'academic_session_id' => $entry->id,
                        'entry_mode' => $applicant->entry_mode, 'entry_level' => $applicant->entry_level, 'current_level' => $level, 'status' => 'active',
                        'admission_batch' => 'First Batch', 'needs_onboarding' => $onboarding, 'offer_accepted_at' => $onboarding ? null : $applicant->decided_at,
                        'verified_at' => $onboarding ? null : $applicant->decided_at, 'cleared_at' => $onboarding ? null : $applicant->decided_at->copy()->addWeeks(3),
                    ]);
                }
            }
        }

        // Applicants waiting for a decision this session, and a few already turned down.
        $year = (int) substr($current->name, 0, 4);
        $programmes = Programme::orderBy('id')->get();
        for ($i = 1; $i <= 30; $i++) {
            $p = $people->person();
            [$state, $lga] = $people->origin();
            $email = $people->slug($p['first'], $p['last'], 900 + $i) . '@applicant.univite.test';
            Applicant::firstOrCreate(['email' => $email], [
                'first_name' => $p['first'], 'last_name' => $p['last'], 'other_names' => $p['other'], 'gender' => $p['gender'], 'phone_number' => $people->phone(),
                'date_of_birth' => $people->birthDate($people->int(17, 22)), 'state_of_origin' => $state, 'lga' => $lga, 'nationality' => 'Nigerian',
                'application_no' => sprintf('APP/%d/%05d', $year, 9000 + $i), 'jamb_reg_no' => sprintf('%d%07dXY', $year, $people->int(0, 9999999)), 'jamb_score' => $people->int(160, 300),
                'programme_id' => $people->pick($programmes->all())->id, 'academic_session_id' => $current->id, 'entry_mode' => 'utme', 'entry_level' => 100,
                'status' => $i <= 4 ? 'rejected' : ($i <= 8 ? 'draft' : 'pending'), 'submitted_at' => $i <= 8 && $i > 4 ? null : now()->subDays($people->int(3, 40)),
                'decided_at' => $i <= 4 ? now()->subDays(2) : null, 'password' => DemoStaffSeeder::passwordHash(),
            ]);
        }
    }
}
