<?php

namespace App\Http\Controllers\Campus;

use App\Http\Controllers\Controller;
use App\Models\ContentReport;
use App\Models\Course;
use App\Models\CourseAssignment;
use App\Models\CourseMaterial;
use App\Models\FeedPost;
use App\Models\MarketListing;
use App\Services\Campus\FeedService;
use App\Services\Campus\MarketService;
use App\Services\Campus\MaterialService;
use App\Services\Campus\ReportService;
use App\Services\OperationAudit;
use App\Support\MobileAppSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;

/** Campus Life on the website: mobile app settings, the feed, marketplace moderation, reports and course materials. */
class CampusAdminController extends Controller
{
    public function __construct(
        private MobileAppSettings $settings,
        private FeedService $feed,
        private MarketService $market,
        private ReportService $reports,
        private MaterialService $materials,
        private OperationAudit $audit,
    ) {
        $this->middleware('permission:Edit mobile app settings')->only('settings', 'saveSettings');
        $this->middleware('permission:Moderate the marketplace')->only('market', 'moderateListing');
        $this->middleware('permission:Handle reports')->only('reportsIndex', 'handleReport');
    }

    // ---- Settings ----------------------------------------------------------------------------------------------------

    public function settings(): View
    {
        return view('campus.settings', ['pagetitle' => 'Mobile App', 'group' => $this->settings, 'values' => $this->settings->all()]);
    }

    public function saveSettings(Request $request): RedirectResponse
    {
        $changed = $this->settings->save($request->validate($this->settings->rules()));
        if ($changed) {
            $this->audit->note('mobile_settings', 'campus', $request->user(), 'settings_changed', null, array_map(fn ($c) => $c[0], $changed), array_map(fn ($c) => $c[1], $changed));
        }

        return back()->with('success', 'Saved. Phones pick up the changes the next time the app opens.');
    }

    // ---- Feed ------------------------------------------------------------------------------------------------------------

    public function feedIndex(Request $request): View
    {
        $user = $request->user();
        abort_unless($user->can('Post to the campus feed') || $user->can('Moderate the campus feed') || $this->feed->audiencesFor($user), 403);
        $q = FeedPost::with('user')->withCount('comments')->latest();
        if (! $user->can('Moderate the campus feed')) {
            $q->where('user_id', $user->id);
        }

        return view('campus.feed', ['pagetitle' => 'Campus Feed', 'posts' => $q->paginate(25), 'audiences' => $this->feed->audiencesFor($user)]);
    }

    public function storePost(Request $request): RedirectResponse
    {
        $d = $request->validate([
            'audience' => ['required', Rule::in(array_keys(FeedPost::AUDIENCES))], 'audience_id' => ['nullable', 'integer'],
            'title' => ['nullable', 'string', 'max:150'], 'body' => ['required', 'string', 'max:5000'], 'image' => ['nullable', 'image', 'max:8192'],
            'is_event' => ['nullable', 'boolean'], 'starts_at' => ['nullable', 'date'], 'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'venue' => ['nullable', 'string', 'max:150'], 'pinned' => ['nullable', 'boolean'], 'allow_comments' => ['nullable', 'boolean'],
        ]);
        if (in_array($d['audience'], ['faculty', 'department'], true)) {
            $d['audience_id'] = $request->integer($d['audience'] . '_id') ?: ($d['audience_id'] ?? null);
        }
        try {
            $this->feed->create($request->user(), $d, $request->file('image'));
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Posted. It shows in the app now.');
    }

    public function moderatePost(Request $request, FeedPost $post): RedirectResponse
    {
        $d = $request->validate(['action' => ['required', 'in:hide,show,pin,unpin,delete']]);
        $user = $request->user();
        $own = $post->user_id === $user->id;
        abort_unless($own || $user->can('Moderate the campus feed'), 403);
        if (in_array($d['action'], ['pin', 'unpin'], true)) {
            abort_unless($user->can('Post to the campus feed'), 403);
        }
        match ($d['action']) {
            'hide' => $post->update(['status' => 'hidden']),
            'show' => $post->update(['status' => 'published']),
            'pin' => $post->update(['pinned' => true]),
            'unpin' => $post->update(['pinned' => false]),
            'delete' => $post->delete(),
        };

        return back()->with('success', 'Done.');
    }

    // ---- Marketplace ---------------------------------------------------------------------------------------------------

    public function market(Request $request): View
    {
        $status = $request->query('status', MarketListing::where('status', 'pending')->exists() ? 'pending' : 'active');
        $listings = MarketListing::with('photos', 'user')->withTrashed(false)
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->when($request->filled('q'), fn ($q) => $q->where('title', 'like', '%' . $request->query('q') . '%'))
            ->latest()->paginate(30)->withQueryString();

        return view('campus.market', ['pagetitle' => 'Marketplace', 'listings' => $listings, 'status' => $status,
            'counts' => MarketListing::selectRaw('status, count(*) c')->groupBy('status')->pluck('c', 'status')]);
    }

    public function moderateListing(Request $request, MarketListing $listing): RedirectResponse
    {
        $d = $request->validate(['decision' => ['required', 'in:approve,reject,hide,restore'], 'note' => ['nullable', 'string', 'max:500']]);
        try {
            $this->market->moderate($listing, $request->user(), $d['decision'], $d['note'] ?? null);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Done. The student has been told.');
    }

    // ---- Reports ----------------------------------------------------------------------------------------------------------

    public function reportsIndex(Request $request): View
    {
        $status = $request->query('status', 'open');
        $reports = ContentReport::with('reporter', 'handler')->when($status !== 'all', fn ($q) => $q->where('status', $status))->latest()->paginate(30)->withQueryString();

        return view('campus.reports', ['pagetitle' => 'Reports', 'reports' => $reports, 'status' => $status,
            'open' => ContentReport::where('status', 'open')->count()]);
    }

    public function handleReport(Request $request, ContentReport $report): RedirectResponse
    {
        $d = $request->validate(['action' => ['required', 'in:dismiss,remove'], 'outcome' => ['nullable', 'string', 'max:500']]);
        try {
            $this->reports->handle($report, $request->user(), $d['action'], $d['outcome'] ?? null);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $d['action'] === 'remove' ? 'Removed.' : 'Dismissed.');
    }

    // ---- Course materials -------------------------------------------------------------------------------------------------

    public function materialsIndex(Request $request): View
    {
        $user = $request->user();
        $courses = $user->can('Manage course materials')
            ? Course::orderBy('code')->get()
            : Course::whereIn('id', CourseAssignment::where('user_id', $user->id)->pluck('course_id'))->orderBy('code')->get();
        abort_if($courses->isEmpty() && ! $user->can('Manage course materials'), 403, 'Materials are added by the lecturers of a course.');
        $course = $request->filled('course') ? $courses->firstWhere('id', $request->integer('course')) : $courses->first();

        return view('campus.materials', ['pagetitle' => 'Course Materials', 'courses' => $courses, 'course' => $course,
            'materials' => $course ? CourseMaterial::with('user', 'session')->where('course_id', $course->id)->latest()->get() : collect()]);
    }

    public function storeMaterial(Request $request, Course $course): RedirectResponse
    {
        $d = $request->validate(['title' => ['required', 'string', 'max:150'], 'description' => ['nullable', 'string', 'max:1000'], 'link' => ['nullable', 'url', 'max:500'],
            'file' => ['nullable', 'file', 'max:' . (MaterialService::MAX_MB * 1024)]]);
        try {
            $this->materials->add($request->user(), $course, $d, $request->file('file'));
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Added. Students taking the course can open it in the app.');
    }

    public function deleteMaterial(Request $request, CourseMaterial $material): RedirectResponse
    {
        try {
            $this->materials->remove($material, $request->user());
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Removed.');
    }

    public function downloadMaterial(Request $request, CourseMaterial $material)
    {
        abort_unless($this->materials->canRead($request->user(), $material->course), 404);

        return response()->download($this->materials->file($material), $material->original_name ?? 'material');
    }
}
