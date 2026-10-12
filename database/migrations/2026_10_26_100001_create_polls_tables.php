<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Polls, surveys and elections.
 *
 * - polls: what it is, who may take part (an Audience), when voting opens and closes, whether ballots are secret and
 *   when results are shown.
 * - poll_questions: a question, or for an election one office (President, Secretary ...).
 * - poll_options: the answers, or the candidates for an office (optionally linked to their account, with a photo).
 * - poll_voters: who has voted (one row each) -- kept apart from the ballots so a secret ballot cannot be traced.
 * - poll_answers: the ballots. user_id is only filled in for polls that are not secret.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('polls', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('kind', 10)->default('poll');                     // poll | election
            $table->json('audience');
            $table->dateTime('opens_at');
            $table->dateTime('closes_at');
            $table->boolean('is_secret')->default(true);
            $table->string('results_shown', 12)->default('after_close');     // live | after_close | on_publish
            $table->string('status', 12)->default('draft');                  // draft | published | closed
            $table->timestamp('results_published_at')->nullable();
            $table->unsignedInteger('eligible_count')->nullable();           // frozen when the poll closes
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'opens_at', 'closes_at']);
        });

        Schema::create('poll_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('poll_id')->constrained()->cascadeOnDelete();
            $table->string('prompt', 500);
            $table->string('type', 10)->default('single');                   // single | multiple | text
            $table->unsignedTinyInteger('max_choices')->nullable();          // for "multiple"
            $table->boolean('is_required')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('poll_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('poll_question_id')->constrained()->cascadeOnDelete();
            $table->string('label');
            $table->text('description')->nullable();                         // a candidate's manifesto summary
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('photo')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('poll_voters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('poll_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('voted_at');
            $table->unique(['poll_id', 'user_id']);
        });

        // Random (not sequential) keys and no timestamps, so the order of ballots cannot be matched to the order of voters.
        Schema::create('poll_answers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('poll_id')->constrained()->cascadeOnDelete();
            $table->foreignId('poll_question_id')->constrained()->cascadeOnDelete();
            $table->foreignId('poll_option_id')->nullable()->constrained()->cascadeOnDelete();
            $table->text('text')->nullable();
            $table->string('ballot', 40);                                   // groups one person's answers without naming them
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->index(['poll_id', 'poll_question_id']);
        });
    }

    public function down(): void
    {
        foreach (['poll_answers', 'poll_voters', 'poll_options', 'poll_questions', 'polls'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
