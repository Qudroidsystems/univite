<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // What a department (or one of its programmes) asks for in O Level results before a student may register courses.
        Schema::create('olevel_requirements', function (Blueprint $table) {
            $table->id();
            $table->string('scope_type', 20);                        // department | programme
            $table->unsignedBigInteger('scope_id');
            $table->unsignedTinyInteger('min_credits')->default(5);  // subjects at credit level (C6) or better, in all
            $table->unsignedTinyInteger('max_sittings')->default(2);
            $table->unsignedTinyInteger('optional_needed')->default(0);   // how many of the "one of" subjects
            $table->string('applies_to', 10)->default('entry');      // entry (registering at the admission level) | all
            $table->boolean('is_active')->default(true);
            $table->text('note')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['scope_type', 'scope_id']);
        });

        Schema::create('olevel_requirement_subjects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('olevel_requirement_id')->constrained()->cascadeOnDelete();
            $table->string('subject', 100);
            $table->string('min_grade', 5)->default('C6');
            $table->string('kind', 10)->default('required');        // required | optional (counts toward optional_needed)
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['olevel_requirement_id', 'subject']);
        });

        // A student's O Level sitting: what they typed, the scanned result, what the AI read from it, and the school's decision.
        Schema::create('student_olevel_sittings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->string('exam_type', 20);                         // waec | neco | nabteb | gce ...
            $table->unsignedSmallInteger('exam_year');
            $table->string('exam_number', 30);
            $table->string('centre', 150)->nullable();
            $table->string('document_path')->nullable();
            $table->string('document_name')->nullable();
            $table->string('document_mime', 80)->nullable();
            $table->string('status', 12)->default('draft');          // draft | submitted | confirmed | rejected
            $table->string('extraction_status', 10)->default('none'); // none | done | failed | off
            $table->json('extracted')->nullable();
            $table->text('extraction_note')->nullable();
            $table->timestamp('extracted_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('grade_source', 10)->nullable();         // student | extracted | mixed
            $table->text('review_note')->nullable();
            $table->timestamps();
            $table->unique(['student_id', 'exam_type', 'exam_year', 'exam_number'], 'student_olevel_sitting_unique');
        });

        Schema::create('student_olevel_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_olevel_sitting_id')->constrained()->cascadeOnDelete();
            $table->string('subject', 100);
            $table->string('grade', 5)->nullable();                  // as the student entered it
            $table->string('extracted_grade', 5)->nullable();        // as read from the uploaded result
            $table->string('confirmed_grade', 5)->nullable();        // what the school accepted
            $table->timestamps();
            $table->unique(['student_olevel_sitting_id', 'subject'], 'student_olevel_result_unique');
        });

        // Staff may let a student register while their O Level is still being sorted out (an awaited result ...).
        Schema::table('students', function (Blueprint $table) {
            $table->timestamp('olevel_override_at')->nullable();
            $table->foreignId('olevel_override_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('olevel_override_note', 500)->nullable();
        });

        \App\Support\ReferenceData::seed();   // the O Level subject list
    }

    public function down(): void
    {
        Schema::table('students', fn (Blueprint $t) => $t->dropConstrainedForeignId('olevel_override_by'));
        Schema::table('students', fn (Blueprint $t) => $t->dropColumn(['olevel_override_at', 'olevel_override_note']));
        Schema::dropIfExists('student_olevel_results');
        Schema::dropIfExists('student_olevel_sittings');
        Schema::dropIfExists('olevel_requirement_subjects');
        Schema::dropIfExists('olevel_requirements');
    }
};
