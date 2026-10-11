<?php

namespace App\Reversals;

use App\Services\OperationAudit;
use App\Http\Controllers\Academics\AcademicRuleController;
use App\Models\OperationBatch;
use App\Models\User;
use App\Support\AcademicRules;
use App\Support\Institution;

/**
 * Put back academic rules or institution-profile values as they were before a change. Anything changed again since is
 * left alone (reverse the later change first).
 */
class SettingsReversal extends BaseReversal
{
    public function __construct(OperationAudit $audit, private AcademicRules $rules)
    {
        parent::__construct($audit);
    }

    public function title(OperationBatch $op): string
    {
        return $op->type === 'rules_change' ? 'Put back the earlier academic rules' : 'Put back the earlier profile values';
    }

    public function allowed(User $user, OperationBatch $op): bool
    {
        return $op->type === 'rules_change'
            ? AcademicRuleController::canEdit($user, $op->meta['scope'] ?? 'university', ($op->meta['id'] ?? 0) ?: null)
            : $user->can('Edit institution profile');
    }

    private function rulesNow(OperationBatch $op): array
    {
        $m = $op->meta;
        $rules = $this->rules;
        $rules->flush();

        return ($m['scope'] === 'university' && ! $m['level'] && ! $m['semester_type_id'])
            ? $rules->university()
            : $rules->own($m['scope'], (int) $m['id'], $m['level'], $m['semester_type_id']);
    }

    private static function describe(array $rules): string
    {
        $parts = [];
        foreach ($rules as $k => $v) {
            if (isset(AcademicRules::DEFS[$k])) {
                $parts[] = AcademicRules::DEFS[$k][1] . ': ' . AcademicRules::display($k, AcademicRules::cast($k, $v));
            }
        }

        return $parts ? implode('; ', $parts) : 'Inherits everything';
    }

    public function plan(OperationBatch $op, ?string $mode = null): ReversalPlan
    {
        $plan = new ReversalPlan('restore', ['restore' => ['Put back', 'Put the earlier values back. Both the change and its reversal stay on the history.']]);
        if ($op->type === 'rules_change') {
            $e = $op->events()->first();
            $now = $this->rulesNow($op);
            $after = $e->after['rules'] ?? [];
            $diff = array_keys(array_filter(AcademicRules::DEFS, fn ($d, $k) => ($now[$k] ?? null) != ($after[$k] ?? null), ARRAY_FILTER_USE_BOTH));
            if ($diff) {
                $plan->block($e->label, self::describe($now), 'These rules have been changed again since. Reverse the later change first.');
                $plan->blockers[] = 'The rules have been changed again since this change.';
            } else {
                $plan->change($e->label, self::describe(array_intersect_key($now, $after + $e->before['rules'])), self::describe($e->before['rules'] ?? []));
            }
            $plan->outcome = 'The rules for ' . $e->label . ' go back to what they were. Registrations, results and graduation lists already worked out are not recalculated.';

            return $plan;
        }

        $raw = Institution::raw();
        foreach ($op->events as $e) {
            $k = $e->after['key'] ?? null;
            if (! $k || ! array_key_exists($k, Institution::FIELDS)) {
                continue;
            }
            ($raw[$k] ?? null) === ($e->after['value'] ?? null)
                ? $plan->change($e->label, $e->after['value'] ?? '(empty)', $e->before['value'] ?? '(empty)', $k)
                : $plan->skip($e->label, $raw[$k] ?? '(empty)', 'Changed again since; left as it is.', $k);
        }
        $plan->outcome = 'The listed profile values go back to what they were before this change.';

        return $plan;
    }

    public function run(OperationBatch $op, ReversalPlan $plan, User $by, string $reason): OperationBatch
    {
        if ($op->type === 'rules_change') {
            $e = $op->events()->first();
            $m = $op->meta;
            $new = $this->begin($op, 'rules_restore', $by, $reason, $m);
            $rules = $this->rules;
            ($m['scope'] === 'university' && ! $m['level'] && ! $m['semester_type_id'])
                ? $rules->saveUniversity(collect(AcademicRules::DEFS)->keys()->mapWithKeys(fn ($k) => [$k => $e->before['rules'][$k] ?? null])->all())
                : $rules->save($m['scope'], (int) $m['id'], $m['level'], $m['semester_type_id'], $e->before['rules'] ?? [], $by->id);
            $this->audit->record($new, 'rules_restored', null, $e->after, $e->before, $reason, $e->id, $e->label);
            $this->audit->finish($new, 1);
            $this->audit->markReversed($op);

            return $new;
        }

        $new = $this->begin($op, 'institution_restore', $by, $reason);
        foreach ($plan->changes() as $item) {
            $e = $op->events->first(fn ($ev) => ($ev->after['key'] ?? null) === $item['key']);
            Institution::put($item['key'], $e->before['value'] ?? null);
            $this->audit->record($new, 'field_restored', null, $e->after, $e->before, $reason, $e->id, $e->label);
        }
        $c = $plan->counts();
        $this->audit->finish($new, $c['change'], 0, $c['skip']);
        if (! $c['skip']) {
            $this->audit->markReversed($op);
        }

        return $new;
    }
}
