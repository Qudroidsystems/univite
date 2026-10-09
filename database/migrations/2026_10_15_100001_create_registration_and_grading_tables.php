<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The university's grading scale: score band -> letter, grade points and whether it is a pass.
        Schema::create('grade_bands', function (Blueprint $table) {
            $table->id();
            $table->string('letter', 5);
            $table->decimal('min_score', 5, 2);
            $table->decimal('max_score', 5, 2);
            $table->decimal('points', 4, 2);
            $table->string('remark')->nullable();
            $table->boolean('is_pass')->default(true);
            $table->timestamps();
        });

        // CGPA -> class of degree / diploma.
        Schema::create('degree_classes', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->decimal('min_cgpa', 4, 2);
            $table->timestamps();
        });

        Schema::create('course_prerequisites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained('courses')->cascadeOnDelete();
            $table->foreignId('prerequisite_id')->constrained('courses')->cascadeOnDelete();
            $table->unique(['course_id', 'prerequisite_id']);
        });

        // One course-registration form per student per session and semester.
        Schema::create('registration_forms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignId('academic_session_id')->constrained('academic_sessions')->cascadeOnDelete();
            $table->foreignId('semester_type_id')->constrained('semester_types')->restrictOnDelete();
            $table->unsignedSmallInteger('level');
            $table->string('status', 20)->default('draft');         // draft | submitted | approved | rejected
            $table->unsignedSmallInteger('total_units')->default(0);
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('note', 500)->nullable();
            $table->timestamps();

            $table->unique(['student_id', 'academic_session_id', 'semester_type_id'], 'reg_form_unique');
        });

        Schema::create('course_registrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('registration_form_id')->constrained('registration_forms')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignId('course_id')->constrained('courses')->restrictOnDelete();
            $table->unsignedTinyInteger('credit_units');
            $table->boolean('is_carryover')->default(false);
            $table->string('course_status', 20)->default('core');   // core | required | elective, as in the curriculum
            $table->timestamps();

            $table->unique(['registration_form_id', 'course_id']);
        });

        // Scores. Entered and approved in the results workflow; read here for carry-overs and prerequisites.
        Schema::create('course_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignId('course_id')->constrained('courses')->restrictOnDelete();
            $table->foreignId('academic_session_id')->constrained('academic_sessions')->cascadeOnDelete();
            $table->foreignId('semester_type_id')->constrained('semester_types')->restrictOnDelete();
            $table->decimal('ca', 5, 2)->nullable();
            $table->decimal('exam', 5, 2)->nullable();
            $table->decimal('total', 5, 2)->nullable();
            $table->string('grade', 5)->nullable();
            $table->decimal('points', 4, 2)->nullable();
            $table->boolean('is_pass')->nullable();
            $table->unsignedTinyInteger('credit_units')->default(0);
            $table->string('status', 20)->default('draft');         // draft | submitted | approved | published
            $table->timestamps();

            $table->unique(['student_id', 'course_id', 'academic_session_id', 'semester_type_id'], 'result_unique');
        });

        \App\Support\ReferenceData::seed();   // the starting grading scale and classes of degree
    }

    public function down(): void
    {
        foreach (['course_results', 'course_registrations', 'registration_forms', 'course_prerequisites', 'degree_classes', 'grade_bands'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
