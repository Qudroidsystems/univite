<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * - document_emails: every receipt, course form, slip or notice the portal emails, with whether it went out.
 * - payments: a reversal is a new payment row that points at the one it reverses; the original keeps its amount
 *   and is only marked reversed.
 * - operation_events.label: a readable name for the affected record (the student's name and matric number ...).
 * - existing successful payments get a "payment recorded" operation so they can be reversed the same way as new ones.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_emails', function (Blueprint $table) {
            $table->id();
            $table->string('type', 30);
            $table->string('subject_type', 60)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('recipient', 190);
            $table->string('subject', 255);
            $table->string('status', 12);                 // sent | failed
            $table->string('error', 500)->nullable();
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['subject_type', 'subject_id']);
            $table->index('type');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->unsignedBigInteger('reverses_payment_id')->nullable()->index()->after('payload');
            $table->timestamp('reversed_at')->nullable()->after('reverses_payment_id');
            $table->foreignId('reversed_by')->nullable()->after('reversed_at')->constrained('users')->nullOnDelete();
            $table->string('reversal_reason', 500)->nullable()->after('reversed_by');
        });

        Schema::table('operation_events', function (Blueprint $table) {
            $table->string('label', 190)->nullable()->after('subject_id');
        });

        // Payments recorded before the operation log existed.
        foreach (DB::table('payments')->where('status', 'successful')->orderBy('id')->get() as $p) {
            $invoice = DB::table('invoices')->find($p->invoice_id);
            do {
                $opId = 'OP-' . strtoupper(Str::random(8));
            } while (DB::table('operation_batches')->where('operation_id', $opId)->exists());
            $at = $p->paid_at ?? $p->created_at ?? now();
            $batchId = DB::table('operation_batches')->insertGetId([
                'operation_id' => $opId, 'type' => 'payment_record', 'module' => 'payments', 'user_id' => $p->recorded_by,
                'academic_session_id' => $invoice?->academic_session_id, 'status' => 'completed', 'success_count' => 1,
                'failed_count' => 0, 'skipped_count' => 0, 'reason' => null,
                'meta' => json_encode(['backfilled' => true, 'note' => 'Recorded before the operation log existed.']),
                'created_at' => $at, 'updated_at' => $at,
            ]);
            DB::table('operation_events')->insert([
                'operation_batch_id' => $batchId, 'user_id' => $p->recorded_by, 'action' => 'payment_recorded',
                'subject_type' => 'Payment', 'subject_id' => $p->id, 'label' => $p->reference . ' · ₦' . number_format((float) $p->amount, 2),
                'before' => null, 'after' => json_encode(['amount' => (string) $p->amount, 'status' => 'successful', 'invoice' => $invoice?->reference]),
                'note' => null, 'created_at' => $at,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('operation_events', fn (Blueprint $t) => $t->dropColumn('label'));
        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reversed_by');
            $table->dropColumn(['reverses_payment_id', 'reversed_at', 'reversal_reason']);
        });
        Schema::dropIfExists('document_emails');
    }
};
