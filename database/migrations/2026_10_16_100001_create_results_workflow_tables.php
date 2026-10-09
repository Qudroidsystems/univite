<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Which lecturer teaches which course in a session and semester.
        Schema::create('course_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('course_id')->constrained('courses')->cascadeOnDelete();
            $table->foreignId('academic_session_id')->constrained('academic_sessions')->cascadeOnDelete();
            $table->foreignId('semester_type_id')->constrained('semester_types')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'course_id', 'academic_session_id', 'semester_type_id'], 'assignment_unique');
        });

        // The approval chain a score sheet passes through after the lecturer submits it, in order.
        Schema::create('result_stages', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('office_role')->nullable();     // spatie role that may approve this stage; blank = anyone allowed to approve results
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // One score sheet per course, session and semester.
        Schema::create('result_sheets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained('courses')->cascadeOnDelete();
            $table->foreignId('academic_session_id')->constrained('academic_sessions')->cascadeOnDelete();
            $table->foreignId('semester_type_id')->constrained('semester_types')->cascadeOnDelete();
            $table->string('status', 20)->default('draft');   // draft | submitted | approved | published
            $table->foreignId('stage_id')->nullable()->constrained('result_stages')->nullOnDelete();   // where a submitted sheet is waiting
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->unique(['course_id', 'academic_session_id', 'semester_type_id'], 'sheet_unique');
        });

        Schema::create('result_sheet_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('result_sheet_id')->constrained('result_sheets')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 30);                      // saved | imported | submitted | approved | returned | published
            $table->string('stage')->nullable();
            $table->string('note', 500)->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::table('course_results', function (Blueprint $table) {
            $table->foreignId('result_sheet_id')->nullable()->after('student_id')->constrained('result_sheets')->nullOnDelete();
        });

        $now = now();
        DB::table('result_stages')->insert([
            ['name' => 'Head of Department', 'office_role' => null, 'sort_order' => 1, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Dean of Faculty', 'office_role' => null, 'sort_order' => 2, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        Schema::table('course_results', fn (Blueprint $t) => $t->dropConstrainedForeignId('result_sheet_id'));
        foreach (['result_sheet_events', 'result_sheets', 'result_stages', 'course_assignments'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
