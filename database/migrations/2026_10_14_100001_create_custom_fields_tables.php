<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Extra questions an administrator adds to the applicant, student and staff forms.
        Schema::create('custom_fields', function (Blueprint $table) {
            $table->id();
            $table->string('target', 20);                       // applicant | student | staff
            $table->string('key', 50);                          // stable name, e.g. "next_of_kin_occupation"
            $table->string('label');
            $table->string('type', 20);                         // text | textarea | number | date | select | checkbox | file
            $table->json('options')->nullable();                // choices for a select
            $table->string('section')->nullable();              // heading the field is grouped under
            $table->string('help_text')->nullable();
            $table->boolean('is_required')->default(false);
            $table->boolean('self_service')->default(false);    // the person can fill it in themselves (online application / getting started)
            $table->string('accept', 80)->default('jpg,jpeg,png,pdf');   // file types allowed for a file field
            $table->unsignedInteger('max_kb')->default(2048);            // largest upload for a file field
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['target', 'key']);
        });

        Schema::create('custom_field_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('custom_field_id')->constrained('custom_fields')->cascadeOnDelete();
            $table->morphs('subject');                           // the applicant / student / user the answer belongs to
            $table->text('value')->nullable();
            $table->string('file_path')->nullable();
            $table->string('original_name')->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->timestamps();

            $table->unique(['custom_field_id', 'subject_type', 'subject_id'], 'cfv_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_field_values');
        Schema::dropIfExists('custom_fields');
    }
};
