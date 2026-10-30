<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Student and staff details from the old portal: NIN, religion, blood group, genotype, marital status, next of kin
 * email and signature; the JAMB number kept apart from the matric number; course option, sub-department and the
 * semesters a student joined and is in; referees, transfers between programmes, deferred admissions and imports.
 * Staff get their file, IPPIS, ID card and payslip numbers, an institutional email and a signature.
 */
return new class extends Migration
{
    private const PERSONAL = ['nin', 'religion', 'blood_group', 'genotype', 'marital_status', 'next_of_kin_email'];

    public function up(): void
    {
        foreach (['applicants', 'students'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->string('nin', 20)->nullable();
                $t->string('religion', 50)->nullable();
                $t->string('blood_group', 5)->nullable();
                $t->string('genotype', 5)->nullable();
                $t->string('marital_status', 30)->nullable();
                $t->string('next_of_kin_email', 150)->nullable();
            });
        }

        Schema::table('students', function (Blueprint $t) {
            $t->string('jamb_reg_no', 30)->nullable()->index();
            $t->string('application_ref', 50)->nullable();
            $t->string('signature')->nullable();
            $t->foreignId('course_option_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('sub_department_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('entry_semester_type_id')->nullable()->constrained('semester_types')->nullOnDelete();
            $t->foreignId('current_semester_type_id')->nullable()->constrained('semester_types')->nullOnDelete();
        });

        Schema::create('student_referees', function (Blueprint $t) {
            $t->id();
            $t->foreignId('student_id')->constrained()->cascadeOnDelete();
            $t->unsignedTinyInteger('position')->default(1);
            $t->string('name', 150);
            $t->string('address', 500)->nullable();
            $t->string('phone', 30)->nullable();
            $t->string('email', 150)->nullable();
            $t->string('occupation', 150)->nullable();
            $t->timestamps();
            $t->unique(['student_id', 'position']);
        });

        Schema::create('student_transfers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('student_id')->constrained()->cascadeOnDelete();
            $t->foreignId('from_programme_id')->constrained('programmes');
            $t->foreignId('to_programme_id')->constrained('programmes');
            $t->foreignId('from_course_option_id')->nullable()->constrained('course_options')->nullOnDelete();
            $t->foreignId('to_course_option_id')->nullable()->constrained('course_options')->nullOnDelete();
            $t->unsignedSmallInteger('from_level');
            $t->unsignedSmallInteger('to_level');
            $t->foreignId('academic_session_id')->nullable()->constrained()->nullOnDelete();
            $t->string('reason', 1000);
            $t->foreignId('transferred_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });

        Schema::create('admission_deferrals', function (Blueprint $t) {
            $t->id();
            $t->foreignId('student_id')->constrained()->cascadeOnDelete();
            $t->foreignId('from_session_id')->constrained('academic_sessions');
            $t->foreignId('to_session_id')->constrained('academic_sessions');
            $t->string('reason', 1000);
            $t->string('previous_status', 30)->nullable();
            $t->string('status', 20)->default('deferred'); // deferred | resumed | cancelled
            $t->foreignId('deferred_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('resumed_at')->nullable();
            $t->foreignId('resumed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });

        // Students and staff added from a spreadsheet (e.g. exported from the old portal), checked first and undoable.
        Schema::create('people_imports', function (Blueprint $t) {
            $t->id();
            $t->string('kind', 20); // students | staff
            $t->string('file_name');
            $t->string('path');
            $t->unsignedInteger('rows')->default(0);
            $t->string('status', 20)->default('uploaded'); // uploaded | checked | imported | undone
            $t->json('report')->nullable();
            $t->json('created_ids')->nullable();
            $t->unsignedInteger('imported')->default(0);
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('imported_at')->nullable();
            $t->timestamp('undone_at')->nullable();
            $t->timestamps();
        });

        Schema::table('staff_profiles', function (Blueprint $t) {
            $t->string('file_number', 50)->nullable();
            $t->string('ippis_number', 30)->nullable();
            $t->string('id_card_number', 30)->nullable();
            $t->string('payslip_number', 30)->nullable();
            $t->string('institutional_email', 150)->nullable();
            $t->string('signature')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('staff_profiles', fn (Blueprint $t) => $t->dropColumn(['file_number', 'ippis_number', 'id_card_number', 'payslip_number', 'institutional_email', 'signature']));
        Schema::dropIfExists('people_imports');
        Schema::dropIfExists('admission_deferrals');
        Schema::dropIfExists('student_transfers');
        Schema::dropIfExists('student_referees');
        Schema::table('students', function (Blueprint $t) {
            foreach (['current_semester_type_id', 'entry_semester_type_id', 'sub_department_id', 'course_option_id'] as $fk) {
                $t->dropConstrainedForeignId($fk);
            }
            $t->dropColumn(['jamb_reg_no', 'application_ref', 'signature']);
        });
        foreach (['applicants', 'students'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->dropColumn(self::PERSONAL));
        }
    }
};
