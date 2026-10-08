<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bills', function (Blueprint $table) {
            // MySQL needs an index on each foreign key before the old composite unique can go.
            $table->index('academic_session_id', 'bills_session_idx');
            $table->index('programme_id', 'bills_programme_idx');
            $table->index('bill_item_id', 'bills_item_idx');
        });

        Schema::table('bills', function (Blueprint $table) {
            $table->dropUnique('bills_unique_charge');
        });

        Schema::table('bills', function (Blueprint $table) {
            // A blank target means "everyone": programme and level are no longer required.
            $table->unsignedBigInteger('programme_id')->nullable()->change();
            $table->unsignedSmallInteger('level')->nullable()->change();

            $table->foreignId('faculty_id')->nullable()->after('academic_session_id')->constrained('faculties')->cascadeOnDelete();
            $table->foreignId('department_id')->nullable()->after('faculty_id')->constrained('departments')->cascadeOnDelete();
            $table->foreignId('study_category_id')->nullable()->after('department_id')->constrained('study_categories')->cascadeOnDelete();
            $table->foreignId('semester_type_id')->nullable()->after('level')->constrained('semester_types')->nullOnDelete(); // null = billed for the whole session
            $table->string('nationality', 10)->default('any');            // any | home | foreign
            $table->unsignedTinyInteger('installments')->default(1);      // 1 to 3
            $table->json('installment_percents')->nullable();             // e.g. [60, 40]
            $table->dateTime('opens_at')->nullable();                     // payment window
            $table->dateTime('closes_at')->nullable();
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->foreignId('semester_type_id')->nullable()->after('academic_session_id')->constrained('semester_types')->nullOnDelete();
            $table->json('installment_plan')->nullable();                 // amounts per installment, in order
            $table->dateTime('opens_at')->nullable();
            $table->dateTime('closes_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('semester_type_id');
            $table->dropColumn(['installment_plan', 'opens_at', 'closes_at']);
        });
        Schema::table('bills', function (Blueprint $table) {
            $table->dropConstrainedForeignId('faculty_id');
            $table->dropConstrainedForeignId('department_id');
            $table->dropConstrainedForeignId('study_category_id');
            $table->dropConstrainedForeignId('semester_type_id');
            $table->dropColumn(['nationality', 'installments', 'installment_percents', 'opens_at', 'closes_at']);
        });
    }
};
