<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Student and staff identity cards. A card keeps the details printed on it (snapshot) so a reprint matches, and a
 * random token for the QR code that anyone can scan to check the card. Issuing a new card marks the old one replaced.
 * A holder's request for a replacement is a row in "requested" status until it is issued.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('id_cards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 10);                                   // student | staff
            $table->string('serial', 40)->nullable()->unique();
            $table->string('token', 40)->unique();
            $table->string('status', 12)->default('active');              // requested | active | replaced | cancelled
            $table->string('reason', 20)->default('first');               // first | lost | damaged | change | renewal
            $table->json('snapshot')->nullable();                         // name, number, programme or department, photo ...
            $table->text('request_note')->nullable();
            $table->timestamp('requested_at')->nullable();
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('issued_at')->nullable();
            $table->date('expires_on')->nullable();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('printed_at')->nullable();
            $table->foreignId('printed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('collected_at')->nullable();
            $table->foreignId('collected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancel_reason', 500)->nullable();
            $table->timestamps();
            $table->index(['user_id', 'status']);
            $table->index(['kind', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('id_cards');
    }
};
