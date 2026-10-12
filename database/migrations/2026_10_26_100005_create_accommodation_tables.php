<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hostels', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('code', 20)->nullable();
            $t->string('gender', 10)->default('mixed'); // male, female, mixed
            $t->json('audience')->nullable(); // which students may be placed here (levels, programmes ...)
            $t->text('description')->nullable();
            $t->foreignId('warden_id')->nullable()->constrained('users')->nullOnDelete();
            $t->boolean('is_active')->default(true);
            $t->unsignedSmallInteger('sort_order')->default(0);
            $t->timestamps();
        });

        Schema::create('hostel_rooms', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hostel_id')->constrained()->cascadeOnDelete();
            $t->string('block', 50)->nullable();
            $t->string('name', 50); // room number
            $t->unsignedTinyInteger('floor')->nullable();
            $t->unsignedTinyInteger('capacity')->default(4);
            $t->boolean('is_active')->default(true);
            $t->string('note')->nullable();
            $t->timestamps();
            $t->unique(['hostel_id', 'block', 'name']);
        });

        // What a bed in a hostel costs for a session. No row (or 0) means free.
        Schema::create('hostel_rates', function (Blueprint $t) {
            $t->id();
            $t->foreignId('hostel_id')->constrained()->cascadeOnDelete();
            $t->foreignId('academic_session_id')->constrained()->cascadeOnDelete();
            $t->decimal('amount', 12, 2)->default(0);
            $t->timestamps();
            $t->unique(['hostel_id', 'academic_session_id']);
        });

        Schema::create('accommodation_applications', function (Blueprint $t) {
            $t->id();
            $t->foreignId('student_id')->constrained()->cascadeOnDelete();
            $t->foreignId('academic_session_id')->constrained()->cascadeOnDelete();
            $t->json('preferences')->nullable(); // hostel ids, most wanted first
            $t->string('status', 20)->default('pending'); // pending, allocated, unsuccessful, withdrawn
            $t->string('note')->nullable();
            $t->timestamps();
            $t->unique(['student_id', 'academic_session_id']);
        });

        // A bed for a student for a session: reserved until paid, then confirmed, then checked in and out.
        Schema::create('bed_allocations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('student_id')->constrained()->cascadeOnDelete();
            $t->foreignId('academic_session_id')->constrained()->cascadeOnDelete();
            $t->foreignId('hostel_room_id')->constrained()->cascadeOnDelete();
            $t->unsignedTinyInteger('bed');
            $t->string('status', 20); // reserved, confirmed, checked_in, checked_out, released, cancelled
            $t->foreignId('application_id')->nullable()->constrained('accommodation_applications')->nullOnDelete();
            $t->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamp('reserved_until')->nullable();
            $t->timestamp('confirmed_at')->nullable();
            $t->timestamp('checked_in_at')->nullable();
            $t->foreignId('checked_in_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('checked_out_at')->nullable();
            $t->foreignId('checked_out_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('ended_at')->nullable();
            $t->string('end_reason')->nullable();
            $t->foreignId('allocated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['academic_session_id', 'status']);
            $t->index(['hostel_room_id', 'academic_session_id', 'status']);
            $t->index(['student_id', 'academic_session_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bed_allocations');
        Schema::dropIfExists('accommodation_applications');
        Schema::dropIfExists('hostel_rates');
        Schema::dropIfExists('hostel_rooms');
        Schema::dropIfExists('hostels');
    }
};
