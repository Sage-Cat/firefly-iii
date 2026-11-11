<?php

require __DIR__ . '/learned_rule_update.php';
$nativeStart = $checks;
$schema      = \Illuminate\Support\Facades\DB::connection()->getSchemaBuilder();
$schema->create('users', static function ($t) {
    $t->increments('id');
    $t->integer('user_group_id');
    $t->string('email');
    $t->timestamps();
    $t->softDeletes();
});
$schema->create('user_groups', static function ($t) {
    $t->increments('id');
    $t->string('title');
    $t->timestamps();
    $t->softDeletes();
});
foreach ([1, 2] as $id) {
    \Illuminate\Support\Facades\DB::table('users')->insert(['id' => $id, 'user_group_id' => $id, 'email' => 'fixture' . $id . '@example.invalid']);
    \Illuminate\Support\Facades\DB::table('user_groups')->insert(['id' => $id, 'title' => 'Synthetic group']);
}
$repository = app(\FireflyIII\Repositories\Rule\RuleRepositoryInterface::class);
$repository->setUser($realUser);
$payload = [
    'rule_group_id'   => 1,
    'title'           => 'Canonical synthetic service',
    'description'     => json_encode([
        'firefly_learned_category' => 1,
        'description'              => 'Synthetic service',
        'type'                     => 'withdrawal',
        'category'                 => 'Продукти'
    ], JSON_UNESCAPED_UNICODE),
    'strict'          => true,
    'active'          => false,
    'stop_processing' => false,
    'trigger'         => 'store-journal',
    'order'           => 3,
    'triggers'        => [
        ['type' => 'transaction_type', 'value' => 'withdrawal', 'active' => true],
        ['type' => 'description_is', 'value' => 'Synthetic service', 'active' => true],
        ['type' => 'has_no_category', 'value' => 'true', 'active' => true]
    ],
    'actions'         => [['type' => 'set_category', 'value' => 'Продукти', 'active' => true]]
];
$created = $repository->store($payload);
$repository->resetRuleOrder($created->ruleGroup);
$created->refresh();
$shape = \FireflyIII\Support\Reports\LearnedRuleUpdate::shape(ruleLoaded($created->id));
verify(array_column($shape['triggers'], 'order') === [1, 3, 4], 'The actual native repository canonicalizes the newly created rule.');
$again = app(\FireflyIII\Repositories\RuleGroup\RuleGroupRepositoryInterface::class);
$again->setUser($realUser);
$again->resetOrder();
verify(
    \FireflyIII\Support\Reports\LearnedRuleUpdate::shape(ruleLoaded($created->id)) === $shape,
    'Actual native UI cleanup keeps the canonical created rule unchanged.'
);
echo 'Passed ' . ($checks - $nativeStart) . " native creation/UI normalization checks.\n";
