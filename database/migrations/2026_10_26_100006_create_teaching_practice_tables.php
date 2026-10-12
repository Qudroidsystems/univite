<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Partner schools that take student teachers.
        Schema::create('tp_schools', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('category', 20)->default('secondary'); // primary, secondary, tertiary, other
            $t->string('address')->nullable();
            $t->string('town')->nullable();
            $t->string('lga')->nullable();
            $t->string('state')->nullable();
            $t->string('phone', 30)->nullable();
            $t->string('head_name')->nullable(); // principal / head teacher
            $t->unsignedSmallInteger('default_capacity')->default(10);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        // One teaching practice exercise, e.g. "2026/2027 Teaching Practice (400 level)".
        Schema::create('tp_exercises', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->foreignId('academic_session_id')->constrained()->cascadeOnDelete();
            $t->foreignId('semester_type_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('course_id')->nullable()->constrained()->nullOnDelete(); // where scores are published
            $t->json('audience')->nullable();
            $t->date('starts_on')->nullable();
            $t->date('ends_on')->nullable();
            $t->unsignedTinyInteger('visits_required')->default(2);
            $t->json('criteria'); // [{key, label, max}]
            $t->boolean('choices_open')->default(false);
            $t->unsignedTinyInteger('max_choices')->default(3);
            $t->text('letter_text')->nullable();
            $t->timestamp('scores_published_at')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });

        Schema::create('tp_exercise_schools', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tp_exercise_id')->constrained()->cascadeOnDelete();
            $t->foreignId('tp_school_id')->constrained()->cascadeOnDelete();
            $t->unsignedSmallInteger('capacity');
            $t->timestamps();
            $t->unique(['tp_exercise_id', 'tp_school_id']);
        });

        Schema::create('tp_supervisors', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tp_exercise_id')->constrained()->cascadeOnDelete();
            $t->foreignId('tp_school_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->timestamps();
            $t->unique(['tp_exercise_id', 'tp_school_id', 'user_id']);
        });

        Schema::create('tp_postings', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tp_exercise_id')->constrained()->cascadeOnDelete();
            $t->foreignId('student_id')->constrained()->cascadeOnDelete();
            $t->foreignId('tp_school_id')->nullable()->constrained()->nullOnDelete();
            $t->string('status', 20)->default('pending'); // pending, posted, withdrawn
            $t->json('choices')->nullable(); // school ids, most wanted first
            $t->string('subject')->nullable(); // subject the student will teach
            $t->timestamp('posted_at')->nullable();
            $t->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $t->decimal('final_score', 5, 2)->nullable(); // percentage, set when scores are published
            $t->timestamps();
            $t->unique(['tp_exercise_id', 'student_id']);
            $t->index(['tp_exercise_id', 'tp_school_id']);
        });

        // A supervisor's assessment of one lesson on one visit.
        Schema::create('tp_assessments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tp_posting_id')->constrained()->cascadeOnDelete();
            $t->unsignedTinyInteger('visit_no');
            $t->foreignId('supervisor_id')->nullable()->constrained('users')->nullOnDelete();
            $t->date('visited_on');
            $t->string('lesson_topic')->nullable();
            $t->json('scores');
            $t->decimal('percent', 5, 2);
            $t->text('remarks')->nullable();
            $t->timestamps();
            $t->unique(['tp_posting_id', 'visit_no']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tp_assessments');
        Schema::dropIfExists('tp_postings');
        Schema::dropIfExists('tp_supervisors');
        Schema::dropIfExists('tp_exercise_schools');
        Schema::dropIfExists('tp_exercises');
        Schema::dropIfExists('tp_schools');
    }
};
