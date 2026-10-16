<?php

namespace App\Http\Controllers;

use App\Data\Results\MasterSheetQuery;
use App\Models\AcademicSession;
use App\Models\SemesterType;
use App\Services\Results\MasterSheets;
use App\Support\Levels;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Illuminate\View\View;
use RuntimeException;

/**
 * The master sheet (broadsheet) of a programme and level for a semester: on screen, printed or saved as PDF from the
 * browser, or downloaded as Excel; and approving or publishing every score sheet on it at once.
 */
class MasterSheetController extends Controller
{
    public function __construct(private MasterSheets $sheets)
    {
    }

    public function index(Request $request): View
    {
        return $this->page($request, false);
    }

    public function print(Request $request): View
    {
        return $this->page($request, true);
    }

    public function excel(Request $request): Response
    {
        $q = MasterSheetQuery::fromRequest($request);
        abort_unless($q->complete(), 422, 'Choose a programme, level, session and semester.');
        abort_unless($this->sheets->canView($request->user(), $q->programme), 403);
        $sheet = $this->sheets->build($q->programme, $q->level, $q->session, $q->semesterType, $q->provisional);
        $name = Str::slug("master sheet {$q->programme->code} {$q->level} {$q->session->name} {$q->semesterType->name}") . '.xlsx';

        return response($this->sheets->excel($sheet), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="' . $name . '"',
        ]);
    }

    public function decide(Request $request): RedirectResponse
    {
        $request->validate(['decision' => ['required', 'in:approve,publish'], 'note' => ['nullable', 'string', 'max:500']]);
        $q = MasterSheetQuery::fromRequest($request);
        abort_unless($q->complete(), 422, 'Choose a programme, level, session and semester.');
        abort_unless($this->sheets->canView($request->user(), $q->programme), 403);
        $sheet = $this->sheets->build($q->programme, $q->level, $q->session, $q->semesterType);

        try {
            $n = $request->input('decision') === 'approve'
                ? $this->sheets->approveAll($sheet, $request->user(), $request->input('note'))
                : $this->sheets->publishAll($sheet, $request->user());
        } catch (RuntimeException $e) {
            return redirect()->route('results.broadsheet', $q->params())->with('error', $e->getMessage());
        }
        $what = $request->input('decision') === 'approve' ? 'approved' : 'published. Students can see their results now';

        return redirect()->route('results.broadsheet', $q->params())->with('success', "{$n} score " . Str::plural('sheet', $n) . " {$what}.");
    }

    private function page(Request $request, bool $print): View
    {
        $user = $request->user();
        $programmes = $this->sheets->programmesFor($user);
        abort_if($programmes->isEmpty(), 403);
        $q = MasterSheetQuery::fromRequest($request);
        if ($q->programme) {
            abort_unless($this->sheets->canView($user, $q->programme), 403);
        }
        $sheet = $q->complete() ? $this->sheets->build($q->programme, $q->level, $q->session, $q->semesterType, $q->provisional) : null;

        return view('results.broadsheet', [
            'pagetitle' => 'Master sheet', 'sheet' => $sheet, 'q' => $q, 'print' => $print,
            'approvable' => $sheet ? $this->sheets->approvable($sheet, $user) : collect(),
            'publishable' => $sheet ? $this->sheets->publishable($sheet, $user) : collect(),
            'shared' => $sheet ? $this->sheets->sharedCourses($sheet) : collect(),
            'programmes' => $programmes->pluck('name', 'id'), 'sessions' => AcademicSession::orderByDesc('name')->pluck('name', 'id'),
            'types' => SemesterType::orderBy('sort_order')->pluck('name', 'id'), 'levels' => Levels::options(),
        ]);
    }
}
