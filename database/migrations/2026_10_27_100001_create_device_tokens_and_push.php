<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Phones signed in to the mobile app (for push notifications), and each person's choice to get push notices. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('token');
            $table->char('token_hash', 64)->unique();
            $table->string('platform', 20)->default('android');
            $table->string('device_name', 120)->nullable();
            $table->string('app_version', 40)->nullable();
            $table->unsignedBigInteger('access_token_id')->nullable()->index();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('notify_push')->default(true)->after('notify_whatsapp');
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('notify_push'));
        Schema::dropIfExists('device_tokens');
    }
};
