<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Staff records, payroll and pensions, and roles limited to one faculty, department or unit.
 *
 * - units: non-academic offices (Bursary, Registry, Works ...) with a head; staff belong to a department or a unit.
 * - staff_profiles gain pay grade and step, bank, pension (PFA, RSA PIN), tax and exit details.
 * - salary_structures / salary_scales: the annual basic salary for each grade level and step.
 * - pay_elements: allowances, deductions and employer contributions, each with how it is worked out and who it applies to.
 * - staff_pay_items: one person's own earnings and deductions (loans with a balance, co-operative, union dues ...).
 * - payroll_runs / payslips / payslip_lines: a month's payroll, frozen once approved.
 * - scoped_roles: a role held only for one faculty, department or unit (heads get theirs automatically).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('units', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code', 20)->nullable()->unique();
            $table->foreignId('head_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('salary_structures', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();                       // CONUASS, CONTISS ...
            $table->string('name');
            $table->unsignedTinyInteger('max_grade')->default(15);
            $table->unsignedTinyInteger('max_step')->default(15);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('salary_scales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('salary_structure_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('grade_level');
            $table->unsignedTinyInteger('step');
            $table->decimal('annual_basic', 14, 2);
            $table->timestamps();
            $table->unique(['salary_structure_id', 'grade_level', 'step']);
        });

        Schema::table('staff_profiles', function (Blueprint $table) {
            $table->foreignId('unit_id')->nullable()->after('department_id')->constrained('units')->nullOnDelete();
            $table->string('title', 20)->nullable()->after('user_id');
            $table->foreignId('salary_structure_id')->nullable()->after('grade_level')->constrained()->nullOnDelete();
            $table->unsignedTinyInteger('step')->nullable()->after('salary_structure_id');
            $table->string('employment_status', 30)->default('active');  // lookup employment_status
            $table->date('confirmation_date')->nullable();
            $table->boolean('professorial')->default(false);              // professors may retire later (setting)
            $table->date('exit_date')->nullable();
            $table->string('exit_reason')->nullable();
            $table->string('bank_name')->nullable();
            $table->string('bank_code', 10)->nullable();
            $table->string('bank_account_number', 20)->nullable();
            $table->string('bank_account_name')->nullable();
            $table->string('pfa_code', 40)->nullable();                   // lookup pfa
            $table->string('rsa_pin', 30)->nullable();
            $table->string('tax_id', 30)->nullable();
            $table->string('tax_state', 40)->nullable();
            $table->string('nhf_number', 30)->nullable();
        });

        // Units typed on staff profiles become real units.
        $now = now();
        foreach (DB::table('staff_profiles')->whereNotNull('unit')->where('unit', '!=', '')->distinct()->pluck('unit') as $name) {
            $id = DB::table('units')->insertGetId(['name' => $name, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
            DB::table('staff_profiles')->where('unit', $name)->update(['unit_id' => $id]);
        }
        Schema::table('staff_profiles', fn (Blueprint $table) => $table->dropColumn('unit'));

        Schema::create('pay_elements', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name');
            $table->string('kind', 12);                                  // earning | deduction | employer
            $table->string('method', 20);                                // fixed | percent_basic | percent_pensionable | grade_table | paye
            $table->decimal('amount', 14, 2)->nullable();                // fixed: monthly amount
            $table->decimal('rate', 7, 3)->nullable();                   // percent methods
            $table->boolean('taxable')->default(true);                   // earnings: counts as taxable income
            $table->boolean('pensionable')->default(false);              // earnings: counts towards pension
            $table->boolean('tax_relief')->default(false);               // deductions: taken off income before tax
            $table->json('applies_to')->nullable();                      // {structures:[], categories:[], grade_min, grade_max}
            $table->json('grade_amounts')->nullable();                   // grade_table: {"7": 15000, ...} monthly by grade level
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_system')->default(false);                // statutory items cannot be deleted
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('staff_pay_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 12);                                  // earning | deduction
            $table->string('name');
            $table->decimal('amount', 14, 2);                            // per month
            $table->decimal('balance', 14, 2)->nullable();               // loans: what is still owed; stops at zero
            $table->boolean('taxable')->default(true);                   // earnings only
            $table->char('start_month', 7);                              // YYYY-MM
            $table->char('end_month', 7)->nullable();
            $table->string('reference')->nullable();
            $table->text('note')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('payroll_runs', function (Blueprint $table) {
            $table->id();
            $table->char('period', 7)->unique();                         // YYYY-MM
            $table->string('status', 12)->default('draft');              // draft | approved | paid
            $table->unsignedInteger('staff_count')->default(0);
            $table->decimal('gross', 16, 2)->default(0);
            $table->decimal('deductions', 16, 2)->default(0);
            $table->decimal('net', 16, 2)->default(0);
            $table->decimal('employer_cost', 16, 2)->default(0);
            $table->json('problems')->nullable();                        // staff left out and why
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });

        Schema::create('payslips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('staff_number', 40)->nullable();
            $table->string('department')->nullable();
            $table->string('designation')->nullable();
            $table->string('structure', 20)->nullable();
            $table->unsignedTinyInteger('grade_level')->nullable();
            $table->unsignedTinyInteger('step')->nullable();
            $table->decimal('factor', 6, 4)->default(1);                 // share of the month paid (joining, leaving, unpaid leave)
            $table->decimal('basic', 14, 2)->default(0);
            $table->decimal('gross', 14, 2)->default(0);
            $table->decimal('taxable', 14, 2)->default(0);
            $table->decimal('tax', 14, 2)->default(0);
            $table->decimal('pension_employee', 14, 2)->default(0);
            $table->decimal('pension_employer', 14, 2)->default(0);
            $table->decimal('total_deductions', 14, 2)->default(0);
            $table->decimal('net', 14, 2)->default(0);
            $table->string('bank_name')->nullable();
            $table->string('bank_code', 10)->nullable();
            $table->string('bank_account_number', 20)->nullable();
            $table->string('bank_account_name')->nullable();
            $table->string('pfa_code', 40)->nullable();
            $table->string('rsa_pin', 30)->nullable();
            $table->string('tax_id', 30)->nullable();
            $table->string('tax_state', 40)->nullable();
            $table->json('notes')->nullable();                           // warnings for whoever checks the run
            $table->timestamp('emailed_at')->nullable();
            $table->timestamps();
            $table->unique(['payroll_run_id', 'user_id']);
        });

        Schema::create('payslip_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payslip_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 12);                                  // earning | deduction | employer
            $table->string('code', 20)->nullable();
            $table->string('name');
            $table->decimal('amount', 14, 2);
            $table->foreignId('staff_pay_item_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('sort_order')->default(0);
        });

        Schema::create('scoped_roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->string('scope_type', 12);                            // faculty | department | unit
            $table->unsignedBigInteger('scope_id');
            $table->string('source', 12)->default('manual');             // manual | head (given because the person heads it)
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'role_id', 'scope_type', 'scope_id']);
            $table->index(['scope_type', 'scope_id']);
        });

        $now = now();
        foreach ([['CONUASS', 'Consolidated University Academic Salary Structure', 7, 15], ['CONTISS', 'Consolidated Tertiary Institutions Salary Structure', 15, 15]] as [$code, $name, $grades, $steps]) {
            DB::table('salary_structures')->insertOrIgnore(['code' => $code, 'name' => $name, 'max_grade' => $grades, 'max_step' => $steps, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
        }
        \App\Support\ReferenceData::seed();
        \App\Support\PayrollDefaults::seed();
    }

    public function down(): void
    {
        foreach (['scoped_roles', 'payslip_lines', 'payslips', 'payroll_runs', 'staff_pay_items', 'pay_elements'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::table('staff_profiles', function (Blueprint $table) {
            $table->string('unit')->nullable();
            $table->dropConstrainedForeignId('unit_id');
            $table->dropConstrainedForeignId('salary_structure_id');
            $table->dropColumn(['title', 'step', 'employment_status', 'confirmation_date', 'professorial', 'exit_date', 'exit_reason', 'bank_name', 'bank_code',
                'bank_account_number', 'bank_account_name', 'pfa_code', 'rsa_pin', 'tax_id', 'tax_state', 'nhf_number']);
        });
        Schema::dropIfExists('salary_scales');
        Schema::dropIfExists('salary_structures');
        Schema::dropIfExists('units');
    }
};
