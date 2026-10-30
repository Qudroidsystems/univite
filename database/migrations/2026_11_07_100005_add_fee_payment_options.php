<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Per fee: paid on the student's main invoice or on its own invoice, and in how many payments at most.
        Schema::table('bill_items', function (Blueprint $table) {
            $table->string('payment_mode', 20)->nullable()->after('is_compulsory');   // invoice | separate | null = the school's default
            $table->unsignedTinyInteger('max_payments')->nullable()->after('payment_mode'); // 1 = one payment; null = the school's default
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->foreignId('bill_item_id')->nullable()->after('kind')->constrained('bill_items')->nullOnDelete(); // set on a fee paid on its own
            $table->unsignedTinyInteger('max_payments')->nullable()->after('installment_plan');
        });

        // Per department: students may register courses before paying; what they owe is shown to them.
        Schema::create('department_fee_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('register_without_paying')->default(true);
            $table->boolean('show_owing')->default(true);
            $table->string('note')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('department_fee_rules');
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('bill_item_id');
            $table->dropColumn('max_payments');
        });
        Schema::table('bill_items', function (Blueprint $table) {
            $table->dropColumn(['payment_mode', 'max_payments']);
        });
    }
};
