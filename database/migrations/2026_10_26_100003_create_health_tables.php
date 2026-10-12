<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One health record per student or member of staff. Clinical fields are encrypted at rest.
        Schema::create('health_records', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $t->string('blood_group', 10)->nullable();
            $t->string('genotype', 10)->nullable();
            $t->decimal('height_cm', 5, 1)->nullable();
            $t->decimal('weight_kg', 5, 1)->nullable();
            $t->text('allergies')->nullable();
            $t->text('conditions')->nullable();
            $t->text('medications')->nullable();
            $t->text('disabilities')->nullable();
            $t->string('emergency_name')->nullable();
            $t->string('emergency_phone', 30)->nullable();
            $t->string('emergency_relationship', 50)->nullable();
            $t->string('fitness', 30)->nullable(); // fit, fit_with_conditions, unfit
            $t->text('fitness_note')->nullable();
            $t->timestamp('examined_at')->nullable();
            $t->foreignId('examined_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('exam_session_id')->nullable()->constrained('academic_sessions')->nullOnDelete();
            $t->timestamp('self_updated_at')->nullable();
            $t->timestamps();
        });

        Schema::create('clinic_visits', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->timestamp('seen_at');
            $t->string('category', 30); // illness, injury, check_up, follow_up, emergency, other
            $t->text('complaint')->nullable();
            $t->text('findings')->nullable();
            $t->text('diagnosis')->nullable();
            $t->text('treatment')->nullable();
            $t->string('outcome', 30); // treated, observation, referred, admitted
            $t->string('referred_to')->nullable();
            $t->unsignedSmallInteger('rest_days')->default(0);
            $t->date('excused_from')->nullable();
            $t->date('excused_to')->nullable();
            $t->string('excuse_token', 64)->nullable()->unique();
            $t->foreignId('seen_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['user_id', 'seen_at']);
            $t->index('seen_at');
        });

        // Who opened whose health information, and why. Kept even if the record is deleted.
        Schema::create('health_access_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete(); // the person whose record it is
            $t->foreignId('viewer_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('action', 30); // viewed, updated, visit_recorded, examined
            $t->string('detail')->nullable();
            $t->string('ip', 45)->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('health_access_logs');
        Schema::dropIfExists('clinic_visits');
        Schema::dropIfExists('health_records');
    }
};
