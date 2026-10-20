<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A student the school allows (or bars) for one area despite their fees: registration, exams, results or the portal.
        Schema::create('fee_access_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('academic_session_id')->constrained('academic_sessions')->cascadeOnDelete();
            $table->string('area', 20);                           // registration | exams | results | portal
            $table->boolean('allow');                              // true = allowed despite fees, false = barred
            $table->string('reason', 500);
            $table->date('expires_on')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['student_id', 'academic_session_id', 'area']);
        });

        // The school's fee loan scheme: a loan against a session's fees, paid onto the invoice, repaid in instalments.
        Schema::create('student_loans', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 30)->nullable()->unique();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('academic_session_id')->constrained('academic_sessions');
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('amount_requested', 12, 2);
            $table->decimal('amount_approved', 12, 2)->nullable();
            $table->decimal('interest_rate', 5, 2)->default(0);   // flat %, on the approved amount
            $table->decimal('amount_due', 12, 2)->default(0);      // approved + interest
            $table->decimal('amount_repaid', 12, 2)->default(0);
            $table->string('status', 15)->default('pending');      // pending | approved | declined | disbursed | settled | written_off | cancelled
            $table->text('reason')->nullable();
            $table->string('guarantor_name', 150)->nullable();
            $table->string('guarantor_phone', 30)->nullable();
            $table->string('guarantor_relationship', 50)->nullable();
            $table->string('guarantor_address', 255)->nullable();
            $table->json('instalments')->nullable();               // [{due: Y-m-d, amount}]
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->foreignId('disbursed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('disbursed_at')->nullable();
            $table->foreignId('payment_id')->nullable()->constrained('payments')->nullOnDelete();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->text('close_note')->nullable();
            $table->timestamps();
            $table->index(['student_id', 'status']);
        });

        Schema::create('loan_repayments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_loan_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 12, 2);
            $table->date('paid_on');
            $table->string('channel', 30);
            $table->string('reference', 100)->nullable();
            $table->string('note', 500)->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loan_repayments');
        Schema::dropIfExists('student_loans');
        Schema::dropIfExists('fee_access_overrides');
    }
};
