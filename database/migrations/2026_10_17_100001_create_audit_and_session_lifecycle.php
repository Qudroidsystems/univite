<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per sensitive operation (a clone, a matric batch, a publish ...). Its events are the append-only trail.
        Schema::create('operation_batches', function (Blueprint $table) {
            $table->id();
            $table->string('operation_id', 20)->unique();
            $table->string('type', 40);                         // session_clone | session_clone_undo | matric_generate | ...
            $table->string('module', 30);
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('academic_session_id')->nullable()->index();
            $table->string('status', 20)->default('completed'); // completed | failed | partial | reversed
            $table->unsignedInteger('success_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);
            $table->string('reason', 500)->nullable();
            $table->unsignedBigInteger('related_operation_id')->nullable()->index(); // the operation this one reverses or follows up
            $table->json('meta')->nullable();                   // options chosen, never secrets
            $table->timestamps();
        });

        // Append-only: corrections are new events that point at the old one, never edits.
        Schema::create('operation_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('operation_batch_id')->constrained('operation_batches')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 40);
            $table->string('subject_type', 60)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->string('note', 500)->nullable();
            $table->unsignedBigInteger('corrects_event_id')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['subject_type', 'subject_id']);
        });

        Schema::table('academic_sessions', function (Blueprint $table) {
            $table->string('status', 12)->default('draft')->after('is_current'); // draft | setup | active | closing | closed | archived
        });
        DB::table('academic_sessions')->where('is_current', true)->update(['status' => 'active']);
        DB::table('academic_sessions')->where('is_current', false)->update(['status' => 'closed']);

        Schema::create('session_state_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('academic_session_id')->constrained('academic_sessions')->cascadeOnDelete();
            $table->string('from_status', 12)->nullable();
            $table->string('to_status', 12);
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason', 500)->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::table('semesters', function (Blueprint $table) {
            $table->dateTime('registration_opens_at')->nullable()->after('end_date');
            $table->dateTime('registration_closes_at')->nullable()->after('registration_opens_at');
        });

        // What a clone created, so it can be repeated safely and undone.
        Schema::create('clone_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('operation_batch_id')->constrained('operation_batches')->cascadeOnDelete();
            $table->string('category', 30);
            $table->unsignedBigInteger('source_id')->nullable();
            $table->unsignedBigInteger('dest_id')->nullable();
            $table->string('fingerprint', 64)->nullable();
            $table->json('before')->nullable();                  // values overwritten (e.g. semester windows) so undo can restore them
            $table->boolean('undone')->default(false);
            $table->timestamps();

            $table->index(['category', 'source_id', 'dest_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clone_items');
        Schema::table('semesters', fn (Blueprint $t) => $t->dropColumn(['registration_opens_at', 'registration_closes_at']));
        Schema::dropIfExists('session_state_changes');
        Schema::table('academic_sessions', fn (Blueprint $t) => $t->dropColumn('status'));
        Schema::dropIfExists('operation_events');
        Schema::dropIfExists('operation_batches');
    }
};
