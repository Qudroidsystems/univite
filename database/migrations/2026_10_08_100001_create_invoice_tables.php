<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // What a student (or, later, an applicant) owes.
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->nullable()->unique();        // INV-2025-000001
            $table->morphs('payable');                                 // student now, applicant later
            $table->foreignId('academic_session_id')->nullable()->constrained('academic_sessions');
            $table->string('title');
            $table->decimal('total', 14, 2);
            $table->decimal('amount_paid', 14, 2)->default(0);
            $table->string('status', 20)->default('unpaid');           // unpaid | partial | paid | cancelled
            $table->date('due_date')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('status');
        });

        Schema::create('invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bill_item_id')->nullable()->constrained('bill_items')->nullOnDelete();
            $table->string('description');
            $table->decimal('amount', 14, 2);
            $table->timestamps();
        });

        // Every attempt to pay an invoice, successful or not. Gateways add their own rows here.
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->string('reference')->unique();                     // our own reference
            $table->string('gateway', 30);                             // manual | paystack | remita ...
            $table->string('gateway_reference')->nullable();           // bank teller / receipt / gateway transaction id
            $table->decimal('amount', 14, 2);
            $table->string('status', 20)->default('pending');          // pending | successful | failed | cancelled
            $table->string('channel', 30)->nullable();                 // bank_deposit, transfer, pos, cash, card ...
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('note')->nullable();
            $table->json('payload')->nullable();                       // raw gateway response
            $table->timestamps();

            $table->unique(['gateway', 'gateway_reference']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
        Schema::dropIfExists('invoice_lines');
        Schema::dropIfExists('invoices');
    }
};
