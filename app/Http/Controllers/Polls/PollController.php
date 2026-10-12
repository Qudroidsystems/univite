<?php

namespace App\Http\Controllers\Polls;

use App\Data\Campus\Audience;
use App\Data\Polls\PollInput;
use App\Http\Controllers\Controller;
use App\Models\Poll;
use App\Models\PollOption;
use App\Models\PollQuestion;
use App\Models\User;
use App\Services\Campus\AudienceResolver;
use App\Services\Campus\PersonFinder;
use App\Services\Polls\PollService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Setting up polls, surveys and elections, publishing them and reading the results. */
class PollController extends Controller
{
    public function __construct(private PollService $polls, private AudienceResolver $audiences, private PersonFinder $people)
    {
        $this->middleware('permission:View polls|Create polls|Publish polls')->only('index', 'show', 'export');
        $this->middleware('permission:Create polls')->only('create', 'store', 'edit', 'update', 'storeQuestion', 'updateQuestion', 'destroyQuestion', 'storeOption', 'destroyOption');
        $this->middleware('permission:Publish polls')->only('publish', 'unpublish', 'close', 'publishResults');
        $this->middleware('permission:Delete polls')->only('destroy');
    }

    public function index(Request $request): View
    {
        $polls = Poll::withCount('voters')->when($request->query('kind'), fn ($q, $k) => $q->where('kind', $k))
            ->when(trim((string) $request->query('q')), fn ($q, $t) => $q->where('title', 'like', "%{$t}%"))
            ->latest('opens_at')->paginate(20)->withQueryString();

        return view('polls.index', ['pagetitle' => 'Polls & Elections', 'polls' => $polls]);
    }

    public function create(): View
    {
        $now = now()->addDay()->startOfDay()->setHour(8);

        return view('polls.form', ['pagetitle' => 'New poll', 'poll' => new Poll(['kind' => 'poll', 'is_secret' => true, 'results_shown' => 'after_close', 'opens_at' => $now, 'closes_at' => $now->copy()->addDays(2)->setHour(17)])]);
    }

    private function input(Request $request): PollInput
    {
        $d = $request->validate([
            'title' => ['required', 'string', 'max:200'], 'description' => ['nullable', 'string', 'max:5000'],
            'kind' => ['required', 'in:' . implode(',', array_keys(Poll::KINDS))],
            'opens_at' => ['required', 'date'], 'closes_at' => ['required', 'date'],
            'is_secret' => ['nullable', 'boolean'], 'results_shown' => ['required', 'in:' . implode(',', array_keys(Poll::RESULTS))],
        ] + Audience::rules());

        return PollInput::fromArray($d);
    }

    public function store(Request $request): RedirectResponse
    {
        try {
            $poll = $this->polls->create($this->input($request), $request->user());
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('polls.show', $poll)->with('success', 'Saved as a draft. Now add the ' . ($poll->isElection() ? 'offices and candidates.' : 'questions.'));
    }

    public function edit(Poll $poll): View
    {
        return view('polls.form', ['pagetitle' => 'Edit ' . $poll->title, 'poll' => $poll]);
    }

    public function update(Request $request, Poll $poll): RedirectResponse
    {
        try {
            $this->polls->update($poll, $this->input($request), $request->user());
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('polls.show', $poll)->with('success', 'Saved.');
    }

    public function show(Poll $poll): View
    {
        $poll->load('questions.options.user', 'creator');

        return view('polls.show', [
            'pagetitle' => $poll->title, 'poll' => $poll, 'locked' => $poll->isLocked(),
            'audience' => $this->audiences->describe($poll->audienceData()),
            'results' => $poll->phase() === 'draft' ? null : $this->polls->results($poll),
        ]);
    }

    public function destroy(Poll $poll): RedirectResponse
    {
        if ($poll->isLocked()) {
            return back()->with('error', 'People have voted in this poll, so it is kept as a record. Close it instead.');
        }
        $poll->delete();

        return redirect()->route('polls.index')->with('success', 'Poll deleted.');
    }

    private function act(callable $fn, string $ok): RedirectResponse
    {
        try {
            $fn();
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return back()->with('success', $ok);
    }

    public function publish(Request $request, Poll $poll): RedirectResponse
    {
        return $this->act(fn () => $this->polls->publish($poll, $request->user()), 'Published. Voting opens ' . $poll->opens_at->format('j M Y, g:ia') . '.');
    }

    public function unpublish(Request $request, Poll $poll): RedirectResponse
    {
        return $this->act(fn () => $this->polls->unpublish($poll, $request->user()), 'Back to draft.');
    }

    public function close(Request $request, Poll $poll): RedirectResponse
    {
        return $this->act(fn () => $this->polls->close($poll, $request->user()), 'Voting closed.');
    }

    public function publishResults(Request $request, Poll $poll): RedirectResponse
    {
        return $this->act(fn () => $this->polls->publishResults($poll, $request->user()), 'Results published to everyone who could take part.');
    }

    private function questionData(Request $request): array
    {
        return $request->validate([
            'prompt' => ['required', 'string', 'max:500'], 'type' => ['nullable', 'in:' . implode(',', array_keys(PollQuestion::TYPES))],
            'max_choices' => ['nullable', 'integer', 'min:2', 'max:50'], 'is_required' => ['nullable', 'boolean'], 'options' => ['nullable', 'string', 'max:5000'],
        ]);
    }

    public function storeQuestion(Request $request, Poll $poll): RedirectResponse
    {
        $d = $this->questionData($request);

        return $this->act(fn () => $this->polls->addQuestion($poll, $d['prompt'], $d['type'] ?? 'single', $d['max_choices'] ?? null, (bool) ($d['is_required'] ?? false), preg_split('/\R/', (string) ($d['options'] ?? ''))),
            $poll->isElection() ? 'Office added. Now add its candidates.' : 'Question added.');
    }

    public function updateQuestion(Request $request, PollQuestion $question): RedirectResponse
    {
        $d = $this->questionData($request);

        return $this->act(fn () => $this->polls->updateQuestion($question, $d['prompt'], $d['type'] ?? 'single', $d['max_choices'] ?? null, (bool) ($d['is_required'] ?? false)), 'Saved.');
    }

    public function destroyQuestion(PollQuestion $question): RedirectResponse
    {
        return $this->act(fn () => $this->polls->deleteQuestion($question), 'Removed.');
    }

    public function storeOption(Request $request, PollQuestion $question): RedirectResponse
    {
        $d = $request->validate([
            'label' => ['nullable', 'string', 'max:200'], 'person' => ['nullable', 'string', 'max:100'], 'description' => ['nullable', 'string', 'max:2000'],
            'photo' => ['nullable', 'image', 'max:2048'],
        ]);
        $user = null;
        if (filled($d['person'] ?? null)) {
            $user = $this->people->find($d['person']);
            if (! $user) {
                return back()->withInput()->with('error', 'Nobody found with that matric number, staff number or email.');
            }
        }
        $label = trim((string) ($d['label'] ?? '')) ?: $user?->student?->full_name ?: $user?->name;
        if (! $label) {
            return back()->withInput()->with('error', $question->poll->isElection() ? 'Enter the candidate\'s matric number, staff number or email, or their name.' : 'Type the answer.');
        }
        $photo = $request->file('photo')?->store('polls', 'public') ?? $user?->student?->photo;

        return $this->act(fn () => $this->polls->addOption($question, $label, $d['description'] ?? null, $user?->id, $photo), $question->poll->isElection() ? 'Candidate added.' : 'Answer added.');
    }

    public function destroyOption(PollOption $option): RedirectResponse
    {
        return $this->act(fn () => $this->polls->deleteOption($option), 'Removed.');
    }

    /** Results as a spreadsheet (CSV). Written answers are included; voters are never named for a secret ballot. */
    public function export(Poll $poll): StreamedResponse
    {
        abort_if($poll->phase() === 'draft', 404);
        $results = $this->polls->results($poll);

        return response()->streamDownload(function () use ($poll, $results) {
            $out = fopen('php://output', 'w');
            fputcsv($out, [$poll->title]);
            fputcsv($out, ['Eligible', $results->eligible, 'Voted', $results->voted, 'Turnout %', $results->turnout()]);
            foreach ($results->questions as $q) {
                fputcsv($out, []);
                fputcsv($out, [$q->prompt, 'Ballots answering', $q->ballots, 'Did not answer', $q->abstained]);
                foreach ($q->options as $o) {
                    fputcsv($out, [$o->label, $o->votes, $o->percent . '%']);
                }
                foreach ($q->texts as $t) {
                    fputcsv($out, [$t]);
                }
            }
            fclose($out);
        }, Str::slug($poll->title) . '-results.csv', ['Content-Type' => 'text/csv']);
    }
}
