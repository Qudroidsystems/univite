<?php

namespace App\Services\Olevel;

use App\Data\Olevel\OlevelCheck;
use App\Models\Applicant;
use App\Models\ApplicantDocument;
use App\Models\OlevelRequirement;
use App\Models\Programme;
use App\Models\Setting;
use App\Models\Student;
use App\Models\StudentOlevelResult;
use App\Models\StudentOlevelSitting;
use App\Models\User;
use App\Services\Ai\AiClient;
use App\Services\Messenger;
use App\Services\OperationAudit;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * O Level results (WAEC, NECO, NABTEB ...) for course registration.
 *
 * Departments (or single programmes) set the subjects and minimum grades they need. Students enter each sitting's
 * subjects and grades and upload the result; when the school has an AI service set up, the upload is read and the
 * grades it finds are placed beside the student's own. Staff compare the two, choose the grades to keep and confirm
 * the sitting. Registration at the admission level (or every level, as the department chooses) waits until the
 * confirmed results meet the requirement, unless staff allow the student to register in the meantime.
 */
class OlevelService
{
    public const DEFAULTS = [
        'olevel.ai_reading' => '1',          // read uploads with the AI service when one is set up
        'olevel.require_document' => '1',    // a sitting cannot be submitted without its scanned result
        'olevel.block_registration' => '1',  // registration waits for confirmed results that meet the requirement
    ];

    /** Common ways a subject is written, so "Maths" matches "Mathematics". */
    private const ALIASES = [
        'english' => 'englishlanguage', 'useofenglish' => 'englishlanguage', 'maths' => 'mathematics', 'generalmathematics' => 'mathematics',
        'mathematicscore' => 'mathematics', 'literature' => 'literatureinenglish', 'crk' => 'christianreligiousstudies', 'crs' => 'christianreligiousstudies',
        'christianreligiousknowledge' => 'christianreligiousstudies', 'irk' => 'islamicreligiousstudies', 'irs' => 'islamicreligiousstudies',
        'islamicstudies' => 'islamicreligiousstudies', 'agric' => 'agriculturalscience', 'agriculture' => 'agriculturalscience',
        'accounting' => 'financialaccounting', 'principlesofaccounts' => 'financialaccounting', 'accounts' => 'financialaccounting',
        'computerscience' => 'computerstudies', 'ict' => 'computerstudies', 'civiceducation' => 'civiceducation', 'civics' => 'civiceducation',
        'furthermaths' => 'furthermathematics', 'geog' => 'geography', 'govt' => 'government',
    ];

    public function __construct(private AiClient $ai, private OperationAudit $audit, private Messenger $messenger)
    {
    }

    public static function setting(string $name): bool
    {
        return Setting::get('olevel.' . $name, self::DEFAULTS['olevel.' . $name]) === '1';
    }

    public static function key(string $subject): string
    {
        $k = preg_replace('/[^a-z]/', '', strtolower($subject));

        return self::ALIASES[$k] ?? $k;
    }

    /** The programme's own requirement, else its department's. */
    public function requirementFor(?Programme $programme): ?OlevelRequirement
    {
        if (! $programme) {
            return null;
        }

        return OlevelRequirement::with('subjects')->where('scope_type', 'programme')->where('scope_id', $programme->id)->first()
            ?? OlevelRequirement::with('subjects')->where('scope_type', 'department')->where('scope_id', $programme->department_id)->first();
    }

    /** Whether the requirement is checked for this student now (at the level they are registering for). */
    public function appliesNow(Student $student, ?OlevelRequirement $req = null): bool
    {
        $req ??= $this->requirementFor($student->programme);
        if (! $req || ! $req->is_active) {
            return false;
        }

        return $req->applies_to === 'all' || (int) $student->current_level === (int) ($student->entry_level ?: 100);
    }

    /**
     * Measure the student's confirmed sittings (or the sittings given, using the grades given) against the requirement.
     * With more sittings than allowed, the best combination is used.
     *
     * @param  Collection<int, StudentOlevelSitting>|null  $sittings  with results loaded
     * @param  string  $use  confirmed | student | extracted: which grade of each subject counts
     */
    public function evaluate(Student $student, ?Collection $sittings = null, string $use = 'confirmed'): OlevelCheck
    {
        $req = $this->requirementFor($student->programme);
        $applies = $this->appliesNow($student, $req);
        $all = $student->olevelSittings()->with('results')->get();
        $sittings ??= $all->where('status', 'confirmed')->values();
        $overridden = (bool) $student->olevel_override_at;
        $awaiting = $all->where('status', 'submitted')->isNotEmpty();
        $hasConfirmed = $all->where('status', 'confirmed')->isNotEmpty();

        if (! $req || ! $req->is_active) {
            return new OlevelCheck($req, false, true, [], $this->best($sittings, $use), $sittings->map->label()->all(), 0, $overridden, $hasConfirmed, $awaiting);
        }
        if ($sittings->isEmpty()) {
            return new OlevelCheck($req, $applies, false, [$awaiting ? 'Your O Level results are waiting for the school to confirm them.' : 'No confirmed O Level result yet. Enter your results and upload the result slip.'], [], [], 0, $overridden, $hasConfirmed, $awaiting);
        }

        $best = null;
        foreach ($this->combinations($sittings->take(6)->values(), max(1, $req->max_sittings)) as $combo) {
            $grades = $this->best($combo, $use);
            [$problems, $credits] = $this->measure($grades, $req);
            if ($best === null || count($problems) < count($best[1]) || (count($problems) === count($best[1]) && $credits > $best[2])) {
                $best = [$combo, $problems, $credits, $grades];
            }
            if (! $problems) {
                break;
            }
        }
        [$combo, $problems, $credits, $grades] = $best;
        if ($sittings->count() > $req->max_sittings && $problems) {
            $problems[] = "At most {$req->max_sittings} " . Str::plural('sitting', $req->max_sittings) . ' may be combined.';
        }

        return new OlevelCheck($req, $applies, ! $problems, $problems, $grades, $combo->map->label()->all(), $credits, $overridden, $hasConfirmed, $awaiting);
    }

    /** @return array<string, string> subject => best grade across the sittings */
    private function best(Collection $sittings, string $use): array
    {
        $out = [];
        foreach ($sittings as $s) {
            foreach ($s->results as $r) {
                $g = match ($use) {
                    'student' => $r->grade, 'extracted' => $r->extracted_grade ?: $r->grade,
                    default => $r->confirmed_grade,
                };
                if (! $g) {
                    continue;
                }
                $k = self::key($r->subject);
                if (! isset($out[$k]) || StudentOlevelSitting::rank($g) < StudentOlevelSitting::rank($out[$k][1])) {
                    $out[$k] = [$r->subject, strtoupper($g)];
                }
            }
        }

        return collect($out)->mapWithKeys(fn ($v) => [$v[0] => $v[1]])->sortKeys()->all();
    }

    /** @return array{0: string[], 1: int} problems and the number of credit passes */
    private function measure(array $grades, OlevelRequirement $req): array
    {
        $byKey = collect($grades)->mapWithKeys(fn ($g, $subject) => [self::key($subject) => $g]);
        $ok = fn ($subject, $min) => ($g = $byKey->get(self::key($subject))) !== null && StudentOlevelSitting::rank($g) <= StudentOlevelSitting::rank($min);
        $problems = [];
        foreach ($req->subjects->where('kind', 'required') as $s) {
            if (! $ok($s->subject, $s->min_grade)) {
                $has = $byKey->get(self::key($s->subject));
                $problems[] = "{$s->subject}: {$s->min_grade} or better needed" . ($has ? " (you have {$has})." : ' (not taken).');
            }
        }
        $optional = $req->subjects->where('kind', 'optional');
        if ($optional->isNotEmpty() && $req->optional_needed > 0) {
            $passed = $optional->filter(fn ($s) => $ok($s->subject, $s->min_grade))->count();
            if ($passed < $req->optional_needed) {
                $problems[] = "{$req->optional_needed} of " . $optional->pluck('subject')->implode(', ') . " needed at the grade set (you have {$passed}).";
            }
        }
        $credits = $byKey->filter(fn ($g) => in_array($g, StudentOlevelSitting::CREDIT_GRADES, true))->count();
        if ($credits < $req->min_credits) {
            $problems[] = "{$req->min_credits} credit passes (C6 or better) needed; you have {$credits}.";
        }

        return [$problems, $credits];
    }

    /** Every combination of 1..$max sittings. @return \Generator<int, Collection> */
    private function combinations(Collection $items, int $max): \Generator
    {
        $n = $items->count();
        for ($mask = 1; $mask < (1 << $n); $mask++) {
            $pick = collect();
            for ($i = 0; $i < $n; $i++) {
                if ($mask & (1 << $i)) {
                    $pick->push($items[$i]);
                }
            }
            if ($pick->count() <= $max) {
                yield $pick;
            }
        }
    }

    /** Reason this student cannot register yet because of O Level, or null. */
    public function registrationBlocker(Student $student): ?string
    {
        if (! self::setting('block_registration') || ! $this->appliesNow($student)) {
            return null;
        }
        $check = $this->evaluate($student);
        if ($check->allowsRegistration()) {
            return null;
        }
        if (! $check->hasConfirmed) {
            return $check->awaiting
                ? 'Your O Level results are waiting for the school to confirm them. You can register once they are confirmed.'
                : 'Submit your O Level results (WAEC, NECO, NABTEB ...) under "My O Level Results". The school must confirm them before you can register.';
        }

        return 'Your confirmed O Level results do not meet ' . ($student->programme?->department?->name ?? 'the department') . '\'s requirement: ' . implode(' ', $check->problems) . ' See your department.';
    }

    // ---- The student's side ---------------------------------------------------------------------------------

    /** Bring in the O Level results the student entered when they applied (once, when they have none here yet). */
    public function importFromApplication(Student $student): int
    {
        if (! $student->applicant_id || $student->olevelSittings()->exists()) {
            return 0;
        }
        $rows = \App\Models\ApplicantResult::where('applicant_id', $student->applicant_id)->whereIn('exam_type', array_keys(StudentOlevelSitting::EXAMS))->get();
        if ($rows->isEmpty()) {
            return 0;
        }
        $doc = ApplicantDocument::where('applicant_id', $student->applicant_id)->where('type', 'olevel')->first();
        $n = 0;
        DB::transaction(function () use ($student, $rows, $doc, &$n) {
            foreach ($rows->groupBy(fn ($r) => "{$r->exam_type}|{$r->exam_year}|{$r->exam_number}") as $group) {
                $first = $group->first();
                $copy = null;
                if ($doc && Storage::disk('local')->exists($doc->path)) {
                    $copy = 'olevel/' . $student->id . '/' . Str::random(20) . '.' . pathinfo($doc->path, PATHINFO_EXTENSION);
                    Storage::disk('local')->copy($doc->path, $copy);
                }
                $sitting = StudentOlevelSitting::create([
                    'student_id' => $student->id, 'exam_type' => $first->exam_type, 'exam_year' => $first->exam_year, 'exam_number' => $first->exam_number,
                    'document_path' => $copy, 'document_name' => $copy ? $doc->original_name : null, 'document_mime' => $copy ? Storage::disk('local')->mimeType($copy) : null,
                ]);
                foreach ($group as $r) {
                    $sitting->results()->create(['subject' => $r->subject, 'grade' => StudentOlevelSitting::normaliseGrade($r->grade) ?? $r->grade]);
                    $n++;
                }
            }
        });

        return $n;
    }

    /** @param  array<int, array{subject: ?string, grade: ?string}>  $rows */
    public function saveSitting(Student $student, array $data, array $rows, ?UploadedFile $file, ?StudentOlevelSitting $sitting = null): StudentOlevelSitting
    {
        if ($sitting && ! $sitting->isEditable()) {
            throw new RuntimeException('This sitting has been submitted and can no longer be changed.');
        }
        $clean = collect($rows)->map(fn ($r) => ['subject' => Str::title(trim((string) ($r['subject'] ?? ''))), 'grade' => StudentOlevelSitting::normaliseGrade($r['grade'] ?? null)])
            ->filter(fn ($r) => $r['subject'] !== '' && $r['grade'])->unique(fn ($r) => self::key($r['subject']))->values();
        if ($clean->isEmpty()) {
            throw new RuntimeException('Enter at least one subject and its grade.');
        }
        if ($clean->count() > 12) {
            throw new RuntimeException('A sitting has at most 12 subjects.');
        }

        return DB::transaction(function () use ($student, $data, $clean, $file, $sitting) {
            $attrs = ['exam_type' => $data['exam_type'], 'exam_year' => (int) $data['exam_year'], 'exam_number' => strtoupper(trim($data['exam_number'])), 'centre' => $data['centre'] ?? null];
            $dupe = StudentOlevelSitting::where('student_id', $student->id)->where('exam_type', $attrs['exam_type'])->where('exam_year', $attrs['exam_year'])
                ->where('exam_number', $attrs['exam_number'])->when($sitting, fn ($q) => $q->whereKeyNot($sitting->id))->exists();
            if ($dupe) {
                throw new RuntimeException('You have already entered that sitting.');
            }
            $sitting = $sitting ? tap($sitting)->update($attrs + ['status' => 'draft']) : StudentOlevelSitting::create($attrs + ['student_id' => $student->id]);
            if ($file) {
                if ($sitting->document_path) {
                    Storage::disk('local')->delete($sitting->document_path);
                }
                $path = $file->store('olevel/' . $student->id, 'local');
                $sitting->update(['document_path' => $path, 'document_name' => $file->getClientOriginalName(), 'document_mime' => $file->getMimeType(),
                    'extraction_status' => 'none', 'extracted' => null, 'extraction_note' => null, 'extracted_at' => null]);
                $sitting->results()->update(['extracted_grade' => null]);
            }
            $keep = $clean->map(fn ($r) => self::key($r['subject']))->all();
            foreach ($sitting->results()->get() as $existing) {
                if (! in_array(self::key($existing->subject), $keep, true)) {
                    $existing->extracted_grade ? $existing->update(['grade' => null]) : $existing->delete();
                }
            }
            foreach ($clean as $r) {
                $row = $sitting->results()->get()->first(fn ($x) => self::key($x->subject) === self::key($r['subject']));
                $row ? $row->update(['grade' => $r['grade'], 'subject' => $r['subject']]) : $sitting->results()->create($r);
            }

            return $sitting->fresh('results');
        });
    }

    public function submit(StudentOlevelSitting $sitting): StudentOlevelSitting
    {
        if (! $sitting->isEditable()) {
            throw new RuntimeException('This sitting has already been submitted.');
        }
        if (self::setting('require_document') && ! $sitting->document_path) {
            throw new RuntimeException('Upload the scanned result (or a clear photo of it) before submitting.');
        }
        if ($sitting->results()->whereNotNull('grade')->doesntExist()) {
            throw new RuntimeException('Enter your subjects and grades first.');
        }
        $sitting->update(['status' => 'submitted', 'submitted_at' => now(), 'review_note' => null]);

        return $sitting;
    }

    // ---- Reading the uploaded result ---------------------------------------------------------------------------

    public function aiAvailable(): bool
    {
        return self::setting('ai_reading') && $this->ai->ready();
    }

    /**
     * Read the uploaded result with the AI service and put the grades it finds beside the student's own. Nothing the
     * student typed is changed. Returns the sitting with extraction_status done, failed or off.
     */
    public function read(StudentOlevelSitting $sitting, User $by): StudentOlevelSitting
    {
        if (! $sitting->document_path || ! Storage::disk('local')->exists($sitting->document_path)) {
            throw new RuntimeException('There is no uploaded result to read.');
        }
        if (! $this->aiAvailable()) {
            $sitting->update(['extraction_status' => 'off', 'extraction_note' => 'Automatic reading is not switched on. Check the grades against the uploaded result by eye.']);

            return $sitting;
        }
        $mime = $sitting->document_mime ?: Storage::disk('local')->mimeType($sitting->document_path);
        if (! str_starts_with((string) $mime, 'image/') && $mime !== 'application/pdf') {
            $sitting->update(['extraction_status' => 'failed', 'extraction_note' => 'Only PDF files and photos can be read automatically.']);

            return $sitting;
        }

        $system = 'You read Nigerian secondary school examination results: WAEC (WASSCE), NECO (SSCE), NABTEB and GCE result slips, '
            . 'statements of result, certificates and scratch-card printouts. Report exactly what the document shows; never guess a grade you cannot read. '
            . 'Grades are on the scale A1, B2, B3, C4, C5, C6, D7, E8, F9. Write subject names in full (English Language, Mathematics, Biology ...).';
        $prompt = 'Read this result and return: {"exam_type": "waec|neco|nabteb|gce|neco_gce|unknown", "exam_year": 2020, "exam_number": "...", '
            . '"candidate_name": "...", "centre": "...", "subjects": [{"subject": "English Language", "grade": "C6"}], '
            . '"readable": true, "notes": "anything unclear, damaged or suspicious about the document"}. Use null for anything not shown.';

        try {
            $data = $this->ai->json($by, $system, [['role' => 'user', 'content' => $prompt]], 1500,
                ['mime' => (string) $mime, 'data' => Storage::disk('local')->get($sitting->document_path)], limit: false);
        } catch (RuntimeException $e) {
            $sitting->update(['extraction_status' => 'failed', 'extraction_note' => 'Could not read it automatically: ' . $e->getMessage()]);

            return $sitting;
        }

        $subjects = collect($data['subjects'] ?? [])->filter(fn ($r) => is_array($r) && ! empty($r['subject']))
            ->map(fn ($r) => ['subject' => Str::title(trim((string) $r['subject'])), 'grade' => StudentOlevelSitting::normaliseGrade($r['grade'] ?? null)])
            ->filter(fn ($r) => $r['grade'])->unique(fn ($r) => self::key($r['subject']))->values();

        DB::transaction(function () use ($sitting, $subjects, $data) {
            $rows = $sitting->results()->get();
            $sitting->results()->update(['extracted_grade' => null]);
            foreach ($subjects as $r) {
                $row = $rows->first(fn ($x) => self::key($x->subject) === self::key($r['subject']));
                $row ? $row->update(['extracted_grade' => $r['grade']]) : $sitting->results()->create(['subject' => $r['subject'], 'grade' => null, 'extracted_grade' => $r['grade']]);
            }
            $notes = array_filter([
                ($data['readable'] ?? true) === false ? 'The document was hard to read.' : null,
                $subjects->isEmpty() ? 'No subjects could be read from the document.' : null,
                ! empty($data['exam_number']) && strtoupper(preg_replace('/\s+/', '', (string) $data['exam_number'])) !== strtoupper(preg_replace('/\s+/', '', $sitting->exam_number)) ? 'The examination number on the document (' . $data['exam_number'] . ') is not the one entered.' : null,
                ! empty($data['exam_year']) && (int) $data['exam_year'] !== (int) $sitting->exam_year ? 'The year on the document (' . $data['exam_year'] . ') is not the one entered.' : null,
                ! empty($data['notes']) ? (string) $data['notes'] : null,
            ]);
            $sitting->update(['extraction_status' => $subjects->isEmpty() ? 'failed' : 'done', 'extracted' => array_intersect_key($data, array_flip(['exam_type', 'exam_year', 'exam_number', 'candidate_name', 'centre', 'readable', 'notes'])),
                'extraction_note' => $notes ? implode(' ', $notes) : null, 'extracted_at' => now()]);
        });

        return $sitting->fresh('results');
    }

    // ---- The school's side --------------------------------------------------------------------------------------

    /**
     * Confirm a sitting with the grades chosen by staff: $grades subject result id => grade, or $source to take every
     * subject from the student's entries or from what was read off the document.
     *
     * @param  array<int, ?string>  $grades
     */
    public function confirm(StudentOlevelSitting $sitting, User $by, string $source = 'mixed', array $grades = [], ?string $note = null): StudentOlevelSitting
    {
        if (! in_array($sitting->status, ['submitted', 'confirmed', 'rejected'], true)) {
            throw new RuntimeException('The student has not submitted this sitting yet.');
        }
        $before = $sitting->results()->get()->mapWithKeys(fn ($r) => [$r->subject => $r->confirmed_grade])->all();
        DB::transaction(function () use ($sitting, $by, $source, $grades, $note) {
            foreach ($sitting->results()->get() as $r) {
                $g = match ($source) {
                    'student' => $r->grade,
                    'extracted' => $r->extracted_grade,
                    default => array_key_exists($r->id, $grades) ? $grades[$r->id] : ($r->confirmed_grade ?? $r->grade),
                };
                $r->update(['confirmed_grade' => StudentOlevelSitting::normaliseGrade($g)]);
            }
            if ($sitting->results()->whereNotNull('confirmed_grade')->doesntExist()) {
                throw new RuntimeException('Keep at least one subject\'s grade to confirm the sitting.');
            }
            $sitting->update(['status' => 'confirmed', 'reviewed_by' => $by->id, 'reviewed_at' => now(), 'grade_source' => $source, 'review_note' => $note]);
        });
        $after = $sitting->results()->get()->mapWithKeys(fn ($r) => [$r->subject => $r->confirmed_grade])->all();
        $this->audit->note('olevel_confirm', 'admissions', $by, 'olevel_confirmed', $sitting, $before, $after, $note);
        $this->tell($sitting, true, $note);

        return $sitting->fresh('results');
    }

    public function reject(StudentOlevelSitting $sitting, User $by, string $note): StudentOlevelSitting
    {
        if (blank($note)) {
            throw new RuntimeException('Say what the student must correct.');
        }
        $sitting->update(['status' => 'rejected', 'reviewed_by' => $by->id, 'reviewed_at' => now(), 'review_note' => $note]);
        $sitting->results()->update(['confirmed_grade' => null]);
        $this->audit->note('olevel_return', 'admissions', $by, 'olevel_returned', $sitting, ['status' => 'submitted'], ['status' => 'rejected'], $note);
        $this->tell($sitting, false, $note);

        return $sitting;
    }

    /** Let a student register although their O Level does not meet the requirement yet (or take that back). */
    public function override(Student $student, User $by, bool $allow, ?string $note): Student
    {
        if ($allow && blank($note)) {
            throw new RuntimeException('Give the reason for letting the student register.');
        }
        $student->update($allow ? ['olevel_override_at' => now(), 'olevel_override_by' => $by->id, 'olevel_override_note' => $note]
            : ['olevel_override_at' => null, 'olevel_override_by' => null, 'olevel_override_note' => null]);
        $this->audit->note('olevel_override', 'admissions', $by, $allow ? 'olevel_override_given' : 'olevel_override_removed', $student, null, ['allowed' => $allow], $note);

        return $student;
    }

    private function tell(StudentOlevelSitting $sitting, bool $confirmed, ?string $note): void
    {
        if ($user = $sitting->student?->user) {
            $this->messenger->notify($user, 'registration', $confirmed ? 'O Level result confirmed' : 'O Level result returned',
                $confirmed ? "Your {$sitting->label()} result has been confirmed by the school." . ($note ? " Note: {$note}" : '')
                    : "Your {$sitting->label()} result was returned: {$note} Correct it and submit it again.",
                $sitting, route('my.olevel'), $confirmed ? 'success' : 'warning');
        }
    }
}
