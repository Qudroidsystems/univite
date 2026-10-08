<?php

use App\Support\ReferenceData;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lookups', function (Blueprint $t) {
            $t->id();
            $t->string('list', 50);
            $t->string('code', 100);
            $t->string('label', 150);
            $t->unsignedSmallInteger('sort_order')->default(0);
            $t->boolean('is_active')->default(true);
            $t->boolean('is_system')->default(false);
            $t->timestamps();
            $t->unique(['list', 'code']);
        });

        Schema::create('study_categories', function (Blueprint $t) {
            $t->id();
            $t->string('name', 100)->unique();
            $t->string('code', 20)->unique();
            $t->unsignedTinyInteger('duration_years')->default(4);
            $t->string('level_format', 50)->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        Schema::create('semester_types', function (Blueprint $t) {
            $t->id();
            $t->unsignedTinyInteger('sort_order')->unique();
            $t->string('name', 100);
            $t->boolean('is_teaching')->default(true);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        Schema::create('student_statuses', function (Blueprint $t) {
            $t->id();
            $t->string('code', 30)->unique();
            $t->string('name', 100);
            $t->string('badge', 20)->default('secondary');
            $t->boolean('can_login')->default(true);
            $t->boolean('can_register')->default(false);
            $t->boolean('can_pay')->default(true);
            $t->boolean('is_enrolled')->default(false);
            $t->boolean('is_default')->default(false);
            $t->unsignedSmallInteger('sort_order')->default(0);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        Schema::create('entry_modes', function (Blueprint $t) {
            $t->id();
            $t->string('code', 30)->unique();
            $t->string('name', 100);
            $t->unsignedSmallInteger('entry_level')->default(100);
            $t->boolean('fixed_level')->default(false);
            $t->boolean('uses_utme')->default(false);
            $t->boolean('needs_prior_result')->default(false);
            $t->boolean('open_online')->default(false);
            $t->unsignedSmallInteger('sort_order')->default(0);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        Schema::table('programmes', function (Blueprint $t) {
            $t->foreignId('study_category_id')->nullable()->after('department_id')->constrained('study_categories')->nullOnDelete();
        });

        ReferenceData::seed();

        // Existing programmes: one study category per distinct degree type they already use.
        $types = DB::table('programmes')->select('degree_type', DB::raw('max(duration_years) as years'))->groupBy('degree_type')->get();
        foreach ($types as $row) {
            $id = DB::table('study_categories')->insertGetId([
                'name' => $row->degree_type, 'code' => Str::upper(Str::slug($row->degree_type, '')) ?: 'CAT' . random_int(10, 99),
                'duration_years' => max(1, (int) $row->years), 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('programmes')->where('degree_type', $row->degree_type)->update(['study_category_id' => $id]);
        }
    }

    public function down(): void
    {
        Schema::table('programmes', fn (Blueprint $t) => $t->dropConstrainedForeignId('study_category_id'));
        foreach (['entry_modes', 'student_statuses', 'semester_types', 'study_categories', 'lookups'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
