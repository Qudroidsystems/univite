<?php

namespace App\Http\Controllers\Polls;

use App\Data\Polls\Ballot;
use App\Http\Controllers\Controller;
use App\Models\Poll;
use App\Services\Polls\PollService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

/** Polls and elections a student or member of staff can take part in. No permission is needed: the poll's audience decides. */
class MyPollController extends Controller
{
    public function __construct(private PollService $polls)
    {
    }

    public function index(Request $request): View
    {
        return view('polls.mine', ['pagetitle' => 'Polls & Elections', 'polls' => $this->polls->forUser($request->user())]);
    }

    public function show(Request $request, Poll $poll): View
    {
        $user = $request->user();
        $voted = $this->polls->hasVoted($poll, $user);
        abort_unless($poll->status !== 'draft' && ($voted || $this->polls->isEligible($poll, $user)), 404);
        $poll->load('questions.options');

        return view('polls.ballot', [
            'pagetitle' => $poll->title, 'poll' => $poll, 'voted' => $voted, 'why' => $this->polls->cannotVote($poll, $user),
            'results' => $poll->resultsVisible() ? $this->polls->results($poll) : null,
        ]);
    }

    public function vote(Request $request, Poll $poll): RedirectResponse
    {
        $d = $request->validate(['answers' => ['nullable', 'array'], 'confirm' => ['accepted']], ['confirm.accepted' => 'Tick the box to confirm your choices. A vote cannot be changed once cast.']);
        try {
            $this->polls->vote($poll, $request->user(), Ballot::fromArray($d['answers'] ?? []));
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('my.polls.show', $poll)->with('success', 'Thank you. Your vote has been counted' . ($poll->is_secret ? ' and nobody can see how you voted.' : '.'));
    }
}
