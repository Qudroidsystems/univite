<?php

namespace App\Services\Students;

use App\Data\Imports\PeopleImportCheck;
use App\Models\AcademicSession;
use App\Models\CourseOption;
use App\Models\Department;
use App\Models\EntryMode;
use App\Models\PeopleImport;
use App\Models\Programme;
use App\Models\StaffProfile;
use App\Models\Student;
use App\Models\User;
use App\Services\OperationAudit;
use App\Support\Lookups;
use App\Support\SpreadsheetReader;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Spatie\Permission\Models\Role;

/**
 * Adds students or staff from a spreadsheet, the way the old portal's Import Students / Import Staff did: download
 * the template, fill it in (or export from the old portal), upload, check every row without saving, then import the
 * good rows. Nobody already in the portal is changed. An import can be undone while the people it added have no
 * registrations, payments or results yet. Imported people sign in with "Forgot password" or a reset by staff;
 * the portal never shows a default password.
 */
class PeopleImportService
{
    public const KINDS = ['students', 'staff'];

    /** Template columns: key => [heading, required, example]. */
    public const COLUMNS = [
        'students' => [
            'surname' => ['Surname', true, 'Adeyemi'], 'first_name' => ['First name', true, 'Tolu'], 'other_names' => ['Other names', false, 'Grace'],
            'email' => ['Email', true, 'tolu.adeyemi@example.com'], 'phone' => ['Phone', false, '08031234567'], 'gender' => ['Gender', false, 'female'],
            'date_of_birth' => ['Date of birth', false, '2004-05-17'], 'matric_number' => ['Matric number', false, 'AFE/2023/0001'],
            'jamb_reg_no' => ['JAMB number', false, '12345678AB'], 'application_ref' => ['Application ID', false, 'APP2023-551'],
            'programme' => ['Programme code', true, 'BED-BIO'], 'course_option' => ['Course option', false, ''],
            'entry_session' => ['Entry session', true, '2023/2024'], 'entry_mode' => ['Entry mode', false, 'utme'],
            'entry_level' => ['Entry level', false, '100'], 'current_level' => ['Current level', false, '300'],
            'state_of_origin' => ['State of origin', false, 'Ondo'], 'lga' => ['LGA', false, 'Ondo West'], 'nationality' => ['Nationality', false, 'Nigerian'],
            'address' => ['Address', false, '12 Oke-Aro Street, Ondo'], 'nin' => ['NIN', false, '12345678901'], 'religion' => ['Religion', false, 'christianity'],
            'guardian_name' => ['Next of kin', false, 'Mr Adeyemi'], 'guardian_phone' => ['Next of kin phone', false, '08039876543'],
        ],
        'staff' => [
            'title' => ['Title', false, 'dr'], 'surname' => ['Surname', true, 'Edafe'], 'first_name' => ['First name', true, 'John'],
            'other_names' => ['Other names', false, ''], 'email' => ['Email', true, 'j.edafe@example.edu.ng'], 'phone' => ['Phone', false, '08030000000'],
            'gender' => ['Gender', false, 'male'], 'date_of_birth' => ['Date of birth', false, '1975-02-11'], 'staff_number' => ['Staff number', false, 'SP/0451'],
            'file_number' => ['File number', false, 'DSSET/ADE/2425'], 'ippis_number' => ['IPPIS number', false, '123456'],
            'department' => ['Department code', false, 'ECO'], 'designation' => ['Designation', false, 'Senior Lecturer'],
            'category' => ['Category', false, 'academic'], 'appointment_type' => ['Appointment', false, 'permanent'], 'grade_level' => ['Grade level', false, '13'],
            'first_appointment_date' => ['First appointment', false, '2009-10-01'], 'institutional_email' => ['Institutional email', false, ''],
        ],
    ];

    public function __construct(private SpreadsheetReader $reader, private OperationAudit $audit)
    {
    }

    /** The template as CSV rows: headings, then one example row. */
    public function template(string $kind): array
    {
        $cols = self::COLUMNS[$kind] ?? throw new RuntimeException('Unknown kind of import.');

        return [array_map(fn ($c) => $c[0], array_values($cols)), array_map(fn ($c) => $c[2], array_values($cols))];
    }

    public function upload(string $kind, UploadedFile $file, User $by): PeopleImport
    {
        if (! in_array($kind, self::KINDS, true)) {
            throw new RuntimeException('Unknown kind of import.');
        }
        $ext = strtolower($file->getClientOriginalExtension());
        $path = $file->storeAs('people-imports', Str::uuid() . '.' . $ext, 'local');
        $rows = $this->reader->read(Storage::disk('local')->path($path), $ext);
        if (count($rows) < 2) {
            Storage::disk('local')->delete($path);
            throw new RuntimeException('The file needs the row of headings from the template and at least one row of people.');
        }

        return PeopleImport::create(['kind' => $kind, 'file_name' => $file->getClientOriginalName(), 'path' => $path, 'rows' => count($rows) - 1, 'created_by' => $by->id]);
    }

    /** Check every row and save the report; nothing else changes. */
    public function check(PeopleImport $import): PeopleImportCheck
    {
        if ($import->status === 'imported') {
            throw new RuntimeException('This file has already been imported.');
        }
        $result = $this->parse($import);
        $import->update(['status' => 'checked', 'report' => $result->toArray()]);

        return $result;
    }

    public function import(PeopleImport $import, User $by): int
    {
        if ($import->status !== 'checked') {
            throw new RuntimeException($import->status === 'imported' ? 'This file has already been imported.' : 'Check the file before importing it.');
        }
        $result = $this->parse($import);
        if (! $result->good) {
            throw new RuntimeException('No row is ready to import. Fix the problems in the file and upload it again.');
        }

        return DB::transaction(function () use ($import, $by, $result) {
            $op = $this->audit->begin('people_import', $import->kind, $by, null, null, ['file' => $import->file_name]);
            $ids = [];
            foreach ($result->good as $row) {
                $ids[] = $import->kind === 'students' ? $this->createStudent($row)->id : $this->createStaff($row)->id;
            }
            $this->audit->record($op, 'imported', $import, null, ['rows' => count($ids)], null, null, $import->file_name);
            $this->audit->finish($op, count($ids), 0, count($result->problems));
            $import->update(['status' => 'imported', 'imported' => count($ids), 'created_ids' => $ids, 'imported_at' => now(), 'report' => $result->toArray()]);

            return count($ids);
        });
    }

    /** Remove everyone an import added, provided none of them has started using the portal. */
    public function undo(PeopleImport $import, User $by): void
    {
        if ($import->status !== 'imported') {
            throw new RuntimeException('Only an imported file can be undone.');
        }
        DB::transaction(function () use ($import, $by) {
            $ids = $import->created_ids ?? [];
            if ($import->kind === 'students') {
                $students = Student::whereIn('id', $ids)->get();
                $busy = $students->filter(fn ($s) => \App\Models\CourseRegistration::where('student_id', $s->id)->exists()
                    || \App\Models\CourseResult::where('student_id', $s->id)->exists()
                    || \App\Models\Invoice::where('payable_type', Student::class)->where('payable_id', $s->id)->whereHas('payments')->exists());
                if ($busy->isNotEmpty()) {
                    throw new RuntimeException('Some students from this file already have registrations, results or payments (for example ' . $busy->first()->full_name . '), so it cannot be undone.');
                }
                $userIds = $students->pluck('user_id');
                Student::whereIn('id', $ids)->delete();
            } else {
                $userIds = StaffProfile::whereIn('id', $ids)->pluck('user_id');
                StaffProfile::whereIn('id', $ids)->delete();
            }
            User::whereIn('id', $userIds)->whereDoesntHave('student')->whereDoesntHave('staffProfile')->whereNull('last_login_at')->delete();
            $import->update(['status' => 'undone', 'undone_at' => now()]);
            $this->audit->note('people_import', $import->kind, $by, 'undone', $import, ['rows' => count($ids)], null);
        });
    }

    // ---- reading and checking rows ------------------------------------------------------------------

    private function parse(PeopleImport $import): PeopleImportCheck
    {
        $path = Storage::disk('local')->path($import->path);
        if (! is_file($path)) {
            throw new RuntimeException('The uploaded file is no longer on the server. Upload it again.');
        }
        $rows = $this->reader->read($path, pathinfo($import->path, PATHINFO_EXTENSION));
        $headers = array_map(fn ($h) => self::norm((string) $h), array_shift($rows));
        $cols = self::COLUMNS[$import->kind];
        $index = [];
        foreach ($cols as $key => [$label, $required]) {
            $i = array_search(self::norm($label), $headers, true);
            if ($i === false) {
                $i = array_search(self::norm($key), $headers, true);
            }
            if ($i === false && $required) {
                throw new RuntimeException("The file has no \"{$label}\" column. Use the template's headings.");
            }
            $index[$key] = $i === false ? null : $i;
        }

        $good = [];
        $problems = [];
        $existing = 0;
        $seenEmail = [];
        $seenNumber = [];
        foreach ($rows as $i => $line) {
            $n = $i + 2;
            $v = fn (string $k) => $index[$k] === null ? '' : trim((string) ($line[$index[$k]] ?? ''));
            if (implode('', array_map('strval', $line)) === '') {
                continue;
            }
            $email = strtolower($v('email'));
            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $problems[$n] = "Email \"{$email}\" is not a valid email address.";
                continue;
            }
            if (isset($seenEmail[$email])) {
                $problems[$n] = "Email {$email} appears again (first on row {$seenEmail[$email]}).";
                continue;
            }
            $seenEmail[$email] = $n;
            if ($v('surname') === '' || $v('first_name') === '') {
                $problems[$n] = 'Surname and first name are both needed.';
                continue;
            }
            $row = $import->kind === 'students' ? $this->studentRow($v, $email, $n, $problems, $existing, $seenNumber) : $this->staffRow($v, $email, $n, $problems, $existing, $seenNumber);
            if ($row) {
                $good[$n] = $row;
            }
        }

        return new PeopleImportCheck(count($rows), $good, $problems, $existing);
    }

    private function studentRow(callable $v, string $email, int $n, array &$problems, int &$existing, array &$seenNumber): ?array
    {
        $matric = strtoupper($v('matric_number'));
        if (User::where('email', $email)->exists() || ($matric && Student::where('matric_number', $matric)->exists())) {
            $existing++;

            return null;
        }
        if ($matric !== '' && isset($seenNumber[$matric])) {
            $problems[$n] = "Matric number {$matric} appears again (first on row {$seenNumber[$matric]}).";

            return null;
        }
        $seenNumber[$matric ?: "row{$n}"] = $n;
        $programme = Programme::whereRaw('UPPER(code) = ?', [strtoupper($v('programme'))])->first();
        if (! $programme) {
            $problems[$n] = 'Programme code "' . $v('programme') . '" is not in the portal.';

            return null;
        }
        $session = AcademicSession::where('name', \App\Services\LegacyImportService::sessionName($v('entry_session')) ?? $v('entry_session'))->first();
        if (! $session) {
            $problems[$n] = 'Entry session "' . $v('entry_session') . '" is not in the portal.';

            return null;
        }
        $mode = $v('entry_mode') !== '' ? strtolower($v('entry_mode')) : 'utme';
        if (! EntryMode::where('code', $mode)->exists()) {
            $problems[$n] = "Entry mode \"{$mode}\" is not one of the portal's entry modes.";

            return null;
        }
        $entry = (int) ($v('entry_level') ?: (EntryMode::where('code', $mode)->value('entry_level') ?? 100));
        $current = (int) ($v('current_level') ?: $entry);
        if (! in_array($current, $programme->levels(), true)) {
            $problems[$n] = "{$programme->name} runs levels " . implode(', ', $programme->levels()) . "; {$current} is not one of them.";

            return null;
        }
        $option = null;
        if ($v('course_option') !== '') {
            $option = CourseOption::where('programme_id', $programme->id)->where(fn ($q) => $q->where('name', $v('course_option'))->orWhere('code', strtoupper($v('course_option'))))->first();
            if (! $option) {
                $problems[$n] = 'Course option "' . $v('course_option') . "\" is not an option of {$programme->name}.";

                return null;
            }
        }
        $dob = $this->date($v('date_of_birth'));
        if ($v('date_of_birth') !== '' && ! $dob) {
            $problems[$n] = 'Date of birth "' . $v('date_of_birth') . '" is not a date like 2004-05-17.';

            return null;
        }
        $nin = preg_replace('/\D/', '', $v('nin'));
        if ($nin !== '' && strlen($nin) !== 11) {
            $problems[$n] = 'A NIN has 11 digits.';

            return null;
        }

        return [
            'last_name' => $v('surname'), 'first_name' => $v('first_name'), 'other_names' => $v('other_names') ?: null, 'email' => $email,
            'phone_number' => $v('phone') ?: null, 'gender' => $this->gender($v('gender')), 'date_of_birth' => $dob,
            'matric_number' => $matric ?: null, 'jamb_reg_no' => strtoupper($v('jamb_reg_no')) ?: null, 'application_ref' => $v('application_ref') ?: null,
            'programme_id' => $programme->id, 'course_option_id' => $option?->id, 'sub_department_id' => $programme->sub_department_id,
            'academic_session_id' => $session->id, 'entry_mode' => $mode, 'entry_level' => $entry, 'current_level' => $current,
            'state_of_origin' => $v('state_of_origin') ?: null, 'lga' => $v('lga') ?: null, 'nationality' => $v('nationality') ?: null, 'address' => $v('address') ?: null,
            'nin' => $nin ?: null, 'religion' => $this->listCode('religion', $v('religion')), 'guardian_name' => $v('guardian_name') ?: null, 'guardian_phone' => $v('guardian_phone') ?: null,
            'label' => trim($v('surname') . ' ' . $v('first_name')) . ' · ' . ($matric ?: 'no matric number') . ' · ' . $programme->code,
        ];
    }

    private function staffRow(callable $v, string $email, int $n, array &$problems, int &$existing, array &$seenNumber): ?array
    {
        $number = $v('staff_number');
        if (User::where('email', $email)->exists() || ($number !== '' && StaffProfile::where('staff_number', $number)->exists())) {
            $existing++;

            return null;
        }
        if ($number !== '' && isset($seenNumber[$number])) {
            $problems[$n] = "Staff number {$number} appears again (first on row {$seenNumber[$number]}).";

            return null;
        }
        $seenNumber[$number ?: "row{$n}"] = $n;
        $dept = null;
        if ($v('department') !== '') {
            $dept = Department::whereRaw('UPPER(code) = ?', [strtoupper($v('department'))])->orWhere('name', $v('department'))->first();
            if (! $dept) {
                $problems[$n] = 'Department "' . $v('department') . '" is not in the portal.';

                return null;
            }
        }
        foreach (['date_of_birth', 'first_appointment_date'] as $k) {
            if ($v($k) !== '' && ! $this->date($v($k))) {
                $problems[$n] = '"' . $v($k) . '" is not a date like 1975-02-11.';

                return null;
            }
        }
        $grade = $v('grade_level');
        if ($grade !== '' && (! ctype_digit($grade) || (int) $grade < 1 || (int) $grade > 20)) {
            $problems[$n] = "Grade level \"{$grade}\" must be a whole number from 1 to 20.";

            return null;
        }

        return [
            'title' => $this->listCode('title', $v('title')), 'name' => trim($v('first_name') . ' ' . ($v('other_names') ? $v('other_names') . ' ' : '') . $v('surname')),
            'email' => $email, 'phone_number' => $v('phone') ?: null, 'gender' => $this->gender($v('gender')), 'date_of_birth' => $this->date($v('date_of_birth')),
            'staff_number' => $number ?: null, 'file_number' => $v('file_number') ?: null, 'ippis_number' => $v('ippis_number') ?: null, 'department_id' => $dept?->id,
            'designation' => $v('designation') ?: null, 'category' => $this->listCode('staff_category', $v('category')), 'appointment_type' => $this->listCode('appointment_type', $v('appointment_type')),
            'grade_level' => $grade !== '' ? (int) $grade : null, 'first_appointment_date' => $this->date($v('first_appointment_date')), 'institutional_email' => $v('institutional_email') ?: null,
            'label' => trim($v('surname') . ' ' . $v('first_name')) . ' · ' . ($number ?: 'no staff number') . ($dept ? ' · ' . $dept->code : ''),
        ];
    }

    private function createStudent(array $r): Student
    {
        $user = User::create([
            'name' => trim($r['first_name'] . ' ' . $r['last_name']), 'email' => $r['email'], 'username' => $r['matric_number'], 'phone_number' => $r['phone_number'],
            'gender' => $r['gender'], 'date_of_birth' => $r['date_of_birth'], 'password' => Str::password(16), 'must_change_password' => true,
        ]);
        $user->assignRole(Role::firstOrCreate(['name' => 'Student', 'guard_name' => 'web'], ['badge' => 'badge bg-info']));

        return Student::create(collect($r)->except(['email', 'label'])->all() + [
            'user_id' => $user->id, 'status' => \App\Models\StudentStatus::defaultCode(), 'needs_onboarding' => false,
            'entry_semester_type_id' => \App\Models\SemesterType::where('is_active', true)->orderBy('sort_order')->value('id'),
        ]);
    }

    private function createStaff(array $r): StaffProfile
    {
        $user = User::create([
            'name' => $r['name'], 'email' => $r['email'], 'username' => $r['staff_number'], 'phone_number' => $r['phone_number'], 'gender' => $r['gender'],
            'date_of_birth' => $r['date_of_birth'], 'password' => Str::password(16), 'must_change_password' => true,
        ]);

        return StaffProfile::create(collect($r)->only(['title', 'staff_number', 'file_number', 'ippis_number', 'department_id', 'designation', 'category', 'appointment_type', 'grade_level', 'first_appointment_date', 'institutional_email'])->all()
            + ['user_id' => $user->id, 'employment_status' => 'active']);
    }

    private function date(string $raw): ?string
    {
        if ($raw === '') {
            return null;
        }
        if (is_numeric($raw) && (float) $raw > 10000) { // a spreadsheet date number
            return \Carbon\Carbon::create(1899, 12, 30)->addDays((int) $raw)->toDateString();
        }
        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'j/n/Y', 'd M Y', 'j M Y'] as $f) {
            $d = \DateTime::createFromFormat('!' . $f, $raw);
            if ($d && $d->format($f) === $raw) {
                return $d->format('Y-m-d');
            }
        }

        return null;
    }

    private function gender(string $raw): ?string
    {
        $k = strtolower(trim($raw));

        return match (true) {
            in_array($k, ['m', 'male'], true) => 'male',
            in_array($k, ['f', 'female'], true) => 'female',
            $k === '' => null,
            default => 'other',
        };
    }

    /** A code from an editable list, matched by code or label; null when it is not on the list. */
    private function listCode(string $list, string $raw): ?string
    {
        if ($raw === '') {
            return null;
        }
        $opts = Lookups::options($list);
        foreach ($opts as $code => $label) {
            if (strcasecmp((string) $code, $raw) === 0 || strcasecmp($label, $raw) === 0) {
                return (string) $code;
            }
        }

        return null;
    }

    private static function norm(string $s): string
    {
        return preg_replace('/[^a-z0-9]/', '', strtolower($s));
    }
}
