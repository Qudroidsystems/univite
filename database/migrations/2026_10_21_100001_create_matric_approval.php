<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Matric numbers awaiting approval. With approval switched on, a generated number is reserved on the student as
 * "provisional" and only becomes their matric number (and login) when the batch is approved and published.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->string('provisional_matric')->nullable()->unique()->after('matric_number');
            $table->string('provisional_batch', 20)->nullable()->index()->after('provisional_matric');
        });

        Schema::create('matric_batches', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('source', 12)->default('generated');   // generated | auto
            $table->string('status', 12)->default('pending');     // pending | approved | rejected
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 500)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('matric_batches');
        Schema::table('students', function (Blueprint $table) {
            $table->dropUnique(['provisional_matric']);
            $table->dropColumn(['provisional_matric', 'provisional_batch']);
        });
    }
};
