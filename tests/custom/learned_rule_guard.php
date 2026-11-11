<?php

declare(strict_types=1);

namespace FireflyIII\Models {
    class Rule
    {
        public int    $id     = 1;
        public string $title  = 'Learned example';
        public bool   $strict = true;
        public bool   $active = true;
        public string $description;
        public $ruleTriggers;
        public $ruleActions;
    }
}

namespace FireflyIII {
    class User {}
}

namespace Illuminate\Support\Facades {
    class Log
    {
        public static function debug(...$args): void {}
    }
}

namespace {
    function app($key)
    {
        return $GLOBALS['search'];
    }

    function config($key)
    {
        return str_ends_with($key, '.needs_context') ? !str_contains($key, 'has_no_category') : 'Europe/Kyiv';
    }

    function today($zone)
    {
        return \Carbon\Carbon::parse('2026-06-14', $zone);
    }

    require (getenv('FIREFLY_TEST_APP_ROOT') ?: '/var/www/html') . '/vendor/autoload.php';
    require dirname(__DIR__, 2) . '/app/Support/Reports/LearnedRuleGuard.php';
    use FireflyIII\Support\Reports\LearnedRuleGuard;
    use Illuminate\Support\Collection;

    $checks = 0;
    function check(bool $value, string $message): void
    {
        ++$GLOBALS['checks'];
        if (!$value) {
            throw new RuntimeException($message);
        }
    }

    function rule(): \FireflyIII\Models\Rule
    {
        $rule               = new \FireflyIII\Models\Rule();
        $rule->description  = json_encode(['firefly_learned_category' => 1, 'description' => 'Exact shop', 'type' => 'withdrawal', 'category' => 'Purchases']);
        $rule->ruleTriggers = new Collection(array_map(
            static fn($type, $value) => (object) ['trigger_type' => $type, 'trigger_value' => $value, 'active' => true],
            ['user_action', 'description_is', 'transaction_type', 'has_no_category'],
            ['store-journal', 'Exact shop', 'withdrawal', 'true']
        ));
        $rule->ruleActions = new Collection([(object) ['action_type' => 'set_category', 'action_value' => 'Purchases', 'active' => true]]);
        return $rule;
    }

    $matching = [
        'description'            => 'Exact shop',
        'transaction_type_type'  => 'Withdrawal',
        'category_id'            => null,
        'amount'                 => '12.34',
        'transaction_journal_id' => '9'
    ];
    $groups   = new Collection([
        ['id' => '1', 'title' => 'Unrelated title', 'transactions' => [$matching, array_replace($matching, ['description' => 'Transfer to card'])]],
        ['id' => '2', 'title' => 'Exact shop', 'transactions' => [array_replace($matching, ['description' => 'Transfer to card'])]],
        ['id' => '3', 'title' => 'Exact shop', 'transactions' => [array_replace($matching, ['description' => 'exact shop'])]],
        ['id' => '4', 'title' => 'Exact shop', 'transactions' => [array_replace($matching, ['transaction_type_type' => 'Deposit'])]],
        ['id' => '5', 'title' => 'Exact shop', 'transactions' => [array_replace($matching, ['category_id' => '2'])]]
    ]);
    $original = serialize($groups);
    $rule     = rule();
    $result   = LearnedRuleGuard::filter($rule, $rule->ruleTriggers, $groups);
    check(
        $result->count() === 1 && $result->first()['transactions'] === [$matching],
        'Only the exact uncategorized withdrawal survives; titles, case aliases, direction and explicit categories cannot widen learned rules.'
    );
    check(serialize($groups) === $original, 'Guard never mutates input groups or monetary values.');
    foreach (['', 'manual rule', '{bad', json_encode(['firefly_learned_category' => 2])] as $description) {
        $r              = rule();
        $r->description = $description;
        check(LearnedRuleGuard::filter($r, $r->ruleTriggers, $groups) === $groups, 'Unmanaged or owner-edited metadata retains native behavior.');
    }
    $r                                 = rule();
    $r->ruleTriggers[1]->trigger_value = 'Owner changed description';
    check(LearnedRuleGuard::filter($r, $r->ruleTriggers, $groups) === $groups, 'Owner changes to native conditions transfer control.');
    $r                               = rule();
    $r->ruleActions[0]->action_value = 'Owner category';
    check(LearnedRuleGuard::filter($r, $r->ruleTriggers, $groups) === $groups, 'Owner action edits are preserved.');
    $r = rule();
    $r->ruleTriggers->push(clone $r->ruleTriggers[0]);
    check(LearnedRuleGuard::filter($r, $r->ruleTriggers, $groups) === $groups, 'Unexpected or duplicate conditions are unmanaged.');
    $r = rule();
    $r->ruleActions->push(clone $r->ruleActions[0]);
    check(LearnedRuleGuard::filter($r, $r->ruleTriggers, $groups) === $groups, 'Additional owner actions are unmanaged.');
    check(LearnedRuleGuard::filter($rule, $rule->ruleTriggers, new Collection([
        null,
        ['id' => 7],
        ['transactions' => 'bad']
    ]))->isEmpty(), 'Malformed managed search results fail closed.');

    require dirname(__DIR__, 2) . '/app/TransactionRules/Engine/SearchRuleEngine.php';
    $GLOBALS['search'] = new class($groups) {
        public function __construct(
            private Collection $groups
        ) {}

        public function parseQuery($v): void {}

        public function searchTransactions(): object
        {
            return new class($this->groups) {
                public function __construct(
                    private Collection $groups
                ) {}

                public function getCollection(): Collection
                {
                    return $this->groups;
                }
            };
        }

        public function setDate($v): void {}

        public function setLimit($v): void {}

        public function setPage($v): void {}

        public function setUser($v): void {}
    };
    $engine = new \FireflyIII\TransactionRules\Engine\SearchRuleEngine();
    $engine->setUser(new \FireflyIII\User());
    $engine->setRefreshTriggers(false);
    $engine->setRules(new Collection([$rule]));
    check($engine->find()->all() === $result->all(), 'Actual native strict engine applies the journal-only guard before actions.');
    echo 'Passed ' . $checks . " learned native rule guard checks.\n";
}
