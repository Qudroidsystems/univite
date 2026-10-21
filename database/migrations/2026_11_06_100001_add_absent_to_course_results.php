<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A student who did not sit the course (marked Absent by the lecturer) is told apart from one who sat and scored 0.
 * How an absent student is graded is a Result Setting: "ABS" (incomplete, not in the GPA, stays outstanding) or
 * graded with the marks they have (missing marks count as 0).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('course_results', function (Blueprint $table) {
            $table->boolean('absent')->default(false)->after('total');
        });
    }

    public function down(): void
    {
        Schema::table('course_results', function (Blueprint $table) {
            $table->dropColumn('absent');
        });
    }
};
