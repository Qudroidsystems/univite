<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The student's academic companion in the app:
 * - venues get a map position, so the app can tell how far a lecture or exam hall is;
 * - a reading plan per student per semester (the weekly study timetable the app builds and reminds about);
 * - study documents the student uploads (or picks from course materials), with their text for the AI tutor;
 * - practice quizzes generated from them, with the student's answers;
 * - a daily count of AI requests per person, for the school's limit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('venues', function (Blueprint $table) {
            $table->decimal('latitude', 10, 7)->nullable()->after('entry_info');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
        });

        Schema::create('study_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('academic_session_id')->nullable()->constrained('academic_sessions')->nullOnDelete();
            $table->foreignId('semester_type_id')->nullable()->constrained('semester_types')->nullOnDelete();
            $table->json('preferences');        // free time, session length, priorities, criteria weights
            $table->json('sessions');           // [{day, starts_at, ends_at, course_id, code, title, kind}]
            $table->json('summary')->nullable(); // minutes per course and why
            $table->timestamp('generated_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'academic_session_id', 'semester_type_id'], 'study_plan_term');
        });

        Schema::create('study_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_id')->nullable()->constrained('courses')->nullOnDelete();
            $table->foreignId('course_material_id')->nullable()->constrained('course_materials')->nullOnDelete();
            $table->string('title', 150);
            $table->string('path')->nullable();
            $table->string('original_name')->nullable();
            $table->string('mime', 120)->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->longText('text')->nullable();
            $table->unsignedInteger('chars')->default(0);
            $table->string('status', 20)->default('ready');   // ready | failed
            $table->string('error', 500)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'course_id']);
        });

        Schema::create('study_quizzes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('study_document_id')->nullable()->constrained('study_documents')->cascadeOnDelete();
            $table->foreignId('course_id')->nullable()->constrained('courses')->nullOnDelete();
            $table->string('title', 150);
            $table->string('difficulty', 20)->default('mixed');
            $table->json('questions');          // [{type, question, options, answer, explanation, topic, source, quote}]
            $table->json('answers')->nullable(); // {index: {answer, correct, score, feedback}}
            $table->decimal('score', 5, 2)->nullable();   // percent so far
            $table->timestamps();

            $table->index(['user_id', 'course_id']);
        });

        Schema::create('ai_usages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('day');
            $table->unsignedInteger('requests')->default(0);
            $table->unsignedBigInteger('input_tokens')->default(0);
            $table->unsignedBigInteger('output_tokens')->default(0);
            $table->timestamps();

            $table->unique(['user_id', 'day']);
        });
    }

    public function down(): void
    {
        foreach (['ai_usages', 'study_quizzes', 'study_documents', 'study_plans'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::table('venues', fn (Blueprint $t) => $t->dropColumn(['latitude', 'longitude']));
    }
};
