<?php

namespace App\Services\Study;

use App\Models\Course;
use App\Models\CourseMaterial;
use App\Models\StudyDocument;
use App\Models\StudyQuiz;
use App\Models\User;
use App\Services\Ai\AiClient;
use App\Services\Campus\MaterialService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The AI tutor in the app. A student adds study documents (their own notes, slides or a course material), then:
 *  - makes practice quizzes from them (multiple choice and short answer), answers them and gets marked with feedback;
 *  - asks questions while reading and gets answers grounded in the document, with the sections they came from;
 *  - sees which topics they keep getting wrong, so the reading planner can give those more time.
 * Answers say plainly when they come from general knowledge rather than the student's material. Documents and quizzes
 * are private to the student.
 */
class StudyCompanion
{
    private const CHUNK = 1800;          // characters per section

    private const CONTEXT = 24000;       // characters of material sent with one request

    public function __construct(private AiClient $ai, private TextExtractor $extractor, private MaterialService $materials)
    {
    }

    // ---- Documents ----------------------------------------------------------------------------------------------------------

    public function upload(User $user, UploadedFile $file, ?string $title, ?Course $course): StudyDocument
    {
        $ext = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: '');
        if (! in_array($ext, TextExtractor::TYPES, true)) {
            throw new RuntimeException('Upload a PDF, Word (.docx), PowerPoint (.pptx), text file or a photo of your notes.');
        }
        $path = $file->store('study/' . $user->id, 'local');

        return $this->make($user, Storage::disk('local')->path($path), $ext, [
            'title' => $title ?: Str::limit(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME), 150, ''),
            'course_id' => $course?->id, 'path' => $path, 'original_name' => $file->getClientOriginalName(),
            'mime' => $file->getMimeType(), 'size' => $file->getSize(),
        ]);
    }

    /** Study a material the lecturer shared for one of the student's courses. */
    public function fromMaterial(User $user, CourseMaterial $material): StudyDocument
    {
        if (! $this->materials->canRead($user, $material->course)) {
            throw new RuntimeException('That material is not available to you.');
        }
        if ($existing = StudyDocument::where('user_id', $user->id)->where('course_material_id', $material->id)->where('status', 'ready')->first()) {
            return $existing;
        }
        if (! $material->path || ! Storage::disk('local')->exists($material->path)) {
            throw new RuntimeException('That material is a link, not a file the study companion can read.');
        }
        $ext = strtolower(pathinfo($material->original_name ?: $material->path, PATHINFO_EXTENSION));

        return $this->make($user, Storage::disk('local')->path($material->path), $ext, [
            'title' => $material->title, 'course_id' => $material->course_id, 'course_material_id' => $material->id,
            'original_name' => $material->original_name, 'mime' => $material->mime, 'size' => (int) $material->size,
        ]);
    }

    /** Typed or pasted notes. */
    public function fromText(User $user, string $title, string $text, ?Course $course): StudyDocument
    {
        $text = $this->extractor->tidy($text);
        if (mb_strlen($text) < 200) {
            throw new RuntimeException('Paste at least a paragraph or two of notes.');
        }

        return StudyDocument::create(['user_id' => $user->id, 'course_id' => $course?->id, 'title' => $title, 'text' => $text, 'chars' => mb_strlen($text), 'status' => 'ready', 'mime' => 'text/plain', 'size' => strlen($text)]);
    }

    private function make(User $user, string $full, string $ext, array $attrs): StudyDocument
    {
        if (! in_array($ext, TextExtractor::TYPES, true)) {
            throw new RuntimeException('The study companion reads PDF, Word, PowerPoint, text files and photos of notes.');
        }
        $text = $this->extractor->extract($full, $ext);
        $pages = $ext === 'pdf' ? $this->extractor->pdfPages((string) file_get_contents($full)) : 1;

        // Scanned PDFs and photos have no text layer: ask the AI service to read them.
        if (! $this->extractor->usable($text, $pages) && in_array($ext, ['pdf', 'jpg', 'jpeg', 'png', 'webp'], true)) {
            $mime = $ext === 'pdf' ? 'application/pdf' : ($ext === 'jpg' ? 'image/jpeg' : 'image/' . $ext);
            $text = $this->extractor->tidy($this->ai->chat($user,
                'You transcribe study material for a university student. Output the text of the document faithfully as plain text, keeping headings, lists, equations (in plain notation such as x^2 + 3x = 0) and tables (as simple rows). Describe diagrams in one line in [square brackets]. No commentary.',
                [['role' => 'user', 'content' => 'Transcribe this document.']], 12000, ['mime' => $mime, 'data' => (string) file_get_contents($full)]));
        }

        $ok = mb_strlen($text) >= 200;

        return StudyDocument::create($attrs + ['user_id' => $user->id, 'text' => $ok ? $text : null, 'chars' => mb_strlen($text),
            'status' => $ok ? 'ready' : 'failed', 'error' => $ok ? null : 'No readable text was found in this file. Try a clearer photo, or a PDF exported from Word or PowerPoint.']);
    }

    public function present(StudyDocument $d): array
    {
        return [
            'id' => $d->id, 'title' => $d->title, 'status' => $d->status, 'error' => $d->error,
            'course' => $d->course ? ['id' => $d->course->id, 'code' => $d->course->code, 'title' => $d->course->title] : null,
            'from_material' => $d->course_material_id !== null, 'file_name' => $d->original_name, 'size' => (int) $d->size,
            'chars' => (int) $d->chars, 'sections' => $d->text ? count($this->chunks($d->text)) : 0,
            'words' => $d->text ? str_word_count(Str::limit($d->text, 400000, '')) : 0,
            'quizzes' => $d->quizzes_count ?? null, 'created_at' => $d->created_at?->toIso8601String(),
        ];
    }

    // ---- Quizzes -----------------------------------------------------------------------------------------------------------

    /**
     * Practice questions from a document. $type: mcq | short | mixed; $difficulty: easy | medium | hard | mixed.
     * Questions favour the topics the student got wrong before.
     */
    public function quiz(User $user, StudyDocument $doc, int $count = 5, string $type = 'mixed', string $difficulty = 'mixed', ?string $topic = null): StudyQuiz
    {
        $this->ready($doc);
        $count = max(1, min(15, $count));
        $chunks = $this->chunks($doc->text);
        $weak = $this->weakTopics($user, $doc->id);
        $pick = $topic ? $this->relevant($chunks, $topic) : $this->spread($chunks);

        $data = $this->ai->json($user, $this->tutor($doc) . "\n\nWrite exam-style practice questions that test understanding, not trivia. Base every question on the material sections given; do not use outside facts for the answers. "
            . 'For calculations, include the working in the explanation.',
            [['role' => 'user', 'content' => $this->sectionsText($chunks, $pick)
                . "\n\nWrite {$count} " . match ($type) { 'mcq' => 'multiple-choice', 'short' => 'short-answer', default => 'mixed (about half multiple-choice, half short-answer)' }
                . " questions, difficulty: {$difficulty}." . ($topic ? " Focus on: {$topic}." : '')
                . ($weak ? ' The student has struggled with: ' . implode(', ', array_slice($weak, 0, 5)) . '; include some questions on these.' : '')
                . "\nJSON shape: {\"title\": \"short quiz title\", \"questions\": [{\"type\": \"mcq\" or \"short\", \"question\": \"...\", \"options\": [\"...\", \"...\", \"...\", \"...\"] (mcq only), "
                . '"answer": index of the correct option for mcq (0-3) or the model answer text for short, "explanation": "why, with working", "topic": "2-4 word topic", "section": section number it comes from, "quote": "a short phrase from that section supporting the answer"}]}'],
            ], 6000);

        $questions = collect($data['questions'] ?? [])->filter(fn ($q) => is_array($q) && filled($q['question'] ?? null))->map(function ($q) use ($chunks) {
            $mcq = ($q['type'] ?? '') === 'mcq' && is_array($q['options'] ?? null) && count($q['options']) >= 2;
            $section = isset($q['section']) && is_numeric($q['section']) && isset($chunks[(int) $q['section'] - 1]) ? (int) $q['section'] : null;

            return [
                'type' => $mcq ? 'mcq' : 'short',
                'question' => Str::limit(trim((string) $q['question']), 1000),
                'options' => $mcq ? array_values(array_map(fn ($o) => Str::limit(trim((string) $o), 300), array_slice($q['options'], 0, 6))) : null,
                'answer' => $mcq ? max(0, min(count($q['options']) - 1, (int) ($q['answer'] ?? 0))) : Str::limit(trim((string) ($q['answer'] ?? '')), 1500),
                'explanation' => Str::limit(trim((string) ($q['explanation'] ?? '')), 2000),
                'topic' => Str::limit(trim((string) ($q['topic'] ?? 'General')), 60),
                'section' => $section,
                'quote' => Str::limit(trim((string) ($q['quote'] ?? '')), 300) ?: null,
            ];
        })->take($count)->values()->all();
        if (! $questions) {
            throw new RuntimeException('No questions could be made from this document. Try another topic or document.');
        }

        return StudyQuiz::create(['user_id' => $user->id, 'study_document_id' => $doc->id, 'course_id' => $doc->course_id,
            'title' => Str::limit((string) ($data['title'] ?? ('Practice: ' . $doc->title)), 150, ''), 'difficulty' => $difficulty, 'questions' => $questions, 'answers' => []]);
    }

    /** Mark one answer: multiple choice on the spot, short answers by the tutor against the model answer. */
    public function answer(User $user, StudyQuiz $quiz, int $index, string|int|null $answer): array
    {
        $q = $quiz->questions[$index] ?? throw new RuntimeException('That question is not in this quiz.');
        if ($q['type'] === 'mcq') {
            $correct = (int) $answer === (int) $q['answer'];
            $mark = ['answer' => (int) $answer, 'correct' => $correct, 'score' => $correct ? 1.0 : 0.0,
                'feedback' => $correct ? 'Correct.' : 'Not quite. The answer is: ' . ($q['options'][$q['answer']] ?? '') . '.'];
        } else {
            $text = trim((string) $answer);
            if ($text === '') {
                throw new RuntimeException('Type your answer first.');
            }
            $m = $this->ai->json($user, 'You mark a university student\'s short answer fairly and kindly against the model answer. Accept answers that are correct in substance even if worded differently. Give brief, specific feedback: what was right, what was missing.',
                [['role' => 'user', 'content' => "Question: {$q['question']}\nModel answer: {$q['answer']}\nStudent's answer: {$text}\n\nJSON shape: {\"score\": 0 to 1 (partial credit allowed), \"feedback\": \"2-3 sentences\"}"]], 800);
            $score = max(0.0, min(1.0, (float) ($m['score'] ?? 0)));
            $mark = ['answer' => Str::limit($text, 2000), 'correct' => $score >= 0.6, 'score' => round($score, 2), 'feedback' => Str::limit((string) ($m['feedback'] ?? ''), 1000)];
        }

        $answers = $quiz->answers ?? [];
        $answers[(string) $index] = $mark;
        $quiz->answers = $answers;
        $quiz->score = round(collect($answers)->avg('score') * 100, 2);
        $quiz->save();

        return $mark + ['explanation' => $q['explanation'], 'model_answer' => $q['type'] === 'mcq' ? ($q['options'][$q['answer']] ?? null) : $q['answer'], 'quote' => $q['quote'], 'section' => $q['section']];
    }

    public function presentQuiz(StudyQuiz $quiz, bool $reveal = false): array
    {
        $answers = $quiz->answers ?? [];

        return [
            'id' => $quiz->id, 'title' => $quiz->title, 'difficulty' => $quiz->difficulty, 'document_id' => $quiz->study_document_id,
            'course' => $quiz->course ? ['id' => $quiz->course->id, 'code' => $quiz->course->code] : null,
            'score' => $quiz->score, 'answered' => count($answers), 'total' => count($quiz->questions),
            'created_at' => $quiz->created_at?->toIso8601String(),
            'questions' => collect($quiz->questions)->map(function ($q, $i) use ($answers, $reveal) {
                $done = $answers[(string) $i] ?? null;
                $show = $reveal || $done;

                return ['index' => $i, 'type' => $q['type'], 'question' => $q['question'], 'options' => $q['options'], 'topic' => $q['topic'], 'section' => $q['section'],
                    'result' => $done, 'explanation' => $show ? $q['explanation'] : null, 'quote' => $show ? $q['quote'] : null,
                    'model_answer' => $show ? ($q['type'] === 'mcq' ? ($q['options'][$q['answer']] ?? null) : $q['answer']) : null,
                    'correct_index' => $show && $q['type'] === 'mcq' ? $q['answer'] : null];
            })->values()->all(),
        ];
    }

    // ---- Asking ------------------------------------------------------------------------------------------------------------

    /**
     * Answer a question about a document (or in general for a course), grounded in the most relevant sections.
     *
     * @param  array<int, array{role: string, content: string}>  $history  the conversation so far (last few turns)
     */
    public function ask(User $user, ?StudyDocument $doc, string $question, array $history = [], ?Course $course = null): array
    {
        $chunks = [];
        $pick = [];
        if ($doc) {
            $this->ready($doc);
            $chunks = $this->chunks($doc->text);
            $pick = $this->relevant($chunks, $question . ' ' . collect($history)->where('role', 'user')->pluck('content')->implode(' '));
        }
        $messages = collect($history)->filter(fn ($m) => in_array($m['role'] ?? '', ['user', 'assistant'], true) && filled($m['content'] ?? null))
            ->take(-8)->map(fn ($m) => ['role' => $m['role'], 'content' => Str::limit((string) $m['content'], 3000)])->values()->all();
        // Conversations must start with the student.
        while ($messages && $messages[0]['role'] !== 'user') {
            array_shift($messages);
        }
        $messages[] = ['role' => 'user', 'content' => ($doc ? $this->sectionsText($chunks, $pick) . "\n\n" : '') . 'Question: ' . Str::limit($question, 2000)
            . "\n\nJSON shape: {\"answer\": \"your answer (plain text; short paragraphs or numbered steps; show working for calculations)\", \"from_material\": true if the answer is supported by the sections, false if it relies on general knowledge, \"sections\": [section numbers used], \"follow_up\": \"one short question to check understanding\"}"];

        $system = ($doc ? $this->tutor($doc) : 'You are a patient university tutor' . ($course ? " for {$course->code} {$course->title}" : '') . '.')
            . ' Explain clearly at undergraduate level. If the material does not cover the question, say so and then answer from general knowledge, marking it as such. '
            . 'Help the student learn: for assignment-style questions, guide them through the steps rather than only giving the final answer. Never invent quotes or references.';
        $data = $this->ai->json($user, $system, $messages, 2500);

        $used = collect($data['sections'] ?? [])->filter(fn ($n) => is_numeric($n) && isset($chunks[(int) $n - 1]))->map(fn ($n) => (int) $n)->unique()->values();

        return [
            'answer' => trim((string) ($data['answer'] ?? '')),
            'from_material' => $doc ? (bool) ($data['from_material'] ?? false) && $used->isNotEmpty() : false,
            'sources' => $used->map(fn ($n) => ['section' => $n, 'excerpt' => Str::limit($chunks[$n - 1], 280)])->all(),
            'follow_up' => filled($data['follow_up'] ?? null) ? (string) $data['follow_up'] : null,
            'remaining' => $this->ai->remaining($user),
        ];
    }

    // ---- Progress ----------------------------------------------------------------------------------------------------------

    /** Topics and courses by how well the student does in practice, weakest first. */
    public function progress(User $user): array
    {
        $topics = [];
        $courses = [];
        foreach (StudyQuiz::with('course')->where('user_id', $user->id)->latest()->limit(200)->get() as $quiz) {
            foreach ($quiz->answers ?? [] as $i => $a) {
                $q = $quiz->questions[(int) $i] ?? null;
                if (! $q) {
                    continue;
                }
                $key = ($quiz->course?->code ? $quiz->course->code . ': ' : '') . $q['topic'];
                $topics[$key] ??= ['topic' => $q['topic'], 'course' => $quiz->course?->code, 'answered' => 0, 'score' => 0.0];
                $topics[$key]['answered']++;
                $topics[$key]['score'] += (float) $a['score'];
                if ($quiz->course) {
                    $courses[$quiz->course_id] ??= ['course_id' => $quiz->course_id, 'code' => $quiz->course->code, 'answered' => 0, 'score' => 0.0];
                    $courses[$quiz->course_id]['answered']++;
                    $courses[$quiz->course_id]['score'] += (float) $a['score'];
                }
            }
        }
        $pct = fn ($r) => $r + ['percent' => (int) round($r['score'] / max(1, $r['answered']) * 100)];

        return [
            'topics' => collect($topics)->map($pct)->sortBy('percent')->values()->map(fn ($r) => collect($r)->except('score')->all())->all(),
            'courses' => collect($courses)->map($pct)->sortBy('percent')->values()->map(fn ($r) => collect($r)->except('score')->all())->all(),
            'quizzes' => StudyQuiz::where('user_id', $user->id)->count(),
            'remaining_today' => $this->ai->ready() ? $this->ai->remaining($user) : 0,
        ];
    }

    /** @return string[] topics answered wrongly more often than not, worst first */
    public function weakTopics(User $user, ?int $documentId = null): array
    {
        $tally = [];
        $q = StudyQuiz::where('user_id', $user->id)->latest()->limit(50);
        if ($documentId) {
            $q->where('study_document_id', $documentId);
        }
        foreach ($q->get() as $quiz) {
            foreach ($quiz->answers ?? [] as $i => $a) {
                $t = $quiz->questions[(int) $i]['topic'] ?? null;
                if ($t) {
                    $tally[$t][] = (float) $a['score'];
                }
            }
        }

        return collect($tally)->map(fn ($s) => array_sum($s) / count($s))->filter(fn ($avg) => $avg < 0.6)->sort()->keys()->all();
    }

    // ---- Helpers -----------------------------------------------------------------------------------------------------------

    private function ready(StudyDocument $doc): void
    {
        if ($doc->status !== 'ready' || ! $doc->text) {
            throw new RuntimeException($doc->error ?: 'This document has no readable text.');
        }
    }

    private function tutor(StudyDocument $doc): string
    {
        return 'You are a patient university tutor helping a student study "' . $doc->title . '"'
            . ($doc->course ? " for {$doc->course->code} {$doc->course->title}" : '') . '. The student\'s material is given as numbered sections.';
    }

    /** @return string[] the text in sections of about CHUNK characters, split at paragraph ends */
    public function chunks(string $text): array
    {
        $out = [];
        $cur = '';
        foreach (preg_split("/\n{2,}/", $text) as $para) {
            while (mb_strlen($para) > self::CHUNK) {   // a very long paragraph is cut at sentence ends
                $cut = mb_strrpos(mb_substr($para, 0, self::CHUNK), '. ') ?: self::CHUNK;
                $piece = mb_substr($para, 0, $cut + 1);
                $para = ltrim(mb_substr($para, $cut + 1));
                if ($cur !== '') {
                    $out[] = $cur;
                    $cur = '';
                }
                $out[] = trim($piece);
            }
            if (mb_strlen($cur) + mb_strlen($para) > self::CHUNK && $cur !== '') {
                $out[] = $cur;
                $cur = '';
            }
            $cur = trim($cur . "\n\n" . $para);
        }
        if ($cur !== '') {
            $out[] = $cur;
        }

        return array_values(array_filter($out, fn ($c) => trim($c) !== ''));
    }

    /** @return int[] section indexes most related to the words given, as many as fit, in document order */
    public function relevant(array $chunks, string $query): array
    {
        $terms = collect(preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($query)))->filter(fn ($w) => mb_strlen($w) >= 3 && ! in_array($w, ['the', 'and', 'for', 'what', 'why', 'how', 'which', 'with', 'this', 'that', 'from', 'are', 'was', 'does', 'explain', 'about'], true))->unique();
        if ($terms->isEmpty()) {
            return $this->spread($chunks);
        }
        $n = max(1, count($chunks));
        $df = $terms->mapWithKeys(fn ($t) => [$t => collect($chunks)->filter(fn ($c) => str_contains(mb_strtolower($c), $t))->count()]);
        $scores = [];
        foreach ($chunks as $i => $c) {
            $lc = mb_strtolower($c);
            $s = 0.0;
            foreach ($terms as $t) {
                $tf = substr_count($lc, $t);
                if ($tf) {
                    $s += (1 + log($tf)) * log(1 + $n / max(1, $df[$t]));
                }
            }
            $scores[$i] = $s;
        }
        arsort($scores);
        $pick = [];
        $size = 0;
        foreach ($scores as $i => $s) {
            if ($s <= 0 && $pick) {
                break;
            }
            if ($size + mb_strlen($chunks[$i]) > self::CONTEXT) {
                break;
            }
            $pick[] = $i;
            $size += mb_strlen($chunks[$i]);
        }
        sort($pick);

        return $pick ?: $this->spread($chunks);
    }

    /** @return int[] sections spread evenly over the whole document, as many as fit */
    public function spread(array $chunks): array
    {
        $n = count($chunks);
        $fit = max(1, intdiv(self::CONTEXT, self::CHUNK));
        if ($n <= $fit) {
            return range(0, max(0, $n - 1));
        }
        // a random start so repeated quizzes cover different parts
        $step = $n / $fit;
        $offset = mt_rand(0, max(0, (int) floor($step) - 1));

        return array_values(array_unique(array_map(fn ($k) => min($n - 1, (int) floor($k * $step) + $offset), range(0, $fit - 1))));
    }

    private function sectionsText(array $chunks, array $pick): string
    {
        return "Material:\n" . collect($pick)->map(fn ($i) => '[Section ' . ($i + 1) . "]\n" . $chunks[$i])->implode("\n\n");
    }
}
