<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Which seeders have run, so one-off seeders are not repeated by later db:seed runs.
    public function up(): void
    {
        if (Schema::hasTable('seeder_log')) {
            return;
        }
        Schema::create('seeder_log', function (Blueprint $t) {
            $t->id();
            $t->string('seeder_name')->unique();
            $t->timestamp('started_at')->nullable();
            $t->timestamp('completed_at')->nullable();
            $t->boolean('success')->default(false);
            $t->text('error_message')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seeder_log');
    }
};
