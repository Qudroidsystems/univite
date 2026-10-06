<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The catalogue of things a student can be billed for (Tuition, Hostel, Library ...)
        Schema::create('bill_items', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('code', 20)->unique();
            $table->text('description')->nullable();
            $table->boolean('is_compulsory')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // How much a bill item costs for a programme + level in a session
        Schema::create('bills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('academic_session_id')->constrained('academic_sessions')->cascadeOnDelete();
            $table->foreignId('programme_id')->constrained('programmes')->cascadeOnDelete();
            $table->unsignedSmallInteger('level');                   // 100, 200 ...
            $table->foreignId('bill_item_id')->constrained('bill_items')->restrictOnDelete();
            $table->string('student_type', 20)->default('all');      // all | fresh | returning
            $table->decimal('amount', 14, 2);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(
                ['academic_session_id', 'programme_id', 'level', 'bill_item_id', 'student_type'],
                'bills_unique_charge'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bills');
        Schema::dropIfExists('bill_items');
    }
};
