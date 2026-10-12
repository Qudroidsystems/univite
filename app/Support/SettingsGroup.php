<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Crypt;

/**
 * A group of portal settings stored as "{prefix}.{key}" rows, described once so the settings page, validation and
 * defaults all come from the same list. Subclasses declare PREFIX and fields().
 *
 * Field: key => [label, type (number|text|textarea|switch|select|color|bill_item|secret|heading), default, help, options (for select), rules]
 * A "secret" is stored encrypted, never shown back on the form, and left alone when the form leaves it empty.
 * A "heading" only splits the form into sections and stores nothing.
 */
abstract class SettingsGroup
{
    abstract protected function prefix(): string;

    /** @return array<string, array{0: string, 1: string, 2: mixed, 3?: string, 4?: array, 5?: array}> */
    abstract public function fields(): array;

    public function get(string $key): ?string
    {
        if (($this->fields()[$key][1] ?? null) === 'secret') {
            $v = Setting::get($this->prefix() . '.' . $key);
            try {
                return $v ? Crypt::decryptString($v) : null;
            } catch (\Throwable) {
                return null;
            }
        }
        $default = $this->fields()[$key][2] ?? null;

        return Setting::get($this->prefix() . '.' . $key, $default === null ? null : (is_bool($default) ? ($default ? '1' : '0') : (string) $default));
    }

    public function int(string $key): int
    {
        return (int) $this->get($key);
    }

    public function float(string $key): float
    {
        return (float) $this->get($key);
    }

    public function on(string $key): bool
    {
        return $this->get($key) === '1';
    }

    /** Values for the settings form. Secrets come back as "1" when one is saved, never as the secret itself. */
    public function all(): array
    {
        return collect($this->fields())->reject(fn ($f) => $f[1] === 'heading')
            ->map(fn ($f, $k) => $f[1] === 'secret' ? (Setting::get($this->prefix() . '.' . $k) ? '1' : null) : $this->get($k))->all();
    }

    /** Validation rules for the settings form. */
    public function rules(): array
    {
        $rules = [];
        foreach ($this->fields() as $key => $f) {
            if ($f[1] === 'heading') {
                continue;
            }
            $rules[$key] = $f[5] ?? match ($f[1]) {
                'number' => ['required', 'numeric', 'min:0', 'max:100000000'],
                'switch' => ['nullable', 'boolean'],
                'select' => ['required', 'in:' . implode(',', array_keys($f[4] ?? []))],
                'color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
                'bill_item' => ['nullable', 'exists:bill_items,id'],
                'textarea' => ['nullable', 'string', 'max:2000'],
                default => ['nullable', 'string', 'max:255'],
            };
        }

        return $rules;
    }

    /** Save validated values (keys not given are left alone); returns [key => [old, new]] for what changed. */
    public function save(array $data): array
    {
        $changed = [];
        foreach ($this->fields() as $key => $f) {
            if (! array_key_exists($key, $data) || $f[1] === 'heading') {
                continue;
            }
            if ($f[1] === 'secret') {
                if (filled($data[$key])) {
                    Setting::put($this->prefix() . '.' . $key, Crypt::encryptString((string) $data[$key]));
                    $changed[$key] = ['(hidden)', '(changed)']; // secrets never reach the audit trail
                }
                continue;
            }
            $new = $f[1] === 'switch' ? (! empty($data[$key]) ? '1' : '0') : (isset($data[$key]) && $data[$key] !== '' ? (string) $data[$key] : null);
            $old = $this->get($key);
            if ($new !== $old) {
                Setting::put($this->prefix() . '.' . $key, $new);
                $changed[$key] = [$old, $new];
            }
        }

        return $changed;
    }
}
