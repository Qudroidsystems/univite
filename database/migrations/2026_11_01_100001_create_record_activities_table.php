<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who created, changed or deleted each record, written automatically for every model a signed-in person saves. Tables
 * show the last person in their "Last changed by" column; the details window shows the record's whole history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('record_activities', function (Blueprint $table) {
            $table->id();
            $table->string('subject_type', 120);
            $table->unsignedBigInteger('subject_id');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 12);                 // created | updated | deleted
            $table->json('fields')->nullable();           // the names of the fields that changed
            $table->string('label', 190)->nullable();     // what the record was called, kept after a delete
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['subject_type', 'subject_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('record_activities');
    }
};
