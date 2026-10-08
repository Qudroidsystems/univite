<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applicants', function (Blueprint $table) {
            $table->string('jamb_reg_no', 20)->nullable()->unique()->after('email');
            $table->unsignedSmallInteger('jamb_score')->nullable()->after('jamb_reg_no');
            $table->string('password')->nullable();                      // chosen by applicants who apply online
            $table->timestamp('submitted_at')->nullable();
            $table->string('admission_batch', 100)->nullable();
        });

        Schema::table('students', function (Blueprint $table) {
            $table->string('admission_batch', 100)->nullable();
        });

        // O'Level / NCE / ND results typed in by the applicant
        Schema::create('applicant_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('applicant_id')->constrained()->cascadeOnDelete();
            $table->string('exam_type', 20);                              // waec | neco | nabteb | nce | nd
            $table->unsignedSmallInteger('exam_year');
            $table->string('exam_number', 30);
            $table->string('subject', 100);
            $table->string('grade', 20);
            $table->timestamps();

            $table->unique(['applicant_id', 'exam_type', 'exam_year', 'exam_number', 'subject'], 'applicant_results_unique');
        });

        // Uploaded files, kept on the private disk and only served through the portal
        Schema::create('applicant_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('applicant_id')->constrained()->cascadeOnDelete();
            $table->string('type', 30);                                   // passport | olevel | nce_nd | jamb_result | birth_certificate | state_of_origin
            $table->string('path');
            $table->string('original_name');
            $table->unsignedInteger('size');
            $table->timestamps();

            $table->unique(['applicant_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('applicant_documents');
        Schema::dropIfExists('applicant_results');
        Schema::table('students', fn (Blueprint $t) => $t->dropColumn('admission_batch'));
        Schema::table('applicants', fn (Blueprint $t) => $t->dropColumn(['jamb_reg_no', 'jamb_score', 'password', 'submitted_at', 'admission_batch']));
    }
};
