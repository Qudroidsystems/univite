<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Key to grade and key to result. A key row applies to the whole university (no school, department or category), or
 * only to one school (faculty), department and/or student category; when results are computed the most specific
 * matching set wins.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('grade_bands', function (Blueprint $table) {
            $table->foreignId('faculty_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
            $table->foreignId('department_id')->nullable()->after('faculty_id')->constrained()->cascadeOnDelete();
            $table->foreignId('study_category_id')->nullable()->after('department_id')->constrained()->cascadeOnDelete();
            $table->string('rank', 40)->nullable()->after('points');     // Distinction, Credit, Merit, Pass, Fail
        });

        Schema::create('result_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('faculty_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('department_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('study_category_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('code', 12);                  // the remark printed on the master sheet: PASS, CSO, TOS ...
            $table->decimal('min_gpa', 4, 2);
            $table->decimal('max_gpa', 4, 2);
            $table->string('rank', 60);                  // Good standing, Probation ...
            $table->string('action', 20)->default('proceed');
            $table->string('activity')->nullable();      // what the student is told to do
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('result_keys');
        Schema::table('grade_bands', function (Blueprint $table) {
            $table->dropConstrainedForeignId('study_category_id');
            $table->dropConstrainedForeignId('department_id');
            $table->dropConstrainedForeignId('faculty_id');
            $table->dropColumn('rank');
        });
    }
};
