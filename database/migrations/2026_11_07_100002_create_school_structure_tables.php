<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * School structure from the old portal: which study categories each department runs and over which levels,
 * sub-departments, course options inside a programme, combined programmes (e.g. Social Studies/English), how a
 * programme reads on the application form, and matric number formats per faculty, department, category or entry mode.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('department_categories', function (Blueprint $t) {
            $t->id();
            $t->foreignId('department_id')->constrained()->cascadeOnDelete();
            $t->foreignId('study_category_id')->constrained()->cascadeOnDelete();
            $t->unsignedSmallInteger('lowest_level')->default(100);
            $t->unsignedSmallInteger('highest_level')->nullable();
            $t->unsignedTinyInteger('semesters_per_level')->default(2);
            $t->boolean('allow_combination')->default(false);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->unique(['department_id', 'study_category_id']);
        });

        Schema::create('sub_departments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('department_id')->constrained()->cascadeOnDelete();
            $t->foreignId('study_category_id')->nullable()->constrained()->nullOnDelete();
            $t->string('name', 150);
            $t->string('code', 20)->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->unique(['department_id', 'name']);
        });

        Schema::create('course_options', function (Blueprint $t) {
            $t->id();
            $t->foreignId('programme_id')->constrained()->cascadeOnDelete();
            $t->string('name', 150);
            $t->string('code', 20)->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->unique(['programme_id', 'name']);
        });

        Schema::table('programmes', function (Blueprint $t) {
            $t->foreignId('sub_department_id')->nullable()->after('department_id')->constrained()->nullOnDelete();
            $t->boolean('is_combination')->default(false)->after('degree_type');
            $t->foreignId('second_department_id')->nullable()->after('is_combination')->constrained('departments')->nullOnDelete();
            $t->boolean('show_on_application')->default(true)->after('is_active');
            $t->string('application_name', 150)->nullable()->after('show_on_application');
        });

        Schema::create('matric_formats', function (Blueprint $t) {
            $t->id();
            $t->string('name', 120);
            $t->foreignId('faculty_id')->nullable()->constrained()->cascadeOnDelete();
            $t->foreignId('department_id')->nullable()->constrained()->cascadeOnDelete();
            $t->foreignId('study_category_id')->nullable()->constrained()->cascadeOnDelete();
            $t->foreignId('programme_id')->nullable()->constrained()->cascadeOnDelete();
            $t->string('entry_mode', 30)->nullable();
            $t->string('pattern', 60);
            $t->string('prefix', 20)->nullable();
            $t->string('suffix', 20)->nullable();
            $t->unsignedInteger('serial_from')->default(1);
            $t->unsignedInteger('serial_to')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('matric_formats');
        Schema::table('programmes', function (Blueprint $t) {
            $t->dropConstrainedForeignId('second_department_id');
            $t->dropConstrainedForeignId('sub_department_id');
            $t->dropColumn(['is_combination', 'show_on_application', 'application_name']);
        });
        Schema::dropIfExists('course_options');
        Schema::dropIfExists('sub_departments');
        Schema::dropIfExists('department_categories');
    }
};
