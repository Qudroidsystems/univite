<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Kinds of application the school runs at once, e.g. Degree (UTME), PDE, NCE Part-Time, each with its own fee and dates.
        Schema::create('application_types', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120)->unique();
            $table->foreignId('study_category_id')->nullable()->constrained()->nullOnDelete(); // only programmes of this category
            $table->decimal('fee', 14, 2)->nullable();          // null = the screening fee under Application Settings
            $table->date('opens_on')->nullable();
            $table->date('closes_on')->nullable();
            $table->boolean('needs_jamb')->default(true);
            $table->text('instructions')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::table('applicants', function (Blueprint $table) {
            $table->foreignId('application_type_id')->nullable()->after('academic_session_id')->constrained()->nullOnDelete();
            $table->text('decision_message')->nullable()->after('decided_at');
            $table->foreignId('decided_by')->nullable()->after('decision_message')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('applicants', function (Blueprint $table) {
            $table->dropConstrainedForeignId('application_type_id');
            $table->dropConstrainedForeignId('decided_by');
            $table->dropColumn('decision_message');
        });
        Schema::dropIfExists('application_types');
    }
};
