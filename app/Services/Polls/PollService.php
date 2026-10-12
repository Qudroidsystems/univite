<?php

namespace App\Services\Polls;

use App\Data\Polls\Ballot;
use App\Data\Polls\OptionTally;
use App\Data\Polls\PollInput;
use App\Data\Polls\PollResults;
use App\Data\Polls\QuestionResult;
use App\Models\Poll;
use App\Models\PollAnswer;
use App\Models\PollQuestion;
use App\Models\PollVoter;
use App\Models\User;
use App\Services\Campus\AudienceResolver;
use App\Services\OperationAudit;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Polls, surveys and elections: set-up, publishing, voting (once per person, secret by default) and results.
 * Questions and candidates are frozen once the first vote is cast.
 */
class PollService
{
    public function __construct(private AudienceResolver $audiences, private OperationAudit $audit)
    {
    }

    public function create(PollInput $in, User $by): Poll
    {
        $this->checkDates($in);

        return Poll::create($in->attributes() + ['status' => 'draft', 'created_by' => $by->id]);
    }

    public function update(Poll $poll, PollInput $in, User $by): Poll
    {
        if ($poll->phase() === 'closed') {
            throw new RuntimeException('Voting has closed, so this poll can no longer be changed.');
        }
        if ($poll->isLocked()) {
            // People have voted: only the description and a later closing time may change.
            if ($in->closesAt->lt($poll->closes_at)) {
                throw new RuntimeException('People have already voted, so the closing time can only be moved later.');
            }
            $before = ['closes_at' => (string) $poll->closes_at];
            $poll->update(['description' => $in->description, 'closes_at' => $in->closesAt]);
            $this->audit->note('poll_changed', 'polls', $by, 'closing_time_changed', $poll, $before, ['closes_at' => (string) $poll->closes_at]);

            return $poll;
        }
        $this->checkDates($in, $poll->status === 'draft');
        $poll->update($in->attributes());

        return $poll;
    }

    private function checkDates(PollInput $in, bool $draft = true): void
    {
        if ($in->closesAt->lte($in->opensAt)) {
            throw new RuntimeException('Voting must close after it opens.');
        }
        if (! $draft && $in->closesAt->isPast()) {
            throw new RuntimeException('The closing time has already passed.');
        }
    }

    /** @param  string[]  $options  answers, one per line (for an election the candidates can also be added one by one) */
    public function addQuestion(Poll $poll, string $prompt, string $type, ?int $maxChoices, bool $required, array $options = []): PollQuestion
    {
        $this->guardEditable($poll);

        return DB::transaction(function () use ($poll, $prompt, $type, $maxChoices, $required, $options) {
            $q = $poll->questions()->create([
                'prompt' => trim($prompt), 'type' => $poll->isElection() ? 'single' : $type, 'is_required' => $required,
                'max_choices' => $type === 'multiple' ? $maxChoices : null, 'sort_order' => (int) $poll->questions()->max('sort_order') + 1,
            ]);
            foreach (array_values(array_filter(array_map('trim', $options))) as $i => $label) {
                $q->options()->create(['label' => $label, 'sort_order' => $i + 1]);
            }

            return $q;
        });
    }

    public function updateQuestion(PollQuestion $q, string $prompt, string $type, ?int $maxChoices, bool $required): void
    {
        $this->guardEditable($q->poll);
        $q->update(['prompt' => trim($prompt), 'type' => $q->poll->isElection() ? 'single' : $type, 'max_choices' => $type === 'multiple' ? $maxChoices : null, 'is_required' => $required]);
    }

    public function deleteQuestion(PollQuestion $q): void
    {
        $this->guardEditable($q->poll);
        $q->delete();
    }

    public function addOption(PollQuestion $q, string $label, ?string $description = null, ?int $userId = null, ?string $photo = null): void
    {
        $this->guardEditable($q->poll);
        if ($userId && $q->options()->where('user_id', $userId)->exists()) {
            throw new RuntimeException('That person is already a candidate for this office.');
        }
        $q->options()->create(['label' => trim($label), 'description' => $description, 'user_id' => $userId, 'photo' => $photo, 'sort_order' => (int) $q->options()->max('sort_order') + 1]);
    }

    public function deleteOption(\App\Models\PollOption $o): void
    {
        $this->guardEditable($o->question->poll);
        $o->delete();
    }

    private function guardEditable(Poll $poll): void
    {
        if ($poll->isLocked()) {
            throw new RuntimeException('People have already voted, so the questions and candidates can no longer change.');
        }
        if ($poll->phase() === 'closed') {
            throw new RuntimeException('Voting has closed.');
        }
    }

    /** Make the poll visible to its audience; voting starts at the opening time. */
    public function publish(Poll $poll, User $by): void
    {
        $poll->load('questions.options');
        if ($poll->questions->isEmpty()) {
            throw new RuntimeException('Add at least one ' . ($poll->isElection() ? 'office' : 'question') . ' first.');
        }
        foreach ($poll->questions as $q) {
            $min = $poll->isElection() ? 1 : 2;
            if ($q->type !== 'text' && $q->options->count() < $min) {
                throw new RuntimeException('"' . Str::limit($q->prompt, 60) . '" needs at least ' . $min . ' ' . ($poll->isElection() ? 'candidate' : 'answers') . '.');
            }
        }
        if ($poll->closes_at->isPast()) {
            throw new RuntimeException('The closing time has already passed. Move it later first.');
        }
        $poll->update(['status' => 'published', 'published_by' => $by->id, 'published_at' => now()]);
        $this->audit->note('poll_published', 'polls', $by, 'published', $poll, null, ['title' => $poll->title, 'opens_at' => (string) $poll->opens_at, 'closes_at' => (string) $poll->closes_at]);
    }

    /** Take a published poll back to draft. Only possible before anyone votes. */
    public function unpublish(Poll $poll, User $by): void
    {
        if ($poll->isLocked()) {
            throw new RuntimeException('People have already voted, so the poll cannot go back to draft. Close it instead.');
        }
        $poll->update(['status' => 'draft', 'published_at' => null, 'published_by' => null]);
    }

    /** End voting now, before the closing time. */
    public function close(Poll $poll, User $by): void
    {
        if ($poll->status !== 'published') {
            throw new RuntimeException('Only a published poll can be closed.');
        }
        $poll->update(['status' => 'closed', 'closed_at' => now(), 'closes_at' => now()->min($poll->closes_at), 'eligible_count' => $this->audiences->count($poll->audienceData())]);
        $this->audit->note('poll_closed', 'polls', $by, 'closed_early', $poll, null, ['title' => $poll->title]);
    }

    public function publishResults(Poll $poll, User $by): void
    {
        if ($poll->phase() !== 'closed') {
            throw new RuntimeException('Results can be published once voting has closed.');
        }
        $poll->update(['results_published_at' => now()]);
        $this->audit->note('poll_results', 'polls', $by, 'results_published', $poll, null, ['title' => $poll->title]);
    }

    public function isEligible(Poll $poll, User $user): bool
    {
        return $this->audiences->includes($poll->audienceData(), $user);
    }

    public function hasVoted(Poll $poll, User $user): bool
    {
        return PollVoter::where('poll_id', $poll->id)->where('user_id', $user->id)->exists();
    }

    /** Why this person cannot vote now, or null if they can. */
    public function cannotVote(Poll $poll, User $user): ?string
    {
        return match (true) {
            $poll->phase() === 'draft' => 'This poll has not been published.',
            $poll->phase() === 'scheduled' => 'Voting opens ' . $poll->opens_at->format('l j F, g:ia') . '.',
            $poll->phase() === 'closed' => 'Voting has closed.',
            $this->hasVoted($poll, $user) => 'You have already voted.',
            ! $this->isEligible($poll, $user) => 'This poll is not open to you.',
            default => null,
        };
    }

    /** Cast one person's ballot. Each person votes once; a secret ballot does not record who chose what. */
    public function vote(Poll $poll, User $user, Ballot $ballot): void
    {
        if ($why = $this->cannotVote($poll, $user)) {
            throw new RuntimeException($why);
        }
        $poll->load('questions.options');
        $rows = [];
        $token = Str::random(32);
        foreach ($poll->questions as $q) {
            $picked = $ballot->choices[$q->id] ?? [];
            $text = $ballot->texts[$q->id] ?? null;
            if ($q->type === 'text') {
                if ($q->is_required && $text === null) {
                    throw new RuntimeException('Answer "' . Str::limit($q->prompt, 60) . '".');
                }
                if ($text !== null) {
                    $rows[] = ['poll_question_id' => $q->id, 'poll_option_id' => null, 'text' => Str::limit($text, 2000, '')];
                }
                continue;
            }
            $valid = $q->options->pluck('id')->all();
            if (array_diff($picked, $valid)) {
                throw new RuntimeException('One of the answers chosen does not belong to "' . Str::limit($q->prompt, 60) . '".');
            }
            if ($q->is_required && ! $picked) {
                throw new RuntimeException(($poll->isElection() ? 'Choose a candidate for "' : 'Answer "') . Str::limit($q->prompt, 60) . '".');
            }
            $max = $q->type === 'single' ? 1 : ($q->max_choices ?: count($valid));
            if (count($picked) > $max) {
                throw new RuntimeException('Choose at most ' . $max . ' for "' . Str::limit($q->prompt, 60) . '".');
            }
            foreach ($picked as $optionId) {
                $rows[] = ['poll_question_id' => $q->id, 'poll_option_id' => $optionId, 'text' => null];
            }
        }

        try {
            DB::transaction(function () use ($poll, $user, $rows, $token) {
                PollVoter::create(['poll_id' => $poll->id, 'user_id' => $user->id, 'voted_at' => now()]);
                foreach ($rows as $r) {
                    PollAnswer::create($r + ['poll_id' => $poll->id, 'ballot' => $token, 'user_id' => $poll->is_secret ? null : $user->id]);
                }
            });
        } catch (QueryException) {
            throw new RuntimeException('You have already voted.');
        }
    }

    public function results(Poll $poll): PollResults
    {
        $poll->load('questions.options');
        if ($poll->phase() === 'closed' && $poll->eligible_count === null) {
            $poll->update(['eligible_count' => $this->audiences->count($poll->audienceData())]);
        }
        $eligible = $poll->eligible_count ?? $this->audiences->count($poll->audienceData());
        $voted = $poll->voters()->count();
        $answers = PollAnswer::where('poll_id', $poll->id)->get(['poll_question_id', 'poll_option_id', 'text', 'ballot'])->groupBy('poll_question_id');

        $questions = [];
        foreach ($poll->questions as $q) {
            $rows = $answers->get($q->id, collect());
            $ballots = $rows->pluck('ballot')->unique()->count();
            $counts = $rows->whereNotNull('poll_option_id')->countBy('poll_option_id');
            $top = $counts->max() ?? 0;
            $options = $q->options->map(fn ($o) => new OptionTally(
                $o->id, $o->label, (int) ($counts[$o->id] ?? 0), $ballots ? round(($counts[$o->id] ?? 0) / $ballots * 100, 1) : 0.0,
                $top > 0 && ($counts[$o->id] ?? 0) === $top, $o->photo_url,
            ))->sortByDesc('votes')->values()->all();
            $questions[] = new QuestionResult(
                $q->id, $q->prompt, $q->type, $ballots, max(0, $voted - $ballots), $options,
                $rows->whereNotNull('text')->pluck('text')->values()->all(), $top > 0 && $counts->filter(fn ($n) => $n === $top)->count() > 1,
            );
        }

        return new PollResults($eligible, $voted, $questions);
    }

    /** Published polls this person may take part in that are open or coming up, newest first, with whether they voted. */
    public function forUser(User $user): Collection
    {
        $voted = PollVoter::where('user_id', $user->id)->pluck('poll_id')->all();

        return Poll::where('status', '!=', 'draft')->where(fn ($q) => $q->where('closes_at', '>', now()->subDays(60)))->latest('opens_at')->get()
            ->filter(fn (Poll $p) => in_array($p->id, $voted, true) || $this->isEligible($p, $user))
            ->each(fn (Poll $p) => $p->setAttribute('has_voted', in_array($p->id, $voted, true)))
            ->values();
    }

    /** How many open polls this person has not voted in yet (for the menu badge). */
    public function waiting(User $user): int
    {
        return Poll::where('status', 'published')->where('opens_at', '<=', now())->where('closes_at', '>', now())
            ->whereDoesntHave('voters', fn ($q) => $q->where('user_id', $user->id))->get()
            ->filter(fn (Poll $p) => $this->isEligible($p, $user))->count();
    }
}
