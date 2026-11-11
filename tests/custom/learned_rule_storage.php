<?php

declare(strict_types=1);

require __DIR__ . '/payment_review_storage.php';
require dirname(__DIR__, 2) . '/app/Support/Reports/LearnedRuleGuard.php';
use FireflyIII\Models\Rule;
use FireflyIII\Models\RuleAction;
use FireflyIII\Support\Reports\LearnedRuleGuard;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

Event::fake([\FireflyIII\Events\Model\TransactionGroup\TransactionGroupRequestsAuditLogEntry::class]);
$start = $checks;
function managedAction(int $id, string $category = 'Продукти'): RuleAction
{
    $rule = new Rule();
    $rule->forceFill([
        'id'            => 1000,
        'user_group_id' => 1,
        'strict'        => true,
        'active'        => true,
        'description'   => json_encode([
            'firefly_learned_category' => 1,
            'description'              => 'Synthetic bank payment ' . $id,
            'type'                     => 'withdrawal',
            'category'                 => $category
        ])
    ]);
    $rule->setRelation(
        'ruleTriggers',
        new Collection(array_map(
            static fn($type, $value) => (object) ['trigger_type' => $type, 'trigger_value' => $value, 'active' => true],
            ['user_action', 'description_is', 'transaction_type', 'has_no_category'],
            ['store-journal', 'Synthetic bank payment ' . $id, 'withdrawal', 'true']
        ))
    );
    $action = new RuleAction();
    $action->forceFill(['id' => 1001, 'action_type' => 'set_category', 'action_value' => $category, 'active' => true]);
    $action->setRelation('rule', $rule);
    $rule->setRelation('ruleActions', new Collection([$action]));
    return $action;
}

function actionSnapshot(int $id, int $owner = 1): array
{
    return ['transaction_group_id' => $id, 'transaction_journal_id' => $id, 'user_group_id' => $owner];
}

foreach ([20, 21, 22, 23, 24, 25] as $id) {
    fixtureJournal($id);
}
// A row matched the search while uncategorized; the owner chooses a category before action execution.
$stale = actionSnapshot(20);
DB::table('category_transaction_journal')->insert(['transaction_journal_id' => 20, 'category_id' => 3]);
$before = financialState();
verify(LearnedRuleGuard::applyCategory(managedAction(20), $stale) === false, 'A newer owner category must reject a stale learned action.');
verify(
    DB::table('category_transaction_journal')->where('transaction_journal_id', 20)->value('category_id') === 3,
    'The owner category must not be deleted or replaced.'
);
verify(financialState() === $before, 'Rejected learned action changed financial rows.');
verify(
    LearnedRuleGuard::applyCategory(managedAction(21), actionSnapshot(21)) === true,
    'An exactly matching uncategorized owned payment should be classified.'
);
verify(LearnedRuleGuard::applyCategory(managedAction(21), actionSnapshot(21)) === false, 'Repeated action must not duplicate assignments.');
verify(DB::table('category_transaction_journal')->where('transaction_journal_id', 21)->count() === 1, 'Exactly one category assignment should exist.');
DB::table('transaction_journals')->where('id', 22)->update(['description' => 'Owner changed description']);
verify(
    LearnedRuleGuard::applyCategory(managedAction(22), actionSnapshot(22)) === false,
    'A changed message must fail at the locked write, not only at search.'
);
DB::table('transaction_journals')->where('id', 23)->update(['transaction_type_id' => 3]);
verify(LearnedRuleGuard::applyCategory(managedAction(23), actionSnapshot(23)) === false, 'Direction drift must reject the stale action.');
verify(LearnedRuleGuard::applyCategory(managedAction(24), actionSnapshot(24, 2)) === false, 'A foreign group must remain untouched.');
verify(
    LearnedRuleGuard::applyCategory(managedAction(25, 'Чужа категорія'), actionSnapshot(25)) === false,
    'A foreign or deleted target category cannot be created or assigned.'
);
verify(DB::table('categories')->count() === 3, 'Learning actions never create categories.');
$manual                    = managedAction(25);
$manual->rule->description = 'Owner rule';
verify(LearnedRuleGuard::applyCategory($manual, actionSnapshot(25)) === null, 'Unmanaged actions delegate to the unchanged native action.');
verify(LearnedRuleGuard::applyCategory(managedAction(25), ['transaction_journal_id' => 'bad']) === false, 'Malformed native action snapshots fail closed.');

// Execute the actual scoped native action hook, including the stale-category case.
$source = file_get_contents(dirname(__DIR__, 2) . '/app/TransactionRules/Actions/SetCategory.php');
$anchor = "    public function actOnArray(array \$journal): bool\n    {\n";
$guard  = $anchor
. '        $managed = \\FireflyIII\\Support\\Reports\\LearnedRuleGuard::applyCategory($this->action, $journal);'
. "\n        if (null !== \$managed) {\n            return \$managed;\n        }\n\n";
if (!str_contains($source, 'LearnedRuleGuard::applyCategory')) {
    $source = str_replace($anchor, $guard, $source);
}
$path = tempnam(sys_get_temp_dir(), 'learned-action-');
file_put_contents($path, $source);
require $path;
unlink($path);
fixtureJournal(26);
$snapshot = actionSnapshot(26);
$native   = new \FireflyIII\TransactionRules\Actions\SetCategory(managedAction(26));
DB::table('category_transaction_journal')->insert(['transaction_journal_id' => 26, 'category_id' => 3]);
verify($native->actOnArray($snapshot) === false, 'Actual native action must recheck the owner category after search.');
verify(
    DB::table('category_transaction_journal')->where('transaction_journal_id', 26)->value('category_id') === 3,
    'Native action preserves the raced owner category.'
);
fixtureJournal(27);
$before = financialState();
verify(
    new \FireflyIII\TransactionRules\Actions\SetCategory(managedAction(27))->actOnArray(actionSnapshot(27)) === true,
    'The actual native managed action classifies an eligible message.'
);
verify(financialState() === $before, 'The native managed action changes only category metadata.');
echo 'Passed ' . ($checks - $start) . " learned category storage and native race checks.\n";
