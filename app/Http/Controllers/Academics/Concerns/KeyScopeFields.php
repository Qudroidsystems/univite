<?php

namespace App\Http\Controllers\Academics\Concerns;

use App\Models\Department;
use App\Models\Faculty;
use App\Models\StudyCategory;
use Illuminate\Http\Request;

/** The school / department / category fields shared by the key to grade and key to result pages. */
trait KeyScopeFields
{
    protected function scopeFields(): array
    {
        return [
            ['name' => 'faculty_id', 'label' => 'School', 'type' => 'select', 'options' => Faculty::orderBy('name')->pluck('name', 'id')->all(), 'blank' => 'All schools', 'col' => 4, 'lookup' => 'faculties'],
            ['name' => 'department_id', 'label' => 'Department', 'type' => 'select', 'options' => Department::orderBy('name')->pluck('name', 'id')->all(), 'blank' => 'All departments', 'col' => 4, 'lookup' => 'departments', 'lookup_parent' => 'faculty_id'],
            ['name' => 'study_category_id', 'label' => 'Student category', 'type' => 'select', 'options' => StudyCategory::orderBy('name')->pluck('name', 'id')->all(), 'blank' => 'All categories', 'col' => 4],
        ];
    }

    protected function scopeRules(Request $request): array
    {
        return [
            'faculty_id' => ['nullable', 'exists:faculties,id'],
            'department_id' => ['nullable', 'exists:departments,id', function ($attr, $value, $fail) use ($request) {
                $faculty = $request->input('faculty_id');
                if ($faculty && (int) Department::whereKey($value)->value('faculty_id') !== (int) $faculty) {
                    $fail('That department is not in the chosen school.');
                }
            }],
            'study_category_id' => ['nullable', 'exists:study_categories,id'],
        ];
    }

    /** @return array{0: ?int, 1: ?int, 2: ?int} school, department, category from the form; a department implies its school. */
    protected function scopeInput(Request $request): array
    {
        $dept = $request->filled('department_id') ? (int) $request->input('department_id') : null;
        $faculty = $dept ? (int) Department::whereKey($dept)->value('faculty_id') : ($request->filled('faculty_id') ? (int) $request->input('faculty_id') : null);

        return [$faculty, $dept, $request->filled('study_category_id') ? (int) $request->input('study_category_id') : null];
    }

    /** Store the school of a chosen department, so the row matches results of that department. */
    protected function prepare(array $data, Request $request, $record): array
    {
        [$data['faculty_id'], $data['department_id'], $data['study_category_id']] = $this->scopeInput($request);

        return $data;
    }

    protected function filters(): array
    {
        return [
            ['faculty_id', 'School', Faculty::orderBy('name')->pluck('name', 'id')->all()],
            ['department_id', 'Department', Department::orderBy('name')->pluck('name', 'id')->all()],
            ['study_category_id', 'Category', StudyCategory::orderBy('name')->pluck('name', 'id')->all()],
        ];
    }
}
