<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Staff leave: staff profiles (category, grade, appointment, years of service), leave types with their entitlement
 * rules and approval routes, applications with an approval trail and documents, and a balance ledger for carry-over
 * and adjustments. Days taken are always worked out from the applications themselves.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('staff_number', 40)->nullable()->unique();
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->string('unit')->nullable();                            // office / unit for non-teaching staff
            $table->string('designation')->nullable();                     // job title
            $table->string('category', 40)->nullable();                    // lookup staff_category
            $table->string('appointment_type', 40)->nullable();            // lookup appointment_type
            $table->unsignedSmallInteger('grade_level')->nullable();
            $table->date('first_appointment_date')->nullable();
            $table->foreignId('supervisor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('critical_role')->default(false);              // must be covered when away
            $table->timestamps();
        });

        Schema::create('leave_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('default_days')->default(0);
            $table->boolean('working_days')->default(true);                // false = calendar days
            $table->boolean('paid')->default(true);
            $table->boolean('needs_document')->default(false);
            $table->string('document_label')->nullable();
            $table->boolean('confidential')->default(false);               // medical: documents only for authorised staff
            $table->string('gender', 10)->nullable();                      // male | female | null = everyone
            $table->unsignedSmallInteger('min_service_months')->default(0);
            $table->unsignedSmallInteger('max_days_per_request')->nullable();
            $table->unsignedSmallInteger('notice_days')->default(0);
            $table->unsignedSmallInteger('max_carryover')->default(0);
            $table->boolean('needs_acting_officer')->default(false);
            $table->boolean('deducts_balance')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('leave_entitlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('leave_type_id')->constrained()->cascadeOnDelete();
            $table->string('category', 40)->nullable();
            $table->string('appointment_type', 40)->nullable();
            $table->unsignedSmallInteger('min_grade')->nullable();
            $table->unsignedSmallInteger('max_grade')->nullable();
            $table->unsignedSmallInteger('min_years')->default(0);
            $table->unsignedSmallInteger('days');
            $table->timestamps();
        });

        Schema::create('leave_approval_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('leave_type_id')->nullable()->constrained()->cascadeOnDelete(); // null = the default route
            $table->unsignedTinyInteger('sort_order');
            $table->string('label');
            $table->string('approver', 20);                                // supervisor | hod | dean | role
            $table->string('role_name')->nullable();
            $table->unsignedSmallInteger('due_days')->default(3);          // remind, then escalate after this
            $table->boolean('can_skip_if_missing')->default(true);         // no HOD on record: move on
            $table->timestamps();
        });

        Schema::create('leave_requests', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 30)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('leave_type_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('year');
            $table->date('start_date');
            $table->date('end_date');
            $table->unsignedSmallInteger('days');
            $table->date('expected_resumption');
            $table->text('reason')->nullable();
            $table->string('address_during_leave')->nullable();
            $table->string('phone_during_leave', 30)->nullable();
            $table->foreignId('acting_officer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('acting_status', 12)->nullable();               // pending | accepted | declined
            $table->string('status', 12)->default('pending');              // pending | returned | approved | rejected | cancelled
            $table->unsignedTinyInteger('current_step')->default(1);
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('decision_note', 500)->nullable();
            $table->date('resumed_on')->nullable();
            $table->foreignId('resumption_recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('resumption_note', 500)->nullable();
            $table->unsignedSmallInteger('late_days')->default(0);
            $table->unsignedSmallInteger('days_used')->nullable();         // when someone resumes early
            $table->timestamp('late_flagged_at')->nullable();
            $table->json('reminders')->nullable();                         // which reminders were sent
            $table->json('warnings')->nullable();                          // coverage and overlap warnings at submission
            $table->timestamps();
            $table->index(['user_id', 'status']);
            $table->index(['start_date', 'end_date']);
        });

        Schema::create('leave_request_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('leave_request_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('step');
            $table->string('label');
            $table->string('approver', 20);
            $table->string('role_name')->nullable();
            $table->foreignId('approver_id')->nullable()->constrained('users')->nullOnDelete(); // resolved person, when there is one
            $table->string('status', 12)->default('waiting');              // waiting | pending | approved | rejected | returned | skipped
            $table->foreignId('acted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('acted_at')->nullable();
            $table->string('comment', 500)->nullable();
            $table->unsignedSmallInteger('due_days')->default(3);
            $table->timestamp('due_at')->nullable();
            $table->timestamp('reminded_at')->nullable();
            $table->timestamp('escalated_at')->nullable();
            $table->timestamps();
        });

        Schema::create('leave_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('leave_request_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->string('original_name');
            $table->string('mime', 100)->nullable();
            $table->unsignedInteger('size')->default(0);
            $table->boolean('confidential')->default(false);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('leave_ledger', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('leave_type_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->string('kind', 12);                                    // carryover | adjustment
            $table->decimal('days', 6, 1);
            $table->string('note', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['user_id', 'leave_type_id', 'year']);
        });

        $now = now();
        // Starting leave types: each university edits days, documents and rules afterwards.
        $types = [
            ['annual', 'Annual leave', 30, true, false, null, false, null, 6, 0, 10, true, true],
            ['casual', 'Casual leave', 7, true, false, null, false, null, 0, 0, 0, false, true],
            ['sick', 'Sick leave', 21, true, true, 'Medical certificate', true, null, 0, 0, 0, false, true],
            ['maternity', 'Maternity leave', 112, false, true, 'Medical certificate (expected date of delivery)', true, 'female', 0, 0, 0, true, false],
            ['paternity', 'Paternity leave', 14, true, true, 'Birth certificate or hospital letter', false, 'male', 0, 0, 0, false, false],
            ['compassionate', 'Compassionate leave', 7, true, false, null, false, null, 0, 0, 0, false, true],
            ['study', 'Study leave', 365, false, true, 'Admission letter', false, null, 36, 30, 0, true, false],
            ['exam', 'Examination leave', 10, true, true, 'Examination timetable', false, null, 0, 0, 0, false, true],
            ['sabbatical', 'Sabbatical leave', 365, false, true, 'Approved sabbatical plan', false, null, 72, 60, 0, true, false],
        ];
        foreach ($types as $i => [$code, $name, $days, $working, $doc, $label, $conf, $gender, $service, $notice, $carry, $acting, $deducts]) {
            DB::table('leave_types')->insert([
                'code' => $code, 'name' => $name, 'default_days' => $days, 'working_days' => $working, 'paid' => true, 'needs_document' => $doc,
                'document_label' => $label, 'confidential' => $conf, 'gender' => $gender, 'min_service_months' => $service, 'notice_days' => $notice,
                'max_carryover' => $carry, 'needs_acting_officer' => $acting, 'deducts_balance' => $deducts, 'sort_order' => $i + 1, 'is_active' => true,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        foreach ([[1, 'Head of department', 'hod', null, 3], [2, 'Human resources', 'role', 'HR Officer', 3], [3, 'Registrar', 'role', 'Registrar', 3]] as [$o, $label, $who, $role, $due]) {
            DB::table('leave_approval_steps')->insert(['leave_type_id' => null, 'sort_order' => $o, 'label' => $label, 'approver' => $who, 'role_name' => $role, 'due_days' => $due, 'can_skip_if_missing' => true, 'created_at' => $now, 'updated_at' => $now]);
        }
    }

    public function down(): void
    {
        foreach (['leave_ledger', 'leave_documents', 'leave_request_steps', 'leave_requests', 'leave_approval_steps', 'leave_entitlements', 'leave_types', 'staff_profiles'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
