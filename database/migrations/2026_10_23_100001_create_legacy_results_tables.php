<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Results from before the portal. An import is an uploaded spreadsheet with its column mapping, the check report and,
 * once imported, the results or opening balances it created (so the whole import can be undone). Opening balances are
 * a student's cumulative totals from the old system (TCP, TNU, units passed), added to the CGPA the portal works out.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legacy_imports', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 12);                                  // results | balances
            $table->string('file_name');
            $table->string('path');
            $table->json('headers')->nullable();
            $table->json('mapping')->nullable();                         // field => column index
            $table->json('options')->nullable();
            $table->json('report')->nullable();                          // last check, or the import outcome
            $table->string('status', 12)->default('draft');              // draft | checked | imported | undone
            $table->unsignedInteger('rows')->default(0);
            $table->unsignedInteger('imported')->default(0);
            $table->foreignId('operation_batch_id')->nullable()->constrained('operation_batches')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('imported_at')->nullable();
            $table->timestamps();
        });

        Schema::table('course_results', function (Blueprint $table) {
            $table->foreignId('legacy_import_id')->nullable()->after('result_sheet_id')->constrained('legacy_imports')->nullOnDelete();
        });

        Schema::create('legacy_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->unique()->constrained()->cascadeOnDelete();
            $table->decimal('tcp', 8, 2);                                // total credit points
            $table->unsignedSmallInteger('tnu');                         // total number of units
            $table->unsignedSmallInteger('units_passed')->nullable();
            $table->foreignId('as_of_session_id')->nullable()->constrained('academic_sessions')->nullOnDelete();
            $table->string('note')->nullable();
            $table->foreignId('legacy_import_id')->nullable()->constrained('legacy_imports')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legacy_balances');
        Schema::table('course_results', fn (Blueprint $t) => $t->dropConstrainedForeignId('legacy_import_id'));
        Schema::dropIfExists('legacy_imports');
    }
};
