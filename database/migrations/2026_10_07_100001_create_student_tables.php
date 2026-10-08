<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->timestamps();
        });

        // Last matric number handed out for each resolved pattern prefix (see MatricNumberGenerator).
        Schema::create('matric_sequences', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->unsignedInteger('last_number')->default(0);
            $table->timestamps();
        });

        Schema::create('applicants', function (Blueprint $table) {
            $table->id();
            $table->string('application_no')->nullable()->unique();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('other_names')->nullable();
            $table->string('email')->unique();
            $table->string('phone_number')->nullable();
            $table->enum('gender', ['male', 'female', 'other'])->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('state_of_origin')->nullable();
            $table->string('lga')->nullable();
            $table->string('nationality')->nullable();
            $table->text('address')->nullable();

            $table->foreignId('programme_id')->constrained('programmes');
            $table->foreignId('academic_session_id')->constrained('academic_sessions');
            $table->string('entry_mode', 20);
            $table->unsignedSmallInteger('entry_level');

            $table->string('guardian_name')->nullable();
            $table->string('guardian_relationship')->nullable();
            $table->string('guardian_phone')->nullable();
            $table->text('guardian_address')->nullable();

            $table->string('status', 20)->default('pending'); // pending | admitted | rejected
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->index('status');
        });

        Schema::create('students', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users');
            $table->foreignId('applicant_id')->nullable()->constrained('applicants');
            $table->string('matric_number')->unique();

            $table->string('first_name');
            $table->string('last_name');
            $table->string('other_names')->nullable();
            $table->string('phone_number')->nullable();
            $table->enum('gender', ['male', 'female', 'other'])->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('state_of_origin')->nullable();
            $table->string('lga')->nullable();
            $table->string('nationality')->nullable();
            $table->text('address')->nullable();
            $table->string('photo')->nullable();

            $table->foreignId('programme_id')->constrained('programmes');
            $table->foreignId('academic_session_id')->constrained('academic_sessions'); // entry session
            $table->string('entry_mode', 20);
            $table->unsignedSmallInteger('entry_level');
            $table->unsignedSmallInteger('current_level');
            $table->string('status', 20)->default('active'); // active | deferred | suspended | withdrawn | graduated

            $table->string('guardian_name')->nullable();
            $table->string('guardian_relationship')->nullable();
            $table->string('guardian_phone')->nullable();
            $table->text('guardian_address')->nullable();

            $table->timestamps();

            $table->index(['programme_id', 'current_level']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('students');
        Schema::dropIfExists('applicants');
        Schema::dropIfExists('matric_sequences');
        Schema::dropIfExists('settings');
    }
};
