<?php

namespace App\Http\Controllers;

use App\Data\Results\ScoreEntryQuery;
use App\Models\AcademicSession;
use App\Models\Department;
use App\Models\SemesterType;
use App\Models\StudyCategory;
use App\Services\RegistrationService;
use App\Services\ResultService;
use App\Services\Results\ScoreEntry;
use App\Support\Levels;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Results → Enter Scores: post scores by student (one class on a course) or by subject (everyone on the course). */
class ScoreEntryController extends Controller
{
    public function __construct(private ScoreEntry $entry, private ResultService $results, private RegistrationService $registration)
    {
    }

    public function index(Request $request): View
    {
        $user = $request->user();
        abort_unless($this->entry->canUse($user), 403, 'You have no courses to enter scores for.');
        $ctx = $this->registration->context();
        $q = ScoreEntryQuery::fromRequest($request, $ctx?->session, $ctx?->type);
        $sheet = $this->entry->sheet($user, $q);
        $scheme = $sheet?->load('course', 'stage', 'scheme.items')->scheme;
        [$caMax, $examMax] = $sheet ? ResultService::limits($sheet) : [ResultService::caMax(), ResultService::examMax()];

        return view('results.entry', [
            'pagetitle' => 'Enter Scores',
            'q' => $q,
            'courses' => $this->entry->courses($user, $q),
            'sheet' => $sheet,
            'scheme' => $scheme,
            'rows' => $sheet ? $this->entry->rows($sheet, $q) : collect(),
            'edit' => $sheet && $sheet->isEditable(),
            'caMax' => $caMax, 'examMax' => $examMax,
            'missing' => $sheet && $sheet->isEditable() ? $this->results->incomplete($sheet) : 0,
            'sessions' => AcademicSession::orderByDesc('name')->pluck('name', 'id'),
            'types' => SemesterType::orderBy('sort_order')->pluck('name', 'id'),
            'departments' => Department::orderBy('name')->pluck('name', 'id'),
            'categories' => StudyCategory::orderBy('name')->pluck('name', 'id'),
            'levels' => Levels::options(),
        ]);
    }
}
