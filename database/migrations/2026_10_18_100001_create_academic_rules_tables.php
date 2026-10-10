<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Overrides of the university's academic rules for a faculty, department or programme,
        // optionally only for one level and/or semester. A rule missing from `rules` is inherited.
        Schema::create('academic_rules', function (Blueprint $table) {
            $table->id();
            $table->string('scope_type', 20);                 // faculty | department | programme
            $table->unsignedBigInteger('scope_id');
            $table->unsignedSmallInteger('level')->nullable();
            $table->foreignId('semester_type_id')->nullable()->constrained('semester_types')->cascadeOnDelete();
            $table->json('rules');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['scope_type', 'scope_id', 'level', 'semester_type_id'], 'academic_rule_scope');
        });

        // Staff who administer a department (besides its head): they may edit its academic rules.
        Schema::create('department_admins', function (Blueprint $table) {
            $table->foreignId('department_id')->constrained('departments')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->primary(['department_id', 'user_id']);
        });

        // Carry over what programmes already held: units to graduate, and a credit load different from the university's.
        $min = (int) (DB::table('settings')->where('key', 'registration.min_units')->value('value') ?? 15);
        $max = (int) (DB::table('settings')->where('key', 'registration.max_units')->value('value') ?? 24);
        foreach (DB::table('programmes')->get() as $p) {
            $rules = array_filter([
                'graduation_units' => $p->graduation_units ?? null,
                'min_units' => (int) $p->min_credit_load !== $min ? (int) $p->min_credit_load : null,
                'max_units' => (int) $p->max_credit_load !== $max ? (int) $p->max_credit_load : null,
            ], fn ($v) => $v !== null);
            if ($rules) {
                DB::table('academic_rules')->insert(['scope_type' => 'programme', 'scope_id' => $p->id, 'rules' => json_encode($rules), 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('department_admins');
        Schema::dropIfExists('academic_rules');
    }
};
