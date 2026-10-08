<?php

namespace App\Support;

use App\Models\Applicant;
use App\Models\CustomField;
use App\Models\CustomFieldValue;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Admin-defined extra fields for the applicant, student and staff forms: which questions exist,
 * how they are rendered and validated, and where the answers are stored.
 */
class CustomFields
{
    /** Active questions for a form, in order. $selfServiceOnly = only those the person fills in themselves. */
    public static function defs(string $target, bool $selfServiceOnly = false): Collection
    {
        return CustomField::where('target', $target)->where('is_active', true)
            ->when($selfServiceOnly, fn ($q) => $q->where('self_service', true))
            ->orderBy('sort_order')->orderBy('id')->get();
    }

    /** Field definitions in the shape the admin CRUD form uses. */
    public static function formFields(?string $target, bool $selfServiceOnly = false): array
    {
        if (! $target) {
            return [];
        }

        return self::defs($target, $selfServiceOnly)->map(fn (CustomField $f) => [
            'name'     => $f->inputName(),
            'label'    => $f->label,
            'type'     => match ($f->type) { 'checkbox' => 'switch', default => $f->type },
            'options'  => $f->type === 'select' ? array_combine($f->options ?? [], $f->options ?? []) : null,
            'required' => false,   // admins are never blocked on older records; self-service forms enforce required
            'col'      => in_array($f->type, ['textarea'], true) ? 12 : 6,
            'help'     => $f->help_text,
            'accept'   => $f->type === 'file' ? '.' . str_replace(',', ',.', $f->accept) : null,
            'section'  => $f->section,
            'custom'   => true,
        ])->all();
    }

    /**
     * Validation rules. $enforceRequired = true on self-service forms and when creating a record.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function rules(?string $target, ?Model $subject = null, bool $enforceRequired = false, bool $selfServiceOnly = false): array
    {
        if (! $target) {
            return [];
        }

        $existing = $subject ? self::valuesFor($subject)->keyBy('custom_field_id') : collect();
        $rules = [];

        foreach (self::defs($target, $selfServiceOnly) as $f) {
            $has = $existing->has($f->id) && ($f->type === 'file' ? $existing[$f->id]->file_path : filled($existing[$f->id]->value));
            $base = $enforceRequired && $f->is_required && ! $has ? ['required'] : ['nullable'];

            $rules[$f->inputName()] = array_merge($base, match ($f->type) {
                'text'     => ['string', 'max:1000'],
                'textarea' => ['string', 'max:5000'],
                'number'   => ['numeric', 'between:-999999999999,999999999999'],
                'date'     => ['date'],
                'select'   => [Rule::in($f->options ?? [])],
                'checkbox' => $f->is_required && $enforceRequired ? ['accepted'] : ['boolean'],
                'file'     => ['file', 'mimes:' . $f->accept, 'max:' . $f->max_kb],
            });
        }

        return $rules;
    }

    public static function attributeNames(?string $target, bool $selfServiceOnly = false): array
    {
        return $target ? self::defs($target, $selfServiceOnly)->mapWithKeys(fn ($f) => [$f->inputName() => strtolower($f->label)])->all() : [];
    }

    /** @return Collection<int, CustomFieldValue> */
    public static function valuesFor(Model $subject): Collection
    {
        return CustomFieldValue::where('subject_type', $subject::class)->where('subject_id', $subject->getKey())->get();
    }

    /** Store the answers sent with a request. Only the questions in $selfServiceOnly scope are touched when it is set. */
    public static function save(?string $target, Model $subject, Request $request, bool $selfServiceOnly = false): void
    {
        if (! $target) {
            return;
        }

        $existing = self::valuesFor($subject)->keyBy('custom_field_id');

        foreach (self::defs($target, $selfServiceOnly) as $f) {
            $name = $f->inputName();
            $row = $existing->get($f->id);

            if ($f->type === 'file') {
                if (! $request->hasFile($name)) {
                    continue;
                }
                $file = $request->file($name);
                if ($row?->file_path) {
                    Storage::disk('local')->delete($row->file_path);
                }
                $path = $file->store("custom-fields/{$target}/{$subject->getKey()}", 'local');
                CustomFieldValue::updateOrCreate(
                    ['custom_field_id' => $f->id, 'subject_type' => $subject::class, 'subject_id' => $subject->getKey()],
                    ['file_path' => $path, 'original_name' => $file->getClientOriginalName(), 'size' => $file->getSize(), 'value' => null]
                );
                continue;
            }

            if (! $request->has($name) && $f->type !== 'checkbox') {
                continue;   // not on this form: leave the old answer alone
            }

            $value = $f->type === 'checkbox' ? ($request->boolean($name) ? '1' : '0') : trim((string) $request->input($name));

            if ($value === '' && $row) {
                $row->delete();
            } elseif ($value !== '') {
                CustomFieldValue::updateOrCreate(
                    ['custom_field_id' => $f->id, 'subject_type' => $subject::class, 'subject_id' => $subject->getKey()],
                    ['value' => $value]
                );
            }
        }
    }

    /** Current answers keyed by input name, for pre-filling an edit form. File answers add "<name>__file" with the file name. */
    public static function recordData(?string $target, Model $subject): array
    {
        if (! $target) {
            return [];
        }
        $values = self::valuesFor($subject)->keyBy('custom_field_id');
        $out = [];

        foreach (self::defs($target) as $f) {
            $v = $values->get($f->id);
            if ($f->type === 'file') {
                $out[$f->inputName() . '__file'] = $v?->original_name;
            } else {
                $out[$f->inputName()] = $f->type === 'checkbox' ? ($v?->value === '1') : $v?->value;
            }
        }

        return $out;
    }

    /**
     * Answers ready to show on a profile page.
     *
     * @return array<int, array{label: string, section: ?string, type: string, text: ?string, file: ?CustomFieldValue}>
     */
    public static function display(string $target, Model $subject): array
    {
        $values = self::valuesFor($subject)->keyBy('custom_field_id');

        return self::defs($target)->map(function (CustomField $f) use ($values) {
            $v = $values->get($f->id);
            $text = match ($f->type) {
                'checkbox' => $v ? ($v->value === '1' ? 'Yes' : 'No') : null,
                'date'     => $v?->value ? \Illuminate\Support\Carbon::parse($v->value)->format('j M Y') : null,
                'file'     => $v?->original_name,
                default    => $v?->value,
            };

            return ['label' => $f->label, 'section' => $f->section, 'type' => $f->type, 'text' => $text, 'file' => $f->type === 'file' ? $v : null];
        })->all();
    }

    /** Required self-service questions this person has not answered yet (labels). */
    public static function missingRequired(string $target, Model $subject): array
    {
        $values = self::valuesFor($subject)->keyBy('custom_field_id');

        return self::defs($target, true)->filter(function (CustomField $f) use ($values) {
            if (! $f->is_required) {
                return false;
            }
            $v = $values->get($f->id);

            return match ($f->type) {
                'file'     => ! $v?->file_path,
                'checkbox' => $v?->value !== '1',
                default    => blank($v?->value),
            };
        })->pluck('label')->values()->all();
    }

    /** May this signed-in user download this answer's file? */
    public static function canDownload(User $user, CustomFieldValue $value): bool
    {
        return match ($value->subject_type) {
            Student::class   => $user->can('View students') || $user->student?->id === $value->subject_id,
            Applicant::class => $user->can('View applicants'),
            User::class      => $user->can('View users') || $user->id === $value->subject_id,
            default          => false,
        };
    }

    public static function slug(string $label): string
    {
        return Str::of($label)->lower()->ascii()->replaceMatches('/[^a-z0-9]+/', '_')->trim('_')->limit(40, '')->toString() ?: 'field';
    }
}
