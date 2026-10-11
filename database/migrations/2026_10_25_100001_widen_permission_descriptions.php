<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Permission descriptions explain in full what a permission allows, which can be longer than 255 characters.
 * SQLite ignores the length; MySQL in strict mode refuses the row, so db:seed stopped there.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('permissions', fn (Blueprint $table) => $table->text('description')->nullable()->change());
    }

    public function down(): void
    {
        Schema::table('permissions', fn (Blueprint $table) => $table->string('description')->nullable()->change());
    }
};
