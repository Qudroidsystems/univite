<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('kind', 20)->default('fees')->after('reference');   // fees | screening | acceptance
        });
        DB::table('invoices')->where('payable_type', 'App\\Models\\Applicant')->update(['kind' => 'screening']);

        Schema::table('students', function (Blueprint $table) {
            $table->boolean('needs_onboarding')->default(false);               // freshly admitted: must accept, pay, complete biodata, be verified
            $table->timestamp('offer_accepted_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('verification_note')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropConstrainedForeignId('verified_by');
            $table->dropColumn(['needs_onboarding', 'offer_accepted_at', 'verified_at', 'verification_note']);
        });
        Schema::table('invoices', fn (Blueprint $t) => $t->dropColumn('kind'));
    }
};
