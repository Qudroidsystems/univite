<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One generation of a timetable (a candidate): how it was made, how good it is, and the placement itself.
        Schema::create('timetable_runs', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();                  // RUN-2026-0001-A
            $table->foreignId('timetable_id')->constrained()->cascadeOnDelete();
            $table->string('batch', 20)->nullable();                // candidates generated together share a batch
            $table->string('label', 60)->nullable();                // Candidate A
            $table->string('algorithm', 20);
            $table->unsignedInteger('seed')->default(1);
            $table->json('scope')->nullable();                      // partial regeneration: what was re-placed
            $table->json('config');                                 // the timetable's rules and course requirements when it ran
            $table->string('status', 12)->default('queued');        // queued | running | done | failed | applied
            $table->longText('assignment')->nullable();             // unit => {slots, venues}
            $table->json('summary')->nullable();                    // placed, unplaced, violations, score
            $table->json('stats')->nullable();                      // analytics
            $table->json('unplaced')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->text('error')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->foreignId('applied_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['timetable_id', 'status']);
        });

        // Saved wizard configurations, reusable in later semesters.
        Schema::create('timetable_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('description', 500)->nullable();
            $table->json('settings');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('timetable_templates');
        Schema::dropIfExists('timetable_runs');
    }
};
