<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1 (low) .. 5 (high): high-priority courses are placed first and get the earlier periods.
        Schema::table('timetable_items', function (Blueprint $table) {
            $table->unsignedTinyInteger('priority')->default(3)->after('preferred_venue_id');
        });
    }

    public function down(): void
    {
        Schema::table('timetable_items', fn (Blueprint $t) => $t->dropColumn('priority'));
    }
};
