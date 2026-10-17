<?php

namespace App\Services\Activity;

use App\Data\Activity\LastChange;
use App\Models\RecordActivity;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Reads the record activity log. One instance per request: the first row of a table asks for the whole table at once,
 * so a page of rows costs one query.
 */
class RecordActivities
{
    /** @var array<string, ?LastChange> "Type:id" => last change */
    private array $loaded = [];

    /** The last change to $record, loading it together with the rest of $among (the table's rows). */
    public function lastFor(Model $record, iterable $among = []): ?LastChange
    {
        $key = $record->getMorphClass() . ':' . $record->getKey();
        if (! array_key_exists($key, $this->loaded)) {
            $this->load(collect($among)->push($record)->filter(fn ($m) => $m instanceof Model));
        }

        return $this->loaded[$key] ?? null;
    }

    /** @param  Collection<int, Model>  $models */
    public function load(Collection $models): void
    {
        foreach ($models->groupBy(fn (Model $m) => $m->getMorphClass()) as $type => $group) {
            $ids = $group->map(fn ($m) => $m->getKey())->filter()->unique()->values();
            foreach ($ids as $id) {
                $this->loaded["{$type}:{$id}"] = null;
            }
            if ($ids->isEmpty()) {
                continue;
            }
            $latest = RecordActivity::where('subject_type', $type)->whereIn('subject_id', $ids)
                ->selectRaw('MAX(id) as id')->groupBy('subject_id')->pluck('id');
            RecordActivity::with('user.staffProfile', 'user.student')->whereIn('id', $latest)->get()
                ->each(fn ($a) => $this->loaded["{$type}:{$a->subject_id}"] = LastChange::from($a, class_basename($type)));
        }
    }

    /** A record's history, newest first. */
    public function history(string $type, int $id, int $limit = 50): Collection
    {
        return RecordActivity::with('user.staffProfile.department')->where('subject_type', $type)->where('subject_id', $id)
            ->latest('id')->limit($limit)->get();
    }

    /** Staff may look at who changed what; students and applicants may not. */
    public function canView(?User $user): bool
    {
        return $user !== null && $user->student === null
            && ($user->staffProfile !== null || $user->hasRole('Super Admin') || $user->getAllPermissions()->isNotEmpty());
    }

    /** "Course" => "App\Models\Course", only for models of this application. */
    public static function modelClass(string $type): ?string
    {
        $class = 'App\\Models\\' . preg_replace('/[^A-Za-z0-9]/', '', $type);

        return class_exists($class) && is_subclass_of($class, Model::class) ? $class : null;
    }
}
