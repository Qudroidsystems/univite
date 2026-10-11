<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lecture and examination timetables (versioned: a revision is a new version, never an edit of a published one),
 * what has to be scheduled (items), where and when it was placed (entries), invigilation duties, times staff are not
 * available, and examination seating plans with one row per candidate seat.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('timetables', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 10);                                   // lecture | exam
            $table->string('name');
            $table->foreignId('academic_session_id')->constrained('academic_sessions')->restrictOnDelete();
            $table->foreignId('semester_type_id')->constrained('semester_types')->restrictOnDelete();
            $table->unsignedSmallInteger('version')->default(1);
            $table->foreignId('previous_id')->nullable()->constrained('timetables')->nullOnDelete();
            $table->string('status', 12)->default('draft');               // draft | review | approved | published | superseded
            $table->json('config');
            $table->json('committee')->nullable();                        // user ids who prepare it
            $table->json('summary')->nullable();                          // last generation / check result
            $table->timestamp('generated_at')->nullable();
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('decision_note', 500)->nullable();
            $table->timestamp('published_at')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['kind', 'academic_session_id', 'semester_type_id', 'status'], 'timetable_lookup');
        });

        Schema::create('timetable_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('timetable_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->string('activity', 12)->default('lecture');           // lecture | tutorial | practical | exam
            $table->string('group_label', 40)->nullable();                // "Group A" when a large class is split
            $table->unsignedInteger('students')->default(0);              // expected candidates / class size
            $table->unsignedTinyInteger('meetings')->default(1);          // per week (always 1 for an exam)
            $table->unsignedSmallInteger('duration');                     // lecture: periods per meeting; exam: minutes
            $table->string('venue_type', 40)->nullable();
            $table->json('facilities')->nullable();
            $table->json('staff')->nullable();                            // lecturer user ids
            $table->foreignId('preferred_venue_id')->nullable()->constrained('venues')->nullOnDelete();
            $table->json('issues')->nullable();                           // why it could not be placed, with suggestions
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('timetable_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('timetable_id')->constrained()->cascadeOnDelete();
            $table->foreignId('timetable_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->string('unit_key', 40);                               // one placement; an exam split over halls has several rows
            $table->unsignedTinyInteger('day')->nullable();               // 1 = Monday (lectures)
            $table->date('date')->nullable();                             // examinations
            $table->time('starts_at');
            $table->time('ends_at');
            $table->json('slots');                                        // slot keys it occupies
            $table->foreignId('venue_id')->nullable()->constrained('venues')->nullOnDelete();
            $table->unsignedInteger('seats')->default(0);
            $table->boolean('locked')->default(false);                    // placed by hand; regeneration keeps it
            $table->timestamps();
            $table->index(['timetable_id', 'unit_key']);
        });

        Schema::create('timetable_invigilators', function (Blueprint $table) {
            $table->id();
            $table->foreignId('timetable_id')->constrained()->cascadeOnDelete();
            $table->string('slot', 40);
            $table->date('date');
            $table->time('starts_at');
            $table->time('ends_at');
            $table->foreignId('venue_id')->constrained('venues')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 12)->default('assistant');             // chief | assistant
            $table->timestamps();
            $table->unique(['timetable_id', 'slot', 'user_id'], 'invigilator_once_per_sitting');
        });

        Schema::create('staff_unavailability', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('day')->nullable();               // weekly: 1 = Monday
            $table->date('date_from')->nullable();                        // or a date range
            $table->date('date_to')->nullable();
            $table->time('starts_at')->nullable();                        // null = the whole day
            $table->time('ends_at')->nullable();
            $table->string('reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('seating_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('timetable_id')->constrained()->cascadeOnDelete();
            $table->string('status', 12)->default('draft');               // draft | approved | published | superseded
            $table->json('rules');
            $table->json('summary')->nullable();
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('generated_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });

        Schema::create('exam_seats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seating_plan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('timetable_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('venue_id')->constrained('venues')->cascadeOnDelete();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->string('slot', 40);
            $table->date('date');
            $table->time('starts_at');
            $table->time('ends_at');
            $table->string('seat_label', 40);
            $table->unsignedSmallInteger('seat_row');
            $table->unsignedSmallInteger('seat_col');
            $table->unsignedInteger('seat_no');
            $table->string('code', 16)->unique();                         // verification code on the slip
            $table->string('attendance', 10)->nullable();                 // present | absent
            $table->timestamp('checked_at')->nullable();
            $table->foreignId('checked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('moved_from', 40)->nullable();
            $table->timestamps();
            $table->unique(['seating_plan_id', 'slot', 'venue_id', 'seat_label'], 'one_candidate_per_seat');
            $table->unique(['seating_plan_id', 'student_id', 'course_id'], 'one_seat_per_paper');
        });

        Schema::table('students', function (Blueprint $table) {
            $table->boolean('needs_accessible_seat')->default(false)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('students', fn (Blueprint $t) => $t->dropColumn('needs_accessible_seat'));
        foreach (['exam_seats', 'seating_plans', 'staff_unavailability', 'timetable_invigilators', 'timetable_entries', 'timetable_items', 'timetables'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
