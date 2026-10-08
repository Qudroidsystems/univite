<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // What a newly admitted student must clear before a matric number is issued. Set per university.
        Schema::create('clearance_items', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code', 40)->unique();
            $table->text('description')->nullable();
            $table->string('mode', 10)->default('manual');         // auto | manual
            $table->string('auto_rule', 40)->nullable();            // which check clears an auto item
            $table->string('office_role')->nullable();              // manual items: the role whose staff sign it off (blank = any clearance officer)
            $table->boolean('is_required')->default(true);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('student_clearances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('clearance_item_id')->constrained('clearance_items')->cascadeOnDelete();
            $table->string('status', 10)->default('pending');       // pending | cleared | rejected
            $table->text('note')->nullable();                        // the officer's reason or remark
            $table->text('student_note')->nullable();                // what the student said when re-submitting
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('resubmitted_at')->nullable();
            $table->timestamps();

            $table->unique(['student_id', 'clearance_item_id']);
        });

        // Matric numbers are now issued after clearance, so a student may exist without one for a while.
        Schema::table('students', function (Blueprint $table) {
            $table->string('matric_number')->nullable()->change();
            $table->timestamp('cleared_at')->nullable();
        });

        // Every issue, cancel, restore and undo of a matric number, so each can be explained and reversed.
        Schema::create('matric_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->string('action', 20);                            // generated | special | external | cancelled | restored | undone
            $table->string('matric_number')->nullable();
            $table->string('batch', 40)->nullable();
            $table->foreignId('performed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note')->nullable();
            $table->timestamps();

            $table->index('batch');
        });

        if (! Schema::hasTable('notifications')) {
            Schema::create('notifications', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('type');
                $table->morphs('notifiable');
                $table->text('data');
                $table->timestamp('read_at')->nullable();
                $table->timestamps();
            });
        }

        $now = now();
        $rows = [
            ['offer', 'Accepted admission offer', 'The student has accepted their offer of admission.', 'auto', 'offer_accepted', 1, 1],
            ['acceptance_fee', 'Acceptance fee paid', 'The acceptance fee has been paid in full. Passes automatically when no acceptance fee is set.', 'auto', 'acceptance_fee_paid', 1, 1],
            ['biodata', 'Biodata completed', 'Personal and guardian details are filled in.', 'auto', 'biodata_complete', 1, 1],
            ['documents', 'Documents uploaded', 'Every required document has been uploaded.', 'auto', 'documents_uploaded', 1, 1],
            ['verification', 'Physically verified', 'The student attended physical verification and the original documents were checked.', 'auto', 'verified', 1, 1],
            ['school_fees', 'School fees paid', 'The first-session school fees invoice is fully paid.', 'auto', 'fees_paid', 1, 0],
            ['medical', 'Medical clearance', 'Cleared by the university health centre.', 'manual', null, 1, 0],
            ['department', 'Department sign-off', 'Cleared by the student\'s department.', 'manual', null, 1, 0],
        ];
        foreach ($rows as $i => [$code, $name, $desc, $mode, $rule, $required, $active]) {
            DB::table('clearance_items')->insertOrIgnore([
                'code' => $code, 'name' => $name, 'description' => $desc, 'mode' => $mode, 'auto_rule' => $rule,
                'is_required' => $required, 'is_active' => $active, 'sort_order' => ($i + 1) * 10, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('matric_events');
        Schema::dropIfExists('student_clearances');
        Schema::dropIfExists('clearance_items');
        Schema::table('students', fn (Blueprint $table) => $table->dropColumn('cleared_at'));
    }
};
