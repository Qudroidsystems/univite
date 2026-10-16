<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Courses record the study category they belong to (Degree, NCE, ...), the semester they are taught in and the session
 * they were introduced; all three are optional so existing courses keep working. Assessment schemes record the CA and
 * exam totals the admin chose (for example 40 and 60); the assessments of each part must add up to them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->foreignId('study_category_id')->nullable()->after('department_id')->constrained('study_categories')->nullOnDelete();
            $table->foreignId('semester_type_id')->nullable()->after('level')->constrained('semester_types')->nullOnDelete();
            $table->foreignId('academic_session_id')->nullable()->after('semester_type_id')->constrained('academic_sessions')->nullOnDelete();
        });

        Schema::table('assessment_schemes', function (Blueprint $table) {
            $table->decimal('ca_total', 5, 2)->nullable()->after('description');
            $table->decimal('exam_total', 5, 2)->nullable()->after('ca_total');
        });
    }

    public function down(): void
    {
        Schema::table('assessment_schemes', fn (Blueprint $t) => $t->dropColumn(['ca_total', 'exam_total']));
        Schema::table('courses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('academic_session_id');
            $table->dropConstrainedForeignId('semester_type_id');
            $table->dropConstrainedForeignId('study_category_id');
        });
    }
};
