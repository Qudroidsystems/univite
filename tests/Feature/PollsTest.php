<?php

use App\Models\Poll;
use App\Models\PollAnswer;
use App\Models\PollVoter;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow('2026-11-02 09:00:00');
    campusWorld($this);
    $this->ada = makeStudent($this, 'Ada', 'female', 300);
    $this->bayo = makeStudent($this, 'Bayo', 'male', 100);
    $this->chem = makeStudent($this, 'Chidi', 'male', 300, $this->prog2);
    $this->officer = makeStaff($this, 'Polls Officer', null, ['View polls', 'Create polls', 'Publish polls', 'Delete polls']);
});

afterEach(fn () => Carbon::setTestNow());

function electionFor($test, array $audience = ['who' => 'students', 'faculties' => []]): Poll
{
    $test->actingAs($test->officer)->post(route('polls.store'), [
        'title' => 'Students\' Union election', 'kind' => 'election', 'is_secret' => 1, 'results_shown' => 'after_close',
        'opens_at' => '2026-11-02 08:00', 'closes_at' => '2026-11-03 17:00', 'audience' => $audience,
    ])->assertRedirect();
    $poll = Poll::latest('id')->first();
    $test->post(route('polls.questions.store', $poll), ['prompt' => 'President', 'is_required' => 1])->assertSessionHas('success');
    $q = $poll->questions()->first();
    $test->post(route('polls.options.store', $q), ['person' => $test->ada->matric_number])->assertSessionHas('success');
    $test->post(route('polls.options.store', $q), ['label' => 'Tunde Candidate'])->assertSessionHas('success');

    return $poll->fresh();
}

it('runs a secret-ballot election: one vote each, nothing ties a ballot to a voter, results after closing', function () {
    $poll = electionFor($this);
    expect($poll->status)->toBe('draft');
    $this->post(route('polls.publish', $poll))->assertSessionHas('success');
    $q = $poll->questions()->first();
    [$adaOption, $tunde] = $q->options()->get()->all();
    expect($adaOption->label)->toBe('Student Ada')->and($adaOption->user_id)->toBe($this->ada->user_id);

    $this->actingAs($this->bayo->user)->get(route('my.polls'))->assertOk()->assertSee('Students&#039; Union election', false);
    $this->post(route('my.polls.vote', $poll), ['answers' => [$q->id => $tunde->id]])->assertSessionHasErrors('confirm');
    $this->post(route('my.polls.vote', $poll), ['answers' => [$q->id => $tunde->id], 'confirm' => 1])->assertSessionHas('success');
    $this->post(route('my.polls.vote', $poll), ['answers' => [$q->id => $adaOption->id], 'confirm' => 1])->assertSessionHas('error', 'You have already voted.');
    $this->actingAs($this->ada->user)->post(route('my.polls.vote', $poll), ['answers' => [$q->id => $adaOption->id], 'confirm' => 1])->assertSessionHas('success');
    $this->actingAs($this->chem->user)->post(route('my.polls.vote', $poll), ['answers' => [$q->id => $tunde->id], 'confirm' => 1])->assertSessionHas('success');

    expect(PollVoter::where('poll_id', $poll->id)->count())->toBe(3)
        ->and(PollAnswer::where('poll_id', $poll->id)->whereNotNull('user_id')->count())->toBe(0)
        ->and(PollAnswer::where('poll_id', $poll->id)->pluck('ballot')->unique()->count())->toBe(3);

    // Results are hidden from voters until voting closes; questions are frozen once voting started.
    $this->actingAs($this->bayo->user)->get(route('my.polls.show', $poll))->assertOk()->assertSee('You have voted')->assertDontSee('Turnout');
    $this->actingAs($this->officer)->post(route('polls.options.store', $q), ['label' => 'Late Entrant'])->assertSessionHas('error');
    $this->post(route('polls.close', $poll))->assertSessionHas('success');
    $this->actingAs($this->bayo->user)->get(route('my.polls.show', $poll))->assertOk()->assertSee('Turnout')->assertSee('Tunde Candidate');

    $results = app(\App\Services\Polls\PollService::class)->results($poll->fresh());
    expect($results->eligible)->toBe(3)->and($results->voted)->toBe(3)
        ->and($results->questions[0]->options[0]->label)->toBe('Tunde Candidate')
        ->and($results->questions[0]->options[0]->votes)->toBe(2)
        ->and($results->questions[0]->tie)->toBeFalse();
});

it('only lets the poll\'s audience vote, and only while it is open', function () {
    $poll = electionFor($this, ['who' => 'students', 'faculties' => [$this->faculty->id], 'levels' => [300]]);
    $q = $poll->questions()->first();
    $this->post(route('polls.publish', $poll));
    $option = $q->options()->first()->id;

    $this->actingAs($this->bayo->user)->post(route('my.polls.vote', $poll), ['answers' => [$q->id => $option], 'confirm' => 1])->assertSessionHas('error', 'This poll is not open to you.');
    $this->actingAs($this->chem->user)->get(route('my.polls.show', $poll))->assertNotFound();
    $this->actingAs($this->officer)->post(route('my.polls.vote', $poll), ['answers' => [$q->id => $option], 'confirm' => 1])->assertSessionHas('error', 'This poll is not open to you.');

    Carbon::setTestNow('2026-11-04 09:00:00');
    $this->actingAs($this->ada->user)->post(route('my.polls.vote', $poll), ['answers' => [$q->id => $option], 'confirm' => 1])->assertSessionHas('error', 'Voting has closed.');
    expect(app(\App\Services\Polls\PollService::class)->results($poll->fresh())->eligible)->toBe(1);
});

it('runs a survey with several-choice and written answers, and records voters on an open ballot', function () {
    $this->actingAs($this->officer)->post(route('polls.store'), [
        'title' => 'Library survey', 'kind' => 'poll', 'is_secret' => 0, 'results_shown' => 'live',
        'opens_at' => '2026-11-01 08:00', 'closes_at' => '2026-11-10 17:00', 'audience' => ['who' => 'everyone'],
    ]);
    $poll = Poll::latest('id')->first();
    $this->post(route('polls.questions.store', $poll), ['prompt' => 'Which services do you use?', 'type' => 'multiple', 'max_choices' => 2, 'is_required' => 1, 'options' => "Reading room\nE-library\nPhotocopying"]);
    $this->post(route('polls.questions.store', $poll), ['prompt' => 'Anything else?', 'type' => 'text', 'is_required' => 0]);
    $this->post(route('polls.publish', $poll))->assertSessionHas('success');
    [$multi, $text] = $poll->questions()->get()->all();
    $ids = $multi->options()->pluck('id')->all();

    $this->actingAs($this->ada->user)->post(route('my.polls.vote', $poll), ['answers' => [$multi->id => $ids], 'confirm' => 1])->assertSessionHas('error', 'Choose at most 2 for "Which services do you use?".');
    $this->post(route('my.polls.vote', $poll), ['answers' => [$multi->id => [$ids[0], $ids[1]], $text->id => 'Longer hours please'], 'confirm' => 1])->assertSessionHas('success');
    $this->actingAs($this->officer)->post(route('my.polls.vote', $poll), ['answers' => [$multi->id => [$ids[1]]], 'confirm' => 1])->assertSessionHas('success');

    expect(PollAnswer::where('poll_id', $poll->id)->whereNotNull('user_id')->distinct()->count('user_id'))->toBe(2);
    $this->actingAs($this->ada->user)->get(route('my.polls.show', $poll))->assertSee('Votes so far')->assertSee('E-library');
    $this->actingAs($this->officer)->get(route('polls.export', $poll))->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
    expect($this->get(route('polls.export', $poll))->streamedContent())->toContain('Longer hours please');
});

it('keeps polls with votes and lets only poll officers manage polls', function () {
    $poll = electionFor($this);
    $this->actingAs($this->ada->user)->get(route('polls.index'))->assertForbidden();
    $this->actingAs($this->ada->user)->post(route('polls.publish', $poll))->assertForbidden();
    $this->actingAs($this->officer)->post(route('polls.publish', $poll));
    $q = $poll->questions()->first();
    $this->actingAs($this->ada->user)->post(route('my.polls.vote', $poll), ['answers' => [$q->id => $q->options()->first()->id], 'confirm' => 1]);
    $this->actingAs($this->officer)->delete(route('polls.destroy', $poll))->assertSessionHas('error');
    $this->post(route('polls.unpublish', $poll))->assertSessionHas('error');
    expect(Poll::count())->toBe(1);
    $this->get(route('polls.show', $poll))->assertOk()->assertSee('Votes so far');
});
