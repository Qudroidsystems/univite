<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A student's computed semester result: what the Head of Department's "Compute" produced from the submitted scores,
 * the key to grade and the key to result. The department master sheet is printed from these rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        // A MySQL run that stopped part-way (index names over 64 characters) left the table behind without recording
        // the migration, so start clean.
        Schema::dropIfExists('computed_results');

        Schema::create('computed_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('academic_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('semester_type_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('level');
            $table->foreignId('department_id')->constrained()->cascadeOnDelete();
            $table->foreignId('programme_id')->constrained()->cascadeOnDelete();
            $table->foreignId('study_category_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('present_tcp', 8, 2)->default(0);
            $table->unsignedSmallInteger('present_tnu')->default(0);
            $table->decimal('present_gpa', 4, 2)->default(0);
            $table->decimal('previous_tcp', 8, 2)->default(0);
            $table->unsignedSmallInteger('previous_tnu')->default(0);
            $table->decimal('previous_gpa', 4, 2)->default(0);
            $table->decimal('cumulative_tcp', 8, 2)->default(0);
            $table->unsignedSmallInteger('cumulative_tnu')->default(0);
            $table->decimal('cgpa', 4, 2)->default(0);
            $table->json('outstanding')->nullable();               // [{code, units, reason}]
            $table->unsignedSmallInteger('units_outstanding')->default(0);
            $table->unsignedSmallInteger('missing')->default(0);   // registered courses with no submitted score
            $table->unsignedSmallInteger('semesters_used')->default(0);
            $table->unsignedSmallInteger('semesters_allowed')->nullable();
            $table->string('sem_remark', 12);                      // PASS | REP | NRI | INC
            $table->string('cum_remark', 12)->nullable();          // from the key to result
            $table->foreignId('result_key_id')->nullable()->constrained()->nullOnDelete();
            $table->string('rank', 60)->nullable();
            $table->string('action', 20)->nullable();
            $table->string('activity')->nullable();
            $table->boolean('graduating')->default(false);
            $table->foreignId('computed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('computed_at')->nullable();
            $table->timestamps();
            $table->unique(['student_id', 'academic_session_id', 'semester_type_id'], 'computed_results_student_term_unique');
            $table->index(['department_id', 'academic_session_id', 'semester_type_id', 'level'], 'computed_results_department_term_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('computed_results');
    }
};
