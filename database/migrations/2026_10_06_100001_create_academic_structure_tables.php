<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Academic sessions, e.g. "2025/2026"
        Schema::create('academic_sessions', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->boolean('is_current')->default(false);
            $table->timestamps();
        });

        // Semesters belong to a session, e.g. "First Semester" of 2025/2026
        Schema::create('semesters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('academic_session_id')->constrained('academic_sessions')->cascadeOnDelete();
            $table->string('name');
            $table->unsignedTinyInteger('sort_order')->default(1);
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->boolean('is_current')->default(false);
            $table->timestamps();

            $table->unique(['academic_session_id', 'name']);
            $table->unique(['academic_session_id', 'sort_order']);
        });

        Schema::create('faculties', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('code', 20)->unique();
            $table->foreignId('dean_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('faculty_id')->constrained('faculties')->restrictOnDelete();
            $table->string('name');
            $table->string('code', 20)->unique();
            $table->foreignId('hod_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['faculty_id', 'name']);
        });

        // A degree/diploma offered by a department, e.g. "B.Sc. Computer Science"
        Schema::create('programmes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->constrained('departments')->restrictOnDelete();
            $table->string('name');
            $table->string('code', 20)->unique();
            $table->string('degree_type', 30);                      // B.Sc, B.Eng, M.Sc, PhD ...
            $table->unsignedTinyInteger('duration_years')->default(4);
            $table->unsignedTinyInteger('min_credit_load')->default(15);   // per semester
            $table->unsignedTinyInteger('max_credit_load')->default(24);   // per semester
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['department_id', 'name']);
        });

        Schema::create('courses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->constrained('departments')->restrictOnDelete();
            $table->string('code', 20)->unique();                   // CSC 101
            $table->string('title');
            $table->unsignedTinyInteger('credit_units');
            $table->unsignedSmallInteger('level')->nullable();      // default level: 100, 200 ...
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Curriculum: the courses a programme runs, per level and semester
        Schema::create('programme_courses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('programme_id')->constrained('programmes')->cascadeOnDelete();
            $table->foreignId('course_id')->constrained('courses')->restrictOnDelete();
            $table->unsignedSmallInteger('level');                  // 100, 200 ...
            $table->unsignedTinyInteger('semester_order');          // 1 = first, 2 = second
            $table->string('status', 20)->default('core');          // core | elective | required
            $table->timestamps();

            $table->unique(['programme_id', 'course_id']);
            $table->index(['programme_id', 'level', 'semester_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('programme_courses');
        Schema::dropIfExists('courses');
        Schema::dropIfExists('programmes');
        Schema::dropIfExists('departments');
        Schema::dropIfExists('faculties');
        Schema::dropIfExists('semesters');
        Schema::dropIfExists('academic_sessions');
    }
};
