<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Course;
use App\Models\CourseMaterial;
use App\Models\StudyDocument;
use App\Models\StudyPlan;
use App\Models\StudyQuiz;
use App\Models\Venue;
use App\Services\Ai\AiClient;
use App\Services\Campus\MaterialService;
use App\Services\Schedule\MySchedule;
use App\Services\Study\GpaPlanner;
use App\Services\Study\ReadingPlanner;
use App\Services\Study\StudyCompanion;
use App\Support\GradeScale;
use App\Support\MobileAppSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * The student's academic companion: GPA / CGPA targets, the reading planner, campus places for directions, and the AI
 * study companion (documents, practice quizzes, questions). See docs/MOBILE_API.md.
 */
class StudyController extends ApiController
{
    public function __construct(
        private MobileAppSettings $settings,
        private GpaPlanner $gpa,
        private ReadingPlanner $reading,
        private StudyCompanion $study,
        private AiClient $ai,
        private MySchedule $schedule,
        private MaterialService $materials,
    ) {
    }

    private function feature(string $key): void
    {
        abort_unless($this->settings->feature($key), 403, 'This is switched off by the school.');
    }

    private function fail(RuntimeException $e): JsonResponse
    {
        return response()->json(['message' => $e->getMessage(), 'errors' => ['action' => [$e->getMessage()]]], 422);
    }

    // ---- GPA / CGPA planner ------------------------------------------------------------------------------------------------

    /** GET /planner/gpa: grading scale, record so far and this semester's courses (with CA so far, when the school allows). */
    public function gpaContext(Request $request): JsonResponse
    {
        $this->feature('gpa_planner');

        return $this->ok($this->gpa->context($this->student($request)));
    }

    /** POST /planner/gpa {target, kind: gpa|cgpa, strengths: {course id: 1-5}, fixed: {course id: letter}} */
    public function gpaPlan(Request $request): JsonResponse
    {
        $this->feature('gpa_planner');
        $d = $request->validate([
            'target' => ['required', 'numeric', 'min:0.01', 'max:' . max(1, GradeScale::maxPoints())],
            'kind' => ['required', Rule::in(['gpa', 'cgpa'])],
            'strengths' => ['nullable', 'array'], 'strengths.*' => ['integer', 'between:1,5'],
            'fixed' => ['nullable', 'array'], 'fixed.*' => ['nullable', 'string', 'max:5'],
        ]);
        $ctx = $this->gpa->context($this->student($request));

        return $this->ok($this->gpa->plan($ctx, (float) $d['target'], $d['kind'], array_map('intval', $d['strengths'] ?? []), array_filter($d['fixed'] ?? [])));
    }

    // ---- Reading planner ---------------------------------------------------------------------------------------------------

    /** The student's courses this semester with what the planner weighs: units, lectures a week, exam date. */
    private function readingCourses(Request $request): array
    {
        $student = $this->student($request);
        $term = $this->schedule->term();
        $ctx = $this->gpa->context($student);
        $lectures = $term->sessionId ? $this->schedule->lectures($student, $term) : collect();
        $perWeek = $lectures->groupBy('course_id')->map->count();
        $examDates = collect();
        if ($term->sessionId) {
            $examDates = $this->schedule->exams($student, $term)->papers->mapWithKeys(fn ($rows) => [$rows->first()->course_id => $rows->first()->date?->toDateString()]);
        }

        return [
            collect($ctx['courses'])->map(fn ($c) => ['id' => $c['id'], 'code' => $c['code'], 'title' => $c['title'], 'units' => $c['units'],
                'lectures' => (int) ($perWeek[$c['id']] ?? 0), 'exam_date' => $examDates[$c['id']] ?? null])->values()->all(),
            $lectures->map(fn ($e) => ['day' => (int) $e->day, 'starts_at' => substr((string) $e->starts_at, 0, 5), 'ends_at' => substr((string) $e->ends_at, 0, 5), 'code' => $e->course?->code])->values()->all(),
            $term,
            $ctx,
        ];
    }

    private function plan(Request $request): ?StudyPlan
    {
        $term = $this->schedule->term();

        return StudyPlan::where('user_id', $request->user()->id)->where('academic_session_id', $term->sessionId)->where('semester_type_id', $term->semesterTypeId)->first();
    }

    private function presentPlan(?StudyPlan $p): ?array
    {
        return $p ? ['id' => $p->id, 'preferences' => $p->preferences, 'sessions' => $p->sessions, 'summary' => $p->summary, 'generated_at' => $p->generated_at?->toIso8601String()] : null;
    }

    /** GET /planner/reading: the saved plan (if any) and what the planner works from. */
    public function readingShow(Request $request): JsonResponse
    {
        $this->feature('reading_planner');
        [$courses, $lectures, $term, $ctx] = $this->readingCourses($request);

        return $this->ok([
            'term' => $ctx['term'],
            'plan' => $this->presentPlan($this->plan($request)),
            'courses' => $courses,
            'lectures' => $lectures,
            'scale' => $ctx['scale'],
            'criteria' => [
                ['key' => 'units', 'label' => 'Credit units', 'help' => 'Courses with more units get more time.'],
                ['key' => 'timetable', 'label' => 'How often it is on the timetable', 'help' => 'Courses with more lectures a week have more to cover.'],
                ['key' => 'priority', 'label' => 'My own priority', 'help' => 'You rank each course.'],
                ['key' => 'difficulty', 'label' => 'How hard I find it', 'help' => 'Harder courses get more time.'],
                ['key' => 'exams', 'label' => 'Upcoming exams', 'help' => 'Courses whose exam is close get more time and turn into revision.'],
                ['key' => 'target', 'label' => 'My target grades', 'help' => 'Courses where you need a high grade get more time.'],
            ],
        ]);
    }

    /** POST /planner/reading: build (or rebuild) the plan from the student's free time and criteria, and save it. */
    public function readingSave(Request $request): JsonResponse
    {
        $this->feature('reading_planner');
        $d = $request->validate([
            'free_time' => ['required', 'array', 'min:1', 'max:60'],
            'free_time.*.day' => ['required', 'integer', 'between:1,7'],
            'free_time.*.start' => ['required', 'date_format:H:i'],
            'free_time.*.end' => ['required', 'date_format:H:i'],
            'session_minutes' => ['required', 'integer', 'between:20,240'],
            'break_minutes' => ['nullable', 'integer', 'between:0,120'],
            'max_per_day' => ['nullable', 'integer', 'between:1,12'],
            'avoid_lectures' => ['nullable', 'boolean'],
            'criteria' => ['required', 'array', 'min:1'], 'criteria.*' => [Rule::in(ReadingPlanner::CRITERIA)],
            'courses' => ['nullable', 'array'],
            'courses.*.priority' => ['nullable', 'integer', 'between:1,5'],
            'courses.*.difficulty' => ['nullable', 'integer', 'between:1,5'],
            'courses.*.target_grade' => ['nullable', 'string', 'max:5'],
            'courses.*.skip' => ['nullable', 'boolean'],
        ]);
        [$courses, $lectures, $term, $ctx] = $this->readingCourses($request);
        $points = collect($ctx['scale'])->pluck('points', 'letter');
        $settings = $d['courses'] ?? [];
        $courses = collect($courses)->reject(fn ($c) => ! empty($settings[$c['id']]['skip']))->map(fn ($c) => $c + [
            'priority' => $settings[$c['id']]['priority'] ?? 3,
            'difficulty' => $settings[$c['id']]['difficulty'] ?? 3,
            'target_points' => isset($settings[$c['id']]['target_grade']) ? ($points[$settings[$c['id']]['target_grade']] ?? null) : null,
        ])->values()->all();
        if (! $courses) {
            return response()->json(['message' => 'You have no courses for this semester to plan for. Register your courses first.', 'errors' => ['courses' => ['No courses.']]], 422);
        }

        // The weakest practice topics' courses get a nudge, like a higher priority.
        $weak = collect($this->study->progress($request->user())['courses'] ?? [])->filter(fn ($c) => $c['answered'] >= 3 && $c['percent'] < 50)->pluck('course_id')->all();
        foreach ($courses as &$c) {
            if (in_array($c['id'], $weak, true)) {
                $c['priority'] = min(5, $c['priority'] + 1);
            }
        }
        unset($c);

        $prefs = collect($d)->except('courses')->all() + ['courses' => $settings];
        $built = $this->reading->build($courses, $prefs, $lectures, GradeScale::maxPoints());
        if (! $built['sessions']) {
            return response()->json(['message' => 'No study session fits in the free time you gave (after your lectures). Add more free time or shorten the sessions.', 'errors' => ['free_time' => ['Not enough free time.']]], 422);
        }

        $plan = StudyPlan::updateOrCreate(
            ['user_id' => $request->user()->id, 'academic_session_id' => $term->sessionId, 'semester_type_id' => $term->semesterTypeId],
            ['preferences' => $prefs, 'sessions' => $built['sessions'], 'summary' => $built['summary'], 'generated_at' => now()],
        );

        return $this->ok($this->presentPlan($plan), ['message' => count($built['sessions']) . ' study sessions a week planned.']);
    }

    public function readingDelete(Request $request): JsonResponse
    {
        $this->plan($request)?->delete();

        return $this->ok(['deleted' => true]);
    }

    // ---- Campus places -----------------------------------------------------------------------------------------------------

    /** GET /campus/places: venues with a map position, and the health centre. */
    public function places(Request $request): JsonResponse
    {
        $this->feature('campus_map');

        return $this->ok([
            'venues' => Venue::where('is_active', true)->whereNotNull('latitude')->whereNotNull('longitude')->orderBy('code')->get()->map(fn (Venue $v) => $v->location())->values()->all(),
            'health_centre' => $this->settings->healthCentre(),
        ]);
    }

    // ---- AI study companion ------------------------------------------------------------------------------------------------

    private function aiFeature(): void
    {
        $this->feature('study_companion');
    }

    private function own(Request $request, StudyDocument|StudyQuiz $m): void
    {
        abort_unless($m->user_id === $request->user()->id, 404);
    }

    /** GET /study: is the tutor on, the student's documents, recent quizzes, progress and course materials they can study. */
    public function home(Request $request): JsonResponse
    {
        $this->aiFeature();
        $user = $request->user();
        $courseIds = $this->materials->courses($user)->pluck('id')->all();

        return $this->ok([
            'ai_ready' => $this->ai->ready(),
            'remaining_today' => $this->ai->ready() ? $this->ai->remaining($user) : 0,
            'upload_mb' => $this->settings->int('study_upload_mb'),
            'documents' => StudyDocument::with('course')->withCount('quizzes')->where('user_id', $user->id)->latest()->get()->map(fn ($d) => $this->study->present($d))->values()->all(),
            'quizzes' => StudyQuiz::with('course')->where('user_id', $user->id)->latest()->limit(10)->get()->map(fn ($q) => collect($this->study->presentQuiz($q))->except('questions')->all())->values()->all(),
            'progress' => $this->study->progress($user),
            'materials' => CourseMaterial::with('course')->whereIn('course_id', $courseIds)->whereNotNull('path')->latest()->limit(50)->get()
                ->map(fn ($m) => ['id' => $m->id, 'title' => $m->title, 'course' => $m->course?->code, 'file_name' => $m->original_name])->values()->all(),
            'courses' => Course::whereIn('id', $courseIds)->orderBy('code')->get(['id', 'code', 'title'])->toArray(),
        ]);
    }

    /** POST /study/documents (multipart): file, or text, or material_id; title; course_id */
    public function addDocument(Request $request): JsonResponse
    {
        $this->aiFeature();
        $mb = $this->settings->int('study_upload_mb');
        $d = $request->validate([
            'file' => ['nullable', 'file', 'max:' . ($mb * 1024)],
            'text' => ['nullable', 'string', 'max:200000'],
            'material_id' => ['nullable', 'integer', 'exists:course_materials,id'],
            'title' => ['nullable', 'string', 'max:150'],
            'course_id' => ['nullable', 'integer', 'exists:courses,id'],
        ]);
        $course = isset($d['course_id']) ? Course::find($d['course_id']) : null;
        try {
            $doc = match (true) {
                isset($d['material_id']) => $this->study->fromMaterial($request->user(), CourseMaterial::findOrFail($d['material_id'])),
                $request->hasFile('file') => $this->study->upload($request->user(), $request->file('file'), $d['title'] ?? null, $course),
                filled($d['text'] ?? null) => $this->study->fromText($request->user(), $d['title'] ?? 'My notes', $d['text'], $course),
                default => throw new RuntimeException('Choose a file, a course material or paste your notes.'),
            };
        } catch (RuntimeException $e) {
            return $this->fail($e);
        }

        return $this->ok($this->study->present($doc->load('course')), [], 201);
    }

    public function document(Request $request, StudyDocument $document): JsonResponse
    {
        $this->own($request, $document);
        $document->load('course');

        return $this->ok($this->study->present($document) + [
            'quizzes' => $document->quizzes()->latest()->get()->map(fn ($q) => collect($this->study->presentQuiz($q))->except('questions')->all())->values()->all(),
            'outline' => collect($document->text ? $this->study->chunks($document->text) : [])->map(fn ($c, $i) => ['section' => $i + 1, 'preview' => \Illuminate\Support\Str::limit(preg_replace('/\s+/', ' ', $c), 140)])->values()->all(),
        ]);
    }

    /** GET /study/documents/{document}/sections/{n}: one section's text, for reading in the app. */
    public function section(Request $request, StudyDocument $document, int $n): JsonResponse
    {
        $this->own($request, $document);
        $chunks = $document->text ? $this->study->chunks($document->text) : [];
        abort_unless(isset($chunks[$n - 1]), 404);

        return $this->ok(['section' => $n, 'of' => count($chunks), 'text' => $chunks[$n - 1]]);
    }

    public function deleteDocument(Request $request, StudyDocument $document): JsonResponse
    {
        $this->own($request, $document);
        if ($document->path && ! $document->course_material_id) {
            Storage::disk('local')->delete($document->path);
        }
        $document->delete();

        return $this->ok(['deleted' => true]);
    }

    /** POST /study/documents/{document}/quiz {count, type: mcq|short|mixed, difficulty: easy|medium|hard|mixed, topic} */
    public function makeQuiz(Request $request, StudyDocument $document): JsonResponse
    {
        $this->aiFeature();
        $this->own($request, $document);
        $d = $request->validate(['count' => ['nullable', 'integer', 'between:1,15'], 'type' => ['nullable', Rule::in(['mcq', 'short', 'mixed'])],
            'difficulty' => ['nullable', Rule::in(['easy', 'medium', 'hard', 'mixed'])], 'topic' => ['nullable', 'string', 'max:150']]);
        try {
            $quiz = $this->study->quiz($request->user(), $document->load('course'), (int) ($d['count'] ?? 5), $d['type'] ?? 'mixed', $d['difficulty'] ?? 'mixed', $d['topic'] ?? null);
        } catch (RuntimeException $e) {
            return $this->fail($e);
        }

        return $this->ok($this->study->presentQuiz($quiz->load('course')), ['remaining_today' => $this->ai->remaining($request->user())], 201);
    }

    public function quiz(Request $request, StudyQuiz $quiz): JsonResponse
    {
        $this->own($request, $quiz);

        return $this->ok($this->study->presentQuiz($quiz->load('course'), $request->boolean('reveal')));
    }

    /** POST /study/quizzes/{quiz}/answer {index, answer} */
    public function answer(Request $request, StudyQuiz $quiz): JsonResponse
    {
        $this->own($request, $quiz);
        $d = $request->validate(['index' => ['required', 'integer', 'min:0'], 'answer' => ['nullable', 'max:4000']]);
        try {
            $mark = $this->study->answer($request->user(), $quiz, (int) $d['index'], $d['answer'] ?? null);
        } catch (RuntimeException $e) {
            return $this->fail($e);
        }

        return $this->ok($mark, ['score' => $quiz->score, 'answered' => count($quiz->answers ?? [])]);
    }

    public function deleteQuiz(Request $request, StudyQuiz $quiz): JsonResponse
    {
        $this->own($request, $quiz);
        $quiz->delete();

        return $this->ok(['deleted' => true]);
    }

    /** POST /study/ask {question, document_id?, course_id?, history: [{role, content}]} */
    public function ask(Request $request): JsonResponse
    {
        $this->aiFeature();
        $d = $request->validate([
            'question' => ['required', 'string', 'min:2', 'max:2000'],
            'document_id' => ['nullable', 'integer'],
            'course_id' => ['nullable', 'integer', 'exists:courses,id'],
            'history' => ['nullable', 'array', 'max:20'],
            'history.*.role' => ['required', Rule::in(['user', 'assistant'])],
            'history.*.content' => ['required', 'string', 'max:6000'],
        ]);
        $doc = isset($d['document_id']) ? StudyDocument::with('course')->find($d['document_id']) : null;
        if ($doc) {
            $this->own($request, $doc);
        }
        try {
            $answer = $this->study->ask($request->user(), $doc, $d['question'], $d['history'] ?? [], isset($d['course_id']) ? Course::find($d['course_id']) : $doc?->course);
        } catch (RuntimeException $e) {
            return $this->fail($e);
        }

        return $this->ok($answer);
    }

    public function progress(Request $request): JsonResponse
    {
        $this->aiFeature();

        return $this->ok($this->study->progress($request->user()));
    }
}
