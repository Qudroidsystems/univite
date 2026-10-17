<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Courses are compulsory, compulsory elective or elective. The old curriculum statuses map across: core and required
 * become compulsory, elective stays elective. Each course also gets its usual type, taken from the curricula it is on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->string('course_type', 20)->default('compulsory')->after('credit_units');
        });
        Schema::table('programme_courses', function (Blueprint $table) {
            $table->string('status', 20)->default('compulsory')->change();
        });
        Schema::table('course_registrations', function (Blueprint $table) {
            $table->string('course_status', 20)->default('compulsory')->change();
        });

        DB::table('programme_courses')->whereIn('status', ['core', 'required'])->update(['status' => 'compulsory']);
        DB::table('course_registrations')->whereIn('course_status', ['core', 'required'])->update(['course_status' => 'compulsory']);

        // A course's usual type: the one most of its curriculum entries give it.
        $types = DB::table('programme_courses')->select('course_id', 'status', DB::raw('count(*) as n'))->groupBy('course_id', 'status')->get()
            ->groupBy('course_id')->map(fn ($g) => $g->sortByDesc('n')->first()->status);
        foreach ($types->groupBy(fn ($t) => $t) as $type => $courses) {
            DB::table('courses')->whereIn('id', $courses->keys()->all())->update(['course_type' => $type]);
        }

        $now = now();
        DB::table('lookups')->where('list', 'course_status')->whereIn('code', ['core', 'required'])->delete();
        foreach (['compulsory' => 'Compulsory', 'compulsory_elective' => 'Compulsory elective', 'elective' => 'Elective'] as $i => $label) {
            $sort = array_search($i, ['compulsory', 'compulsory_elective', 'elective'], true) + 1;
            if (DB::table('lookups')->where('list', 'course_status')->where('code', $i)->exists()) {
                DB::table('lookups')->where('list', 'course_status')->where('code', $i)->update(['sort_order' => $sort, 'is_system' => true]);
            } else {
                DB::table('lookups')->insert(['list' => 'course_status', 'code' => $i, 'label' => $label, 'sort_order' => $sort, 'is_active' => true, 'is_system' => true, 'created_at' => $now, 'updated_at' => $now]);
            }
        }
    }

    public function down(): void
    {
        DB::table('programme_courses')->where('status', 'compulsory')->update(['status' => 'core']);
        DB::table('programme_courses')->where('status', 'compulsory_elective')->update(['status' => 'elective']);
        DB::table('course_registrations')->where('course_status', 'compulsory')->update(['course_status' => 'core']);
        DB::table('course_registrations')->where('course_status', 'compulsory_elective')->update(['course_status' => 'elective']);
        DB::table('lookups')->where('list', 'course_status')->whereIn('code', ['compulsory', 'compulsory_elective'])->delete();
        Schema::table('courses', function (Blueprint $table) {
            $table->dropColumn('course_type');
        });
    }
};
