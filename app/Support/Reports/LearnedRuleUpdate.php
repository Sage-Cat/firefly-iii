<?php

declare(strict_types=1);

namespace FireflyIII\Support\Reports;

use FireflyIII\Models\Rule;
use FireflyIII\Models\RuleGroup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Atomically change only the active flag of an unchanged owned generated rule. */
final class LearnedRuleUpdate
{
    public static function fingerprint(array $shape): string
    {
        $sort = static function ($value) use (&$sort) {
            if (!is_array($value)) {
                return $value;
            }
            if (!array_is_list($value)) {
                ksort($value, SORT_STRING);
            }
            return array_map($sort, $value);
        };
        return hash('sha256', json_encode(
            $sort($shape),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS
        ));
    }

    public static function shape(Rule $rule): array
    {
        $shape = [];
        foreach (['title', 'description', 'strict', 'active', 'stop_processing', 'order'] as $key) {
            $shape[$key] = $rule->{$key};
        }
        $shape['rule_group_id'] = (string) $rule->rule_group_id;
        $shape['trigger']       = null;
        $shape['triggers']      = [];
        foreach ($rule->ruleTriggers as $trigger) {
            if ('user_action' === $trigger->trigger_type) {
                $shape['trigger'] = $trigger->trigger_value;
                continue;
            }
            $type                = ltrim((string) $trigger->trigger_type, '-');
            $value               = false === config('search.operators.' . $type . '.needs_context', true) ? 'true' : (string) $trigger->trigger_value;
            $shape['triggers'][] = [
                'type'            => $type,
                'value'           => $value,
                'active'          => $trigger->active,
                'stop_processing' => $trigger->stop_processing,
                'order'           => $trigger->order,
                'prohibited'      => str_starts_with((string) $trigger->trigger_type, '-')
            ];
        }
        $shape['actions'] = [];
        foreach ($rule->ruleActions as $action) {
            $shape['actions'][] = [
                'type'            => $action->action_type,
                'value'           => $action->action_value,
                'active'          => $action->active,
                'stop_processing' => $action->stop_processing,
                'order'           => $action->order
            ];
        }
        return $shape;
    }

    public static function update(Request $request, Rule $rule): JsonResponse
    {
        $expected = $request->header('If-Match-Learned-Rule');
        $data     = $request->all();
        if (!is_string($expected) || !preg_match('/^[0-9a-f]{64}$/D', $expected) || array_keys($data) !== ['active'] || !is_bool($data['active'])) {
            return response()->json(['message' => 'Conditional learned rule update accepts one boolean active flag.'], 422);
        }
        $user = auth()->user();
        if ((string) $rule->user_group_id !== (string) $user->user_group_id) {
            return response()->json(['message' => 'Rule not found.'], 404);
        }
        return DB::transaction(static function () use ($rule, $user, $data, $expected): JsonResponse {
            $group  = RuleGroup::query()->where('id', $rule->rule_group_id)->where('user_group_id', $user->user_group_id)->lockForUpdate()->first();
            $locked = Rule::query()->where('id', $rule->id)->where('user_group_id', $user->user_group_id)->lockForUpdate()->first();
            if (null === $group || null === $locked || (string) $locked->rule_group_id !== (string) $group->id) {
                return response()->json(['message' => 'Rule changed since it was read.'], 409);
            }
            foreach (['ruleTriggers', 'ruleActions'] as $relation) {
                $locked->setRelation($relation, $locked->{$relation}()->orderBy('order')->orderBy('id')->lockForUpdate()->get());
            }
            $metadata = json_decode((string) $locked->description, true);
            if (!is_array($metadata)) {
                $metadata = json_decode(html_entity_decode((string) $locked->description, ENT_QUOTES | ENT_HTML5, 'UTF-8'), true);
            }
            if (
                !$group->active
                || !str_starts_with((string) $group->description, 'category-learning:v1;')
                || !is_array($metadata)
                || 1 !== ($metadata['firefly_learned_category'] ?? null)
                || !hash_equals($expected, self::fingerprint(self::shape($locked)))
            ) {
                return response()->json(['message' => 'Learned rule changed since it was read.'], 409);
            }
            $locked->active = $data['active'];
            $locked->save();
            return response()->json(['data' => ['id' => (string) $locked->id, 'type' => 'rules', 'attributes' => ['active' => $locked->active]]]);
        });
    }
}
