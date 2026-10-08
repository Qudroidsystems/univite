<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Starter reference data. Everything here is copied into editable database tables on install,
 * so each university can rename, add or retire entries without touching code.
 */
class ReferenceData
{
    /** Editable lists: key => [title, description, [code => label]]. */
    public static function lists(): array
    {
        $states = ['Abia', 'Adamawa', 'Akwa Ibom', 'Anambra', 'Bauchi', 'Bayelsa', 'Benue', 'Borno', 'Cross River', 'Delta',
            'Ebonyi', 'Edo', 'Ekiti', 'Enugu', 'FCT Abuja', 'Gombe', 'Imo', 'Jigawa', 'Kaduna', 'Kano', 'Katsina', 'Kebbi',
            'Kogi', 'Kwara', 'Lagos', 'Nasarawa', 'Niger', 'Ogun', 'Ondo', 'Osun', 'Oyo', 'Plateau', 'Rivers', 'Sokoto',
            'Taraba', 'Yobe', 'Zamfara'];

        return [
            'title' => ['Titles', 'Honorifics used for staff and students.', ['mr' => 'Mr', 'mrs' => 'Mrs', 'miss' => 'Miss', 'ms' => 'Ms', 'dr' => 'Dr', 'prof' => 'Prof', 'engr' => 'Engr']],
            'gender' => ['Gender', 'Options shown wherever a person\'s gender is recorded.', ['male' => 'Male', 'female' => 'Female', 'other' => 'Other']],
            'marital_status' => ['Marital status', 'Options for marital status.', ['single' => 'Single', 'married' => 'Married', 'divorced' => 'Divorced', 'separated' => 'Separated', 'widowed' => 'Widowed']],
            'religion' => ['Religion', 'Options for religion.', ['christianity' => 'Christianity', 'islam' => 'Islam', 'traditional' => 'Traditional', 'none' => 'None', 'others' => 'Others']],
            'blood_group' => ['Blood groups', 'Options for blood group.', ['A+' => 'A+', 'A-' => 'A-', 'AB+' => 'AB+', 'AB-' => 'AB-', 'B+' => 'B+', 'B-' => 'B-', 'O+' => 'O+', 'O-' => 'O-']],
            'genotype' => ['Genotypes', 'Options for genotype.', ['AA' => 'AA', 'AC' => 'AC', 'AS' => 'AS', 'SS' => 'SS']],
            'relationship' => ['Next-of-kin relationships', 'How a next of kin or guardian is related to a person.', ['father' => 'Father', 'mother' => 'Mother', 'guardian' => 'Guardian', 'brother' => 'Brother', 'sister' => 'Sister', 'spouse' => 'Spouse', 'uncle' => 'Uncle', 'aunt' => 'Aunt', 'other' => 'Other']],
            'course_status' => ['Course status in a curriculum', 'Whether a course is compulsory or optional for a programme. Elective courses are not counted towards the minimum credit load.', ['core' => 'Core', 'required' => 'Required', 'elective' => 'Elective']],
            'state' => ['States of origin', 'States offered as "state of origin". The code is the text stored on the record.', ['Not applicable (non-Nigerian)' => 'Not applicable (non-Nigerian)'] + array_combine($states, $states)],
        ];
    }

    public static function semesterTypes(): array
    {
        return [[1, 'First Semester', true], [2, 'Second Semester', true]];
    }

    /** [code, name, badge colour, can_login, can_register, can_pay, is_enrolled, is_default] */
    public static function studentStatuses(): array
    {
        return [
            ['active', 'Active', 'success', true, true, true, true, true],
            ['deferred', 'Deferred', 'info', true, false, true, false, false],
            ['suspended', 'Suspended', 'warning', false, false, false, false, false],
            ['withdrawn', 'Withdrawn', 'secondary', false, false, false, false, false],
            ['graduated', 'Graduated', 'primary', true, false, false, false, false],
        ];
    }

    /** [code, name, entry level, fixed level?, uses UTME score?, needs NCE/ND result?, open online?] */
    public static function entryModes(): array
    {
        return [
            ['utme', 'UTME', 100, true, true, false, true],
            ['direct_entry', 'Direct Entry', 200, false, false, true, true],
            ['transfer', 'Transfer', 200, false, false, false, false],
            ['postgraduate', 'Postgraduate', 100, false, false, false, false],
        ];
    }

    /** Idempotent: only adds what is missing, never overwrites an administrator's edits. */
    public static function seed(): void
    {
        $now = now();
        $order = 0;
        foreach (self::lists() as $list => [, , $items]) {
            $i = 0;
            foreach ($items as $code => $label) {
                DB::table('lookups')->insertOrIgnore(['list' => $list, 'code' => (string) $code, 'label' => $label, 'sort_order' => ++$i, 'is_active' => true, 'is_system' => $list === 'course_status', 'created_at' => $now, 'updated_at' => $now]);
            }
            $order++;
        }

        foreach (self::semesterTypes() as [$sort, $name, $teaching]) {
            if (! DB::table('semester_types')->where('sort_order', $sort)->exists()) {
                DB::table('semester_types')->insert(['sort_order' => $sort, 'name' => $name, 'is_teaching' => $teaching, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
            }
        }

        $i = 0;
        foreach (self::studentStatuses() as [$code, $name, $badge, $login, $register, $pay, $enrolled, $default]) {
            DB::table('student_statuses')->insertOrIgnore(['code' => $code, 'name' => $name, 'badge' => $badge, 'can_login' => $login, 'can_register' => $register, 'can_pay' => $pay, 'is_enrolled' => $enrolled, 'is_default' => $default, 'sort_order' => ++$i, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
        }

        $i = 0;
        foreach (self::entryModes() as [$code, $name, $level, $fixed, $utme, $prior, $online]) {
            DB::table('entry_modes')->insertOrIgnore(['code' => $code, 'name' => $name, 'entry_level' => $level, 'fixed_level' => $fixed, 'uses_utme' => $utme, 'needs_prior_result' => $prior, 'open_online' => $online, 'sort_order' => ++$i, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
        }
    }
}
