<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A student's query about one published result, and the answer.
        Schema::create('result_complaints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignId('course_result_id')->constrained('course_results')->cascadeOnDelete();
            $table->text('reason');
            $table->string('status', 20)->default('open');     // open | upheld | rejected
            $table->text('response')->nullable();
            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('handled_at')->nullable();
            $table->timestamps();
        });

        // Units a student must pass to graduate from a programme (blank = not checked).
        Schema::table('programmes', function (Blueprint $table) {
            $table->unsignedSmallInteger('graduation_units')->nullable()->after('max_credit_load');
        });

        Schema::table('students', function (Blueprint $table) {
            $table->timestamp('graduated_at')->nullable();
            $table->string('degree_class')->nullable();
            $table->decimal('final_cgpa', 4, 2)->nullable();
        });

        // One graduation list per programme and session: checked, then approved (which graduates the students on it).
        Schema::create('graduation_lists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('programme_id')->constrained('programmes')->cascadeOnDelete();
            $table->foreignId('academic_session_id')->constrained('academic_sessions')->cascadeOnDelete();
            $table->string('status', 20)->default('draft');    // draft | approved
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('checked_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->unique(['programme_id', 'academic_session_id'], 'graduation_list_unique');
        });

        Schema::create('graduation_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('graduation_list_id')->constrained('graduation_lists')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->decimal('cgpa', 4, 2)->default(0);
            $table->unsignedSmallInteger('units_passed')->default(0);
            $table->string('degree_class')->nullable();
            $table->boolean('is_eligible')->default(false);
            $table->json('problems')->nullable();             // why the student cannot graduate yet
            $table->boolean('is_excluded')->default(false);    // held back by staff
            $table->string('exclusion_note')->nullable();
            $table->timestamps();

            $table->unique(['graduation_list_id', 'student_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('graduation_entries');
        Schema::dropIfExists('graduation_lists');
        Schema::table('students', fn (Blueprint $t) => $t->dropColumn(['graduated_at', 'degree_class', 'final_cgpa']));
        Schema::table('programmes', fn (Blueprint $t) => $t->dropColumn('graduation_units'));
        Schema::dropIfExists('result_complaints');
    }
};
