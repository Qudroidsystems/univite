<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A curriculum entry can be a project or teaching practice, and can be for one course option only.
        Schema::table('programme_courses', function (Blueprint $table) {
            $table->string('kind', 20)->default('normal')->after('status');   // normal | project | teaching_practice
            $table->foreignId('course_option_id')->nullable()->after('kind')->constrained('course_options')->nullOnDelete();
        });

        // A newer course that replaces an older one: passing the new one clears a failed old one.
        Schema::create('course_replacements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->foreignId('replaces_course_id')->constrained('courses')->cascadeOnDelete();
            $table->string('note')->nullable();
            $table->timestamps();
            $table->unique(['course_id', 'replaces_course_id']);
        });

        // Who signs printed results, per faculty, department, category or programme, e.g. "Dr. J. Edafe, Head of Department".
        Schema::create('result_signatories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('faculty_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('department_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('study_category_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('programme_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('position', 120);       // Head of Department, Dean, Exams Officer
            $table->string('title', 40)->nullable(); // Dr., Prof.
            $table->string('name', 150)->nullable();
            $table->string('address')->nullable();   // e.g. "Department of Economics"
            $table->unsignedSmallInteger('sort_order')->default(1);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('result_signatories');
        Schema::dropIfExists('course_replacements');
        Schema::table('programme_courses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('course_option_id');
            $table->dropColumn('kind');
        });
    }
};
