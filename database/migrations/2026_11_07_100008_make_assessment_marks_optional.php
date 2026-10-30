<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An assessment can be left without a mark of its own (CA1, CA2, CA3 with no "out of"): its marks then only have to keep
 * the part within the CA or exam total the admin set for the scheme.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assessment_items', function (Blueprint $table) {
            $table->decimal('max_score', 5, 2)->nullable()->change();
        });
    }

    public function down(): void
    {
        \Illuminate\Support\Facades\DB::table('assessment_items')->whereNull('max_score')->update(['max_score' => 0]);
        Schema::table('assessment_items', function (Blueprint $table) {
            $table->decimal('max_score', 5, 2)->nullable(false)->change();
        });
    }
};
