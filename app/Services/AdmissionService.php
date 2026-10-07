<?php

namespace App\Services;

use App\Models\Applicant;
use App\Models\Student;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Spatie\Permission\Models\Role;

class AdmissionService
{
    public function __construct(private MatricNumberGenerator $matric)
    {
    }

    /**
     * Turn an applicant into a student: reserve a matric number, create the login and the student record.
     *
     * @return array{0: Student, 1: string} the student and the temporary password (shown to the admin once)
     */
    public function admit(Applicant $applicant): array
    {
        if ($applicant->status === 'admitted') {
            throw new RuntimeException('This applicant has already been admitted.');
        }

        $applicant->loadMissing('programme.department.faculty', 'session');

        if (! $applicant->programme->is_active) {
            throw new RuntimeException("{$applicant->programme->name} is not accepting students. Activate the programme first.");
        }

        if (User::where('email', $applicant->email)->exists()) {
            throw new RuntimeException('A user account with this email already exists.');
        }

        return DB::transaction(function () use ($applicant) {
            $matric = $this->matric->next($applicant->programme, $applicant->session);
            $plain  = Str::password(10, symbols: false);

            $user = User::create([
                'name'                => trim($applicant->first_name . ' ' . $applicant->last_name),
                'email'               => $applicant->email,
                'username'            => $matric,
                'phone_number'        => $applicant->phone_number,
                'gender'              => $applicant->gender,
                'date_of_birth'       => $applicant->date_of_birth,
                'password'            => $plain,
                'must_change_password' => true,
            ]);
            $user->assignRole(Role::firstOrCreate(['name' => 'Student', 'guard_name' => 'web'], ['badge' => 'badge bg-info']));

            $student = Student::create(array_merge(
                Arr::only($applicant->getAttributes(), Applicant::PROFILE_FIELDS),
                [
                    'user_id'             => $user->id,
                    'applicant_id'        => $applicant->id,
                    'matric_number'       => $matric,
                    'programme_id'        => $applicant->programme_id,
                    'academic_session_id' => $applicant->academic_session_id,
                    'entry_mode'          => $applicant->entry_mode,
                    'entry_level'         => $applicant->entry_level,
                    'current_level'       => $applicant->entry_level,
                    'status'              => 'active',
                ]
            ));

            $applicant->update(['status' => 'admitted', 'decided_at' => now()]);

            return [$student, $plain];
        });
    }
}
