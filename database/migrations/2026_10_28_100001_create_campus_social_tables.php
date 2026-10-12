<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The mobile app's campus life: chat (direct, department, course and study groups), the marketplace and lost & found,
 * the campus feed and events, course materials, reports and blocks, and students sharing where they live.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ---- Chat ------------------------------------------------------------------------------------------
        Schema::create('chat_conversations', function (Blueprint $table) {
            $table->id();
            $table->string('type', 20);                       // direct | department | course | study | group
            $table->string('title', 120)->nullable();
            $table->string('description', 500)->nullable();
            $table->string('direct_key', 50)->nullable()->unique();   // "12-40" for a direct chat between users 12 and 40
            $table->foreignId('department_id')->nullable()->constrained('departments')->cascadeOnDelete();
            $table->foreignId('course_id')->nullable()->constrained('courses')->cascadeOnDelete();
            $table->foreignId('academic_session_id')->nullable()->constrained('academic_sessions')->cascadeOnDelete();
            $table->foreignId('semester_type_id')->nullable()->constrained('semester_types')->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_open')->default(false);       // study groups: anyone taking the course may join
            $table->boolean('only_admins_post')->default(false);
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();

            $table->index(['type', 'department_id']);
            $table->index(['type', 'course_id', 'academic_session_id', 'semester_type_id'], 'chat_course_group');
        });

        Schema::create('chat_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chat_conversation_id')->constrained('chat_conversations')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('role', 10)->default('member');    // member | admin
            $table->unsignedBigInteger('last_read_message_id')->default(0);
            $table->boolean('muted')->default(false);
            $table->boolean('auto')->default(false);          // added by the portal (department / course), not by a person
            $table->timestamps();

            $table->unique(['chat_conversation_id', 'user_id']);
            $table->index('user_id');
        });

        Schema::create('chat_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chat_conversation_id')->constrained('chat_conversations')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('kind', 12)->default('text');      // text | image | file | listing | system
            $table->text('body')->nullable();
            $table->string('attachment_path')->nullable();
            $table->string('attachment_name')->nullable();
            $table->unsignedInteger('attachment_size')->nullable();
            $table->json('meta')->nullable();
            $table->foreignId('reply_to_id')->nullable()->constrained('chat_messages')->nullOnDelete();
            $table->softDeletes();
            $table->timestamps();

            $table->index(['chat_conversation_id', 'id']);
        });

        Schema::create('user_blocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('blocked_user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'blocked_user_id']);
        });

        // ---- Marketplace and lost & found -------------------------------------------------------------------
        Schema::create('market_listings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('kind', 10)->default('sale');      // sale | wanted | lost | found
            $table->string('title', 120);
            $table->text('description')->nullable();
            $table->string('category', 30)->nullable();
            $table->decimal('price', 12, 2)->nullable();
            $table->boolean('negotiable')->default(false);
            $table->string('condition', 20)->nullable();      // new | like_new | good | fair
            $table->string('location', 150)->nullable();      // where on campus to meet / where it was lost or found
            $table->date('happened_on')->nullable();          // lost & found: the day
            $table->string('status', 12)->default('active');  // pending | active | sold | closed | hidden | rejected
            $table->string('moderation_note', 500)->nullable();
            $table->foreignId('moderated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('views')->default(0);
            $table->timestamp('bumped_at')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->index(['kind', 'status', 'bumped_at']);
        });

        Schema::create('market_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('market_listing_id')->constrained('market_listings')->cascadeOnDelete();
            $table->string('path');
            $table->unsignedTinyInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('market_saves', function (Blueprint $table) {
            $table->foreignId('market_listing_id')->constrained('market_listings')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->primary(['market_listing_id', 'user_id']);
        });

        // ---- Campus feed and events -------------------------------------------------------------------------
        Schema::create('feed_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('audience', 12)->default('everyone');   // everyone | students | staff | faculty | department
            $table->unsignedBigInteger('audience_id')->nullable(); // the faculty or department
            $table->string('title', 150)->nullable();
            $table->text('body');
            $table->string('image_path')->nullable();
            $table->boolean('is_event')->default(false);
            $table->dateTime('starts_at')->nullable();
            $table->dateTime('ends_at')->nullable();
            $table->string('venue', 150)->nullable();
            $table->boolean('pinned')->default(false);
            $table->boolean('allow_comments')->default(true);
            $table->string('status', 10)->default('published');    // published | hidden
            $table->softDeletes();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['is_event', 'starts_at']);
        });

        Schema::create('feed_likes', function (Blueprint $table) {
            $table->foreignId('feed_post_id')->constrained('feed_posts')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->primary(['feed_post_id', 'user_id']);
        });

        Schema::create('feed_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('feed_post_id')->constrained('feed_posts')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('body');
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('event_rsvps', function (Blueprint $table) {
            $table->foreignId('feed_post_id')->constrained('feed_posts')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('status', 10)->default('going');        // going | interested
            $table->timestamps();
            $table->primary(['feed_post_id', 'user_id']);
        });

        // ---- Course materials --------------------------------------------------------------------------------
        Schema::create('course_materials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained('courses')->cascadeOnDelete();
            $table->foreignId('academic_session_id')->nullable()->constrained('academic_sessions')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title', 150);
            $table->string('description', 1000)->nullable();
            $table->string('path')->nullable();
            $table->string('link', 500)->nullable();               // or a link (YouTube, Drive ...)
            $table->string('original_name')->nullable();
            $table->string('mime', 120)->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->unsignedInteger('downloads')->default(0);
            $table->softDeletes();
            $table->timestamps();

            $table->index(['course_id', 'created_at']);
        });

        // ---- Reports (chat messages, listings, posts, comments, people) ---------------------------------------
        Schema::create('content_reports', function (Blueprint $table) {
            $table->id();
            $table->string('reportable_type', 40);
            $table->unsignedBigInteger('reportable_id');
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason', 30);
            $table->string('details', 1000)->nullable();
            $table->string('status', 12)->default('open');         // open | dismissed | actioned
            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('handled_at')->nullable();
            $table->string('outcome', 500)->nullable();
            $table->timestamps();

            $table->index(['reportable_type', 'reportable_id']);
            $table->index('status');
        });

        // ---- People: where they live, a short line about themselves ------------------------------------------
        Schema::table('users', function (Blueprint $table) {
            $table->string('share_lodging', 12)->default('nobody')->after('notify_push');   // nobody | mates | everyone
            $table->string('lodging_note', 200)->nullable()->after('share_lodging');      // off-campus address or extra detail
            $table->string('bio', 160)->nullable()->after('lodging_note');
            $table->timestamp('last_seen_at')->nullable()->after('bio');
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['share_lodging', 'lodging_note', 'bio', 'last_seen_at']));
        foreach (['content_reports', 'course_materials', 'event_rsvps', 'feed_comments', 'feed_likes', 'feed_posts', 'market_saves', 'market_photos',
            'market_listings', 'user_blocks', 'chat_messages', 'chat_members', 'chat_conversations'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
