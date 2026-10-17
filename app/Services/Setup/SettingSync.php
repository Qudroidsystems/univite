<?php

namespace App\Services\Setup;

use App\Data\Setup\RestorePlan;
use App\Models\Setting;

/** Captures a list of settings and puts them back as they were (a setting that did not exist is removed again). */
final class SettingSync
{
    /** @param  string[]  $keys */
    public function __construct(private array $keys)
    {
    }

    /** @return array<string, ?string> */
    public function capture(): array
    {
        $have = Setting::whereIn('key', $this->keys)->pluck('value', 'key');

        return collect($this->keys)->mapWithKeys(fn ($k) => [$k => $have->get($k)])->all();
    }

    public function restore(array $values, bool $apply): RestorePlan
    {
        $have = Setting::whereIn('key', array_keys($values))->get()->keyBy('key');
        $added = $updated = $removed = 0;
        foreach ($values as $key => $value) {
            $row = $have->get($key);
            if ($value === null) {
                if ($row) {
                    $removed++;
                    $apply && $row->delete();
                }
            } elseif (! $row) {
                $added++;
                $apply && Setting::put($key, $value);
            } elseif ((string) $row->value !== (string) $value) {
                $updated++;
                $apply && Setting::put($key, $value);
            }
        }

        return new RestorePlan($added, $updated, $removed);
    }
}
