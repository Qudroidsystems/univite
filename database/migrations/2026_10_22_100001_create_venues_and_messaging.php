<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The venue register (lecture halls, classrooms, laboratories, examination halls ...) that both timetables read from,
 * closures and fault reports that block a venue, ad hoc bookings, the holiday calendar shared by leave and timetables,
 * and the outbound message log for SMS, WhatsApp and in-portal notices.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('venues', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name');
            $table->string('type', 40);                                  // lookup list venue_type
            $table->string('campus')->nullable();
            $table->string('building')->nullable();
            $table->string('floor', 40)->nullable();
            $table->string('room_number', 40)->nullable();
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete(); // owning / preferred department
            $table->unsignedInteger('capacity')->default(0);             // installed seats
            $table->unsignedInteger('teaching_capacity')->default(0);    // usable for lectures
            $table->unsignedInteger('exam_capacity')->default(0);        // usable for examinations after spacing
            $table->unsignedSmallInteger('seat_rows')->nullable();       // examination seat map
            $table->unsignedSmallInteger('seats_per_row')->nullable();
            $table->json('blocked_seats')->nullable();                   // ["R01-S03", ...] never used
            $table->json('accessible_seats')->nullable();                // reserved for candidates who need them
            $table->json('uses')->nullable();                            // lecture, practical, exam, meeting
            $table->json('facilities')->nullable();                      // lookup list venue_facility
            $table->boolean('shared_exams')->default(true);              // several courses may write here at once
            $table->boolean('wheelchair_access')->default(false);
            $table->string('entry_info')->nullable();                    // "North entrance"
            $table->string('status', 20)->default('available');          // available | reserved | maintenance | closed
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('venue_closures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('venue_id')->constrained()->cascadeOnDelete();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('reason', 500);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('lifted_at')->nullable();
            $table->foreignId('lifted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('venue_issues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('venue_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('details')->nullable();
            $table->string('severity', 12)->default('minor');    // minor | major | blocking
            $table->string('status', 12)->default('open');       // open | in_progress | resolved
            $table->foreignId('reported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->string('resolution', 500)->nullable();
            $table->date('inspected_on')->nullable();
            $table->timestamps();
        });

        Schema::create('venue_bookings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('venue_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->date('date');
            $table->time('starts_at');
            $table->time('ends_at');
            $table->unsignedInteger('attendees')->nullable();
            $table->foreignId('booked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 12)->default('confirmed');  // confirmed | cancelled
            $table->string('note', 500)->nullable();
            $table->timestamps();
            $table->index(['venue_id', 'date']);
        });

        Schema::create('holidays', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->string('name');
            $table->boolean('recurring')->default(false);   // same day every year
            $table->timestamps();
        });

        Schema::create('outbound_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('channel', 12);                 // sms | whatsapp
            $table->string('recipient', 40);
            $table->text('body');
            $table->string('topic', 40)->nullable();        // leave, timetable, seating ...
            $table->string('subject_type', 60)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('dedupe_key', 191)->nullable()->unique();
            $table->string('status', 12)->default('queued'); // queued | sent | failed | logged
            $table->string('provider', 30)->nullable();
            $table->string('provider_ref', 120)->nullable();
            $table->string('error', 500)->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'attempts']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('whatsapp_number', 30)->nullable()->after('phone_number');
            $table->boolean('notify_sms')->default(true)->after('whatsapp_number');
            $table->boolean('notify_whatsapp')->default(true)->after('notify_sms');
        });

        // Portal notices (the bell in the top bar) use Laravel's database notifications.
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

        \App\Support\ReferenceData::seed();
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['whatsapp_number', 'notify_sms', 'notify_whatsapp']));
        foreach (['notifications', 'outbound_messages', 'holidays', 'venue_bookings', 'venue_issues', 'venue_closures', 'venues'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
