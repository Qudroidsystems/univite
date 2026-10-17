<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Settings a session (or one semester of it) sets for itself instead of the university's default, such as
        // whether course registration is open or which fee check applies. A value missing from `values` is inherited.
        Schema::create('period_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('academic_session_id')->constrained('academic_sessions')->cascadeOnDelete();
            $table->foreignId('semester_type_id')->nullable()->constrained('semester_types')->cascadeOnDelete();
            $table->string('group', 30)->default('registration');
            $table->json('values');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['academic_session_id', 'semester_type_id', 'group'], 'period_setting_scope');
        });

        // A saved copy of part of the setup (registration settings, windows, bills, lecturers, academic rules,
        // grading keys, approval steps) that can be restored later. Taken by hand, or automatically before a copy or
        // a restore changes anything.
        Schema::create('setup_snapshots', function (Blueprint $table) {
            $table->id();
            $table->string('label', 150);
            $table->string('kind', 20)->default('manual');   // manual | auto
            $table->foreignId('academic_session_id')->nullable()->constrained('academic_sessions')->nullOnDelete();
            $table->json('sections');
            $table->longText('payload');
            $table->json('summary')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['academic_session_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('setup_snapshots');
        Schema::dropIfExists('period_settings');
    }
};
