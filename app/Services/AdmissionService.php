<?php

namespace App\Services;

use App\Models\Applicant;
use App\Models\Student;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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
     * @return array{0: Student, 1: string|null} the student and the temporary password (shown to the admin once).
     *         The password is null for applicants who applied online: they keep the password they chose.
     */
    public function admit(Applicant $applicant, ?string $batch = null): array
    {
        if ($applicant->status === 'admitted') {
            throw new RuntimeException('This applicant has already been admitted.');
        }

        $applicant->loadMissing('programme.department.faculty', 'session');

        if ($applicant->status === 'draft') {
            throw new RuntimeException('This applicant has not submitted their application yet.');
        }

        if (! $applicant->programme->is_active) {
            throw new RuntimeException("{$applicant->programme->name} is not accepting students. Activate the programme first.");
        }

        if (User::where('email', $applicant->email)->exists()) {
            throw new RuntimeException('A user account with this email already exists.');
        }

        $batch ??= $applicant->admission_batch;

        return DB::transaction(function () use ($applicant, $batch) {
            // By default the matric number comes later, after clearance. Until then the application number is the login name.
            $matric = MatricNumberGenerator::issueAt() === 'admission' ? $this->matric->next($applicant->programme, $applicant->session) : null;
            $chosen = $applicant->getAttributes()['password'] ?? null;   // already hashed
            $plain  = $chosen ? null : Str::password(10, symbols: false);

            $user = User::create([
                'name'                => trim($applicant->first_name . ' ' . $applicant->last_name),
                'email'               => $applicant->email,
                'username'            => $matric ?? $applicant->application_no,
                'phone_number'        => $applicant->phone_number,
                'gender'              => $applicant->gender,
                'date_of_birth'       => $applicant->date_of_birth,
                'password'            => $chosen ?: $plain,
                'must_change_password' => $chosen === null,
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
                    'status'              => \App\Models\StudentStatus::defaultCode(),
                    'admission_batch'     => $batch,
                    'needs_onboarding'    => true,
                ]
            ));

            // The passport photo they uploaded becomes the student's profile photo.
            $passport = $applicant->documents()->where('type', 'passport')->first();
            if ($passport && Storage::disk('local')->exists($passport->path)) {
                $photo = 'students/photos/' . Str::uuid() . '.' . pathinfo($passport->path, PATHINFO_EXTENSION);
                Storage::disk('public')->put($photo, Storage::disk('local')->get($passport->path));
                $student->update(['photo' => $photo]);
            }

            app(ClearanceService::class)->refresh($student);

            $applicant->update(['status' => 'admitted', 'decided_at' => now(), 'admission_batch' => $batch ?? $applicant->admission_batch]);

            return [$student, $plain];
        });
    }
}
