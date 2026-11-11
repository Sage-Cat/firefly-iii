<?php

declare(strict_types=1);

namespace FireflyIII\Support\Reports;

use FireflyIII\Events\Model\TransactionGroup\TransactionGroupRequestsAuditLogEntry;
use FireflyIII\Models\Category;
use FireflyIII\Models\TransactionGroup;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Keep generated exact-description rules independent of a group's display title. */
final class LearnedRuleGuard
{
    /** null delegates an owner-managed rule to the unchanged native action. */
    public static function applyCategory($action, array $snapshot): null|bool
    {
        $rule     = $action->rule;
        $metadata = self::definition($rule, $rule->ruleTriggers);
        if (null === $metadata) {
            return null;
        }
        $groupId   = $snapshot['transaction_group_id'] ?? null;
        $journalId = $snapshot['transaction_journal_id'] ?? null;
        $owner     = $snapshot['user_group_id'] ?? null;
        foreach ([$groupId, $journalId, $owner] as $identifier) {
            if (!is_scalar($identifier) || !preg_match('/^[1-9][0-9]*$/D', (string) $identifier)) {
                return false;
            }
        }

        return DB::transaction(static function () use ($groupId, $journalId, $owner, $metadata, $rule): bool {
            $group = TransactionGroup::query()->where('id', $groupId)->where('user_group_id', $owner)->lockForUpdate()->first();
            if (null === $group || (string) $rule->user_group_id !== (string) $owner) {
                return false;
            }
            $journal = $group->transactionJournals()->where('id', $journalId)->where('user_group_id', $owner)->lockForUpdate()->first();
            if (null === $journal) {
                return false;
            }
            $existing = DB::table('category_transaction_journal')->where('transaction_journal_id', $journalId)->lockForUpdate()->get();
            if (
                !$existing->isEmpty()
                || $journal->description !== $metadata['description']
                || strtolower((string) $journal->transactionType?->type) !== $metadata['type']
            ) {
                return false;
            }
            $categories = Category::query()->where('user_group_id', $owner)->where('name', $metadata['category'])->lockForUpdate()->get();
            if (1 !== $categories->count() || $categories->first()->name !== $metadata['category']) {
                return false;
            }
            DB::table('category_transaction_journal')->insert(['transaction_journal_id' => $journalId, 'category_id' => $categories->first()->id]);
            event(new TransactionGroupRequestsAuditLogEntry($rule, $journal, 'set_category', null, $metadata['category']));

            return true;
        });
    }

    public static function filter($rule, iterable $triggers, Collection $groups): Collection
    {
        $metadata = self::definition($rule, $triggers);
        if (null === $metadata) {
            return $groups;
        }
        return $groups
            ->map(static function ($group) use ($metadata): array {
                $rows = is_array($group) && is_array($group['transactions'] ?? null) ? $group['transactions'] : [];
                $rows = array_filter(
                    $rows,
                    static fn($row): bool => (
                        is_array($row)
                        && ($row['description'] ?? null) === $metadata['description']
                        && strtolower((string) ($row['transaction_type_type'] ?? '')) === $metadata['type']
                        && in_array($row['category_id'] ?? null, [null, ''], true)
                    )
                );

                return is_array($group) ? array_replace($group, ['transactions' => array_values($rows)]) : ['transactions' => []];
            })
            ->filter(static fn($group): bool => [] !== $group['transactions'])
            ->values();
    }

    private static function definition($rule, iterable $triggers): null|array
    {
        $metadata = json_decode((string) $rule->description, true);
        if (!is_array($metadata)) {
            $metadata = json_decode(html_entity_decode((string) $rule->description, ENT_QUOTES | ENT_HTML5, 'UTF-8'), true);
        }
        if (
            !is_array($metadata)
            || 1 !== ($metadata['firefly_learned_category'] ?? null)
            || !is_string($metadata['description'] ?? null)
            || '' === $metadata['description']
            || !in_array($metadata['type'] ?? null, ['withdrawal', 'deposit'], true)
            || !is_string($metadata['category'] ?? null)
            || '' === $metadata['category']
        ) {
            return null;
        }
        // Editing the native conditions or action transfers control to the owner.
        $expected = [
            'description_is'   => $metadata['description'],
            'transaction_type' => $metadata['type'],
            'has_no_category'  => null,
            'user_action'      => 'store-journal'
        ];
        $seen = [];
        foreach ($triggers as $trigger) {
            $type = (string) $trigger->trigger_type;
            if (
                !$trigger->active
                || !array_key_exists($type, $expected)
                || isset($seen[$type])
                || null !== $expected[$type] && (string) $trigger->trigger_value !== $expected[$type]
            ) {
                return null;
            }
            $seen[$type] = true;
        }
        $actions = $rule->ruleActions;
        if (
            count($seen) !== count($expected)
            || 1 !== $actions->count()
            || !$actions->first()->active
            || 'set_category' !== $actions->first()->action_type
            || $metadata['category'] !== $actions->first()->action_value
        ) {
            return null;
        }

        return $metadata;
    }
}
