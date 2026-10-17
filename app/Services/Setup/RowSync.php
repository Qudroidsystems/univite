<?php

namespace App\Services\Setup;

use App\Data\Setup\RestorePlan;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Captures the rows of one table (or part of it) and brings them back: rows still there are changed back, rows
 * removed since are added back with their old id, and rows added since are removed unless $keep gives a reason to
 * leave them (it may also switch the row off instead).
 */
final class RowSync
{
    /**
     * @param  class-string<Model>  $model
     * @param  Closure(): Builder  $query
     * @param  string[]  $fields
     * @param  ?Closure(Model, bool): ?string  $keep  reason a row added since must stay; the bool says whether to act
     */
    public function __construct(private string $model, private Closure $query, private array $fields, private ?Closure $keep = null)
    {
    }

    /** @return array<int, array{id: int, attrs: array}> */
    public function capture(): array
    {
        return ($this->query)()->orderBy('id')->get()->map(fn (Model $m) => [
            'id' => $m->getKey(),
            'attrs' => array_intersect_key($m->getAttributes(), array_flip($this->fields)),   // as stored, so dates and JSON come back exactly
        ])->values()->all();
    }

    public function restore(array $rows, bool $apply): RestorePlan
    {
        $current = ($this->query)()->get()->keyBy(fn (Model $m) => $m->getKey());
        $added = $updated = $removed = 0;
        $kept = [];
        $wanted = [];

        foreach ($rows as $row) {
            $wanted[$row['id']] = true;
            $model = $current->get($row['id']);
            if ($model) {
                $model->setRawAttributes(array_merge($model->getAttributes(), $row['attrs']));
                if ($model->isDirty()) {
                    $updated++;
                    $apply && $model->save();
                }
                continue;
            }
            $added++;
            if ($apply) {
                $fresh = new ($this->model)();
                $taken = ($this->model)::query()->whereKey($row['id'])->exists();
                $fresh->setRawAttributes(($taken ? [] : ['id' => $row['id']]) + $row['attrs']);
                $fresh->save();
            }
        }

        foreach ($current as $id => $model) {
            if (isset($wanted[$id])) {
                continue;
            }
            if ($this->keep && ($why = ($this->keep)($model, $apply))) {
                $kept[] = $why;
                continue;
            }
            $removed++;
            $apply && $model->delete();
        }

        return new RestorePlan($added, $updated, $removed, array_values(array_unique($kept)));
    }
}
