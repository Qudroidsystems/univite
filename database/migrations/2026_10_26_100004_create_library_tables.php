<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A title in the catalogue (a book, journal, project or thesis). Its physical copies are library_copies.
        Schema::create('library_items', function (Blueprint $t) {
            $t->id();
            $t->string('kind', 20)->default('book');
            $t->string('title');
            $t->string('authors')->nullable();
            $t->string('isbn', 20)->nullable()->index();
            $t->string('publisher')->nullable();
            $t->unsignedSmallInteger('year')->nullable();
            $t->string('edition', 50)->nullable();
            $t->string('call_number', 50)->nullable()->index();
            $t->string('subject')->nullable();
            $t->text('description')->nullable();
            $t->string('url')->nullable(); // for electronic resources
            $t->boolean('is_reference')->default(false); // read in the library only
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index('title');
        });

        Schema::create('library_copies', function (Blueprint $t) {
            $t->id();
            $t->foreignId('library_item_id')->constrained()->cascadeOnDelete();
            $t->string('accession_number', 50)->unique();
            $t->string('location')->nullable();
            $t->string('status', 20)->default('available'); // available, on_loan, on_hold, repair, lost, withdrawn
            $t->date('acquired_on')->nullable();
            $t->decimal('price', 12, 2)->nullable();
            $t->string('note')->nullable();
            $t->timestamps();
        });

        Schema::create('library_loans', function (Blueprint $t) {
            $t->id();
            $t->foreignId('library_copy_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->timestamp('issued_at');
            $t->timestamp('due_at');
            $t->timestamp('returned_at')->nullable();
            $t->unsignedTinyInteger('renewals')->default(0);
            $t->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('returned_to')->nullable()->constrained('users')->nullOnDelete();
            $t->boolean('lost')->default(false);
            $t->decimal('fine', 12, 2)->default(0);
            $t->string('fine_status', 20)->default('none'); // none, owed, invoiced, paid, waived
            $t->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamp('fine_settled_at')->nullable();
            $t->foreignId('fine_settled_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('fine_note')->nullable();
            $t->timestamp('reminded_at')->nullable();
            $t->timestamps();
            $t->index(['user_id', 'returned_at']);
            $t->index(['returned_at', 'due_at']);
        });

        // A borrower waiting for a title. When a copy comes back it is kept for the first person waiting.
        Schema::create('library_holds', function (Blueprint $t) {
            $t->id();
            $t->foreignId('library_item_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('library_copy_id')->nullable()->constrained()->nullOnDelete();
            $t->string('status', 20)->default('waiting'); // waiting, ready, fulfilled, cancelled, expired
            $t->timestamp('ready_at')->nullable();
            $t->timestamp('expires_at')->nullable();
            $t->timestamps();
            $t->index(['library_item_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('library_holds');
        Schema::dropIfExists('library_loans');
        Schema::dropIfExists('library_copies');
        Schema::dropIfExists('library_items');
    }
};
