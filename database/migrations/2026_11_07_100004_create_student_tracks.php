<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where each student is, semester by semester (the old portal's "Migrations"): one active track per student, closed
 * when the class moves on. A move of a class (or one student) to the next semester or level is one run that can be undone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('track_migrations', function (Blueprint $t) {
            $t->id();
            $t->string('key', 40)->unique();
            $t->foreignId('from_session_id')->nullable()->constrained('academic_sessions')->nullOnDelete();
            $t->foreignId('from_semester_type_id')->nullable()->constrained('semester_types')->nullOnDelete();
            $t->foreignId('to_session_id')->constrained('academic_sessions');
            $t->foreignId('to_semester_type_id')->constrained('semester_types');
            $t->json('scope')->nullable();
            $t->unsignedInteger('moved')->default(0);
            $t->unsignedInteger('held')->default(0);
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('undone_at')->nullable();
            $t->foreignId('undone_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });

        Schema::create('student_tracks', function (Blueprint $t) {
            $t->id();
            $t->foreignId('student_id')->constrained()->cascadeOnDelete();
            $t->foreignId('track_migration_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('closed_by_migration_id')->nullable()->constrained('track_migrations')->nullOnDelete();
            $t->foreignId('academic_session_id')->constrained();
            $t->foreignId('semester_type_id')->constrained();
            $t->unsignedSmallInteger('level');
            $t->foreignId('programme_id')->constrained();
            $t->foreignId('course_option_id')->nullable()->constrained()->nullOnDelete();
            $t->string('status', 10)->default('active'); // active | closed
            $t->string('semester_remark', 20)->nullable();
            $t->string('cumulative_remark', 40)->nullable();
            $t->string('result_action', 20)->nullable();
            $t->foreignId('computed_result_id')->nullable()->constrained()->nullOnDelete();
            $t->unsignedSmallInteger('previous_level')->nullable();
            $t->foreignId('previous_semester_type_id')->nullable()->constrained('semester_types')->nullOnDelete();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['student_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_tracks');
        Schema::dropIfExists('track_migrations');
    }
};
