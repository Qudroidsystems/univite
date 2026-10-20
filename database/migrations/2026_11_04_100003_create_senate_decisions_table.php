<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Senate's decision on a student or a course at the levels it sits on. Kept as history: the latest one counts.
        Schema::create('senate_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('academic_session_id')->constrained('academic_sessions')->cascadeOnDelete();
            $table->foreignId('semester_type_id')->nullable()->constrained('semester_types')->nullOnDelete();
            $table->string('subject_type', 10);                      // student | course
            $table->unsignedBigInteger('subject_id');
            $table->foreignId('programme_id')->nullable()->constrained('programmes')->nullOnDelete();
            $table->unsignedSmallInteger('level')->nullable();
            $table->string('decision', 10);                          // approved | deferred | referred
            $table->text('note')->nullable();
            $table->string('status_seen', 20)->nullable();           // eligible | not_eligible | needs_decision when decided
            $table->json('reasons')->nullable();                     // what Senate saw
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['academic_session_id', 'subject_type', 'subject_id'], 'senate_decision_subject');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('senate_decisions');
    }
};
