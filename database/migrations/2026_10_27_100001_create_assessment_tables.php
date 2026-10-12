<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dynamic assessments. A scheme is a named list of assessment items (Test 1 /10, Assignment /10, Exam /70 ...),
 * each counting towards CA or exam, adding up to 100. A scheme is assigned to the university, a faculty, a department
 * or one course, for every session or one session; the most specific assignment wins. A score sheet keeps the scheme
 * it started with, and lecturers enter a mark per item; the items add up to the sheet's CA and exam.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assessment_schemes', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('description', 500)->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('assessment_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assessment_scheme_id')->constrained('assessment_schemes')->cascadeOnDelete();
            $table->string('name', 80);                        // Test 1
            $table->string('code', 20);                        // T1 (column heading in uploads)
            $table->string('component', 10)->default('ca');    // ca | exam
            $table->decimal('max_score', 5, 2);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['assessment_scheme_id', 'code']);
        });

        // Which scheme a scope uses. scope_type: university | faculty | department | course (scope_id null for university).
        Schema::create('assessment_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assessment_scheme_id')->constrained('assessment_schemes')->cascadeOnDelete();
            $table->string('scope_type', 20);
            $table->unsignedBigInteger('scope_id')->nullable();
            $table->foreignId('academic_session_id')->nullable()->constrained('academic_sessions')->cascadeOnDelete();   // null = every session
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['scope_type', 'scope_id', 'academic_session_id'], 'assessment_assignment_scope');
        });

        Schema::table('result_sheets', function (Blueprint $table) {
            $table->foreignId('assessment_scheme_id')->nullable()->after('semester_type_id')->constrained('assessment_schemes')->restrictOnDelete();
        });

        // One mark per student per assessment item. The items of a component add up to course_results.ca / exam.
        Schema::create('assessment_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_result_id')->constrained('course_results')->cascadeOnDelete();
            $table->foreignId('assessment_item_id')->constrained('assessment_items')->restrictOnDelete();
            $table->decimal('score', 5, 2);
            $table->foreignId('entered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['course_result_id', 'assessment_item_id'], 'assessment_score_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assessment_scores');
        Schema::table('result_sheets', fn (Blueprint $t) => $t->dropConstrainedForeignId('assessment_scheme_id'));
        foreach (['assessment_assignments', 'assessment_items', 'assessment_schemes'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
