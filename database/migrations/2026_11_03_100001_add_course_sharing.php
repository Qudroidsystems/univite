<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Courses belong to the department that runs them. That department sets the course's type for its own programmes
 * (compulsory, compulsory elective or elective) and decides whether other departments may take it, and how:
 *   null                 only the owning department's programmes
 *   elective             other departments may add it as an elective
 *   compulsory_elective  other departments may add it as a compulsory elective
 *   either               other departments may add it as an elective or a compulsory elective
 *   general              a general course (GST and the like): any department may make it compulsory, CE or E
 *
 * Existing curricula stay valid: a course another department already uses is opened to others as it is used there.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->string('shared_as', 20)->nullable()->after('course_type');
        });

        $foreign = DB::table('programme_courses as pc')
            ->join('programmes as p', 'p.id', '=', 'pc.programme_id')
            ->join('courses as c', 'c.id', '=', 'pc.course_id')
            ->whereColumn('p.department_id', '!=', 'c.department_id')
            ->select('pc.course_id', 'pc.status')->get()->groupBy('course_id');

        foreach ($foreign as $courseId => $rows) {
            $types = $rows->pluck('status')->unique();
            $sharedAs = match (true) {
                $types->contains('compulsory') => 'general',
                $types->count() > 1 => 'either',
                default => $types->first() === 'compulsory_elective' ? 'compulsory_elective' : 'elective',
            };
            DB::table('courses')->where('id', $courseId)->update(['shared_as' => $sharedAs]);
        }
    }

    public function down(): void
    {
        Schema::table('courses', fn (Blueprint $t) => $t->dropColumn('shared_as'));
    }
};
