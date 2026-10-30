<?php

namespace App\Http\Controllers\Students;

use App\Data\Students\RefereeData;
use App\Http\Controllers\Academics\StudentController;
use App\Http\Controllers\Concerns\HasProfileFields;
use App\Http\Controllers\Controller;
use App\Services\Students\StudentRecordService;
use App\Support\StudentSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

/**
 * A student's own profile: what the school holds about them, and (when Settings → Students allows it) their contact
 * details, next of kin, health details, referees and signature. Names, programme and level stay with staff.
 */
class MyProfileController extends Controller
{
    use HasProfileFields;

    /** What a student may change here. */
    private const EDITABLE = ['phone_number', 'address', 'guardian_name', 'guardian_relationship', 'guardian_phone', 'guardian_address', 'next_of_kin_email', 'religion', 'marital_status', 'blood_group', 'genotype'];

    public function __construct(private StudentSettings $settings, private StudentRecordService $records)
    {
    }

    public function edit(Request $request): View
    {
        $student = $request->user()->student;
        abort_unless($student, 404);
        $student->load('programme.department.faculty', 'entrySession', 'courseOption', 'subDepartment', 'referees');

        return view('students.my-profile', [
            'pagetitle' => 'My profile', 'student' => $student, 'settings' => $this->settings, 'canUpdate' => $this->settings->on('allow_profile_update'),
            'refereeCount' => (int) $this->settings->get('referees'),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $student = $request->user()->student;
        abort_unless($student, 404);
        abort_unless($this->settings->on('allow_profile_update'), 403, 'The school does not allow students to change their profile. Ask the registry.');

        $rules = collect($this->profileRules())->only(self::EDITABLE)->all();
        if ($this->settings->on('show_nin')) {
            $rules['nin'] = [$this->settings->on('require_nin') ? 'required' : 'nullable', 'digits:11'];
        }
        $rules += StudentController::refereeRules() + ['signature' => ['nullable', 'image', 'mimes:jpg,jpeg,png', 'max:1024']];
        $data = $request->validate($rules);

        $student->update(collect($data)->only([...self::EDITABLE, 'nin'])->all());
        $student->user->update(['phone_number' => $student->phone_number]);
        if ($count = (int) $this->settings->get('referees')) {
            $this->records->saveReferees($student, RefereeData::listFrom($request->input('referees', []), $count));
        }
        if ($request->hasFile('signature')) {
            $path = $request->file('signature')->store('students/signatures', 'public');
            if ($student->signature) {
                Storage::disk('public')->delete($student->signature);
            }
            $student->update(['signature' => $path]);
        }

        return back()->with('success', 'Your profile has been saved.');
    }
}
