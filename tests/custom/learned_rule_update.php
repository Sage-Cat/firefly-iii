<?php

declare(strict_types=1);

require __DIR__ . '/learned_rule_storage.php';
require dirname(__DIR__, 2) . '/app/Support/Reports/LearnedRuleUpdate.php';
use FireflyIII\Models\Rule;
use FireflyIII\Models\RuleGroup;
use FireflyIII\Support\Reports\LearnedRuleUpdate;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

$start  = $checks;
$schema = DB::connection()->getSchemaBuilder();
foreach ([
    'rule_groups'   => ['user_id:i', 'user_group_id:i', 'title:s', 'description:s', 'active:i', 'order:i'],
    'rules'         => ['user_id:i', 'user_group_id:i', 'rule_group_id:i', 'title:s', 'description:s', 'active:i', 'strict:i', 'stop_processing:i', 'order:i'],
    'rule_triggers' => ['rule_id:i', 'trigger_type:s', 'trigger_value:s', 'active:i', 'stop_processing:i', 'order:i'],
    'rule_actions'  => ['rule_id:i', 'action_type:s', 'action_value:s', 'active:i', 'stop_processing:i', 'order:i']
] as $table => $columns) {
    $schema->create($table, static function (Blueprint $t) use ($columns): void {
        $t->increments('id');
        foreach ($columns as $column) {
            [$name, $type] = explode(':', $column);
            ('i' === $type ? $t->integer($name) : $t->text($name))->nullable();
        }
        $t->timestamps();
        $t->softDeletes();
    });
}
foreach ([1, 2] as $owner) {
    DB::table('rule_groups')->insert([
        'id'            => $owner,
        'user_id'       => $owner,
        'user_group_id' => $owner,
        'title'         => 'Generated group',
        'description'   => 'category-learning:v1; managed',
        'active'        => 1,
        'order'         => 1
    ]);
}
function ruleFixture(int $id, int $owner = 1): Rule
{
    $r = new Rule();
    $r->forceFill([
        'id'              => $id,
        'user_id'         => $owner,
        'user_group_id'   => $owner,
        'rule_group_id'   => $owner,
        'title'           => 'Learned example',
        'description'     => json_encode([
            'firefly_learned_category' => 1,
            'description'              => 'Example service',
            'type'                     => 'withdrawal',
            'category'                 => 'Продукти'
        ], JSON_UNESCAPED_UNICODE),
        'active'          => false,
        'strict'          => true,
        'stop_processing' => false,
        'order'           => 1
    ]);
    $r->save();
    foreach ([
        'user_action'      => 'store-journal',
        'transaction_type' => 'withdrawal',
        'description_is'   => 'Example service',
        'has_no_category'  => 'true'
    ] as $type => $value) {
        DB::table('rule_triggers')->insert([
            'rule_id'         => $id,
            'trigger_type'    => $type,
            'trigger_value'   => $value,
            'active'          => 1,
            'stop_processing' => 0,
            'order'           => 1 + DB::table('rule_triggers')->where('rule_id', $id)->count(),
            'created_at'      => now(),
            'updated_at'      => now()
        ]);
    }
    DB::table('rule_actions')->insert([
        'rule_id'         => $id,
        'action_type'     => 'set_category',
        'action_value'    => 'Продукти',
        'active'          => 1,
        'stop_processing' => 0,
        'order'           => 1,
        'created_at'      => now(),
        'updated_at'      => now()
    ]);
    return $r;
}

function ruleLoaded(int $id): Rule
{
    return Rule::with([
        'ruleTriggers' => static fn($q) => $q->orderBy('order')->orderBy('id'),
        'ruleActions'  => static fn($q) => $q->orderBy('order')->orderBy('id')
    ])->findOrFail($id);
}

function ruleRequest(bool $active, string $hash, array $extra = []): Request
{
    $r = Request::create('/api/v1/rules/1', 'PUT', array_replace(['active' => $active], $extra));
    $r->headers->set('If-Match-Learned-Rule', $hash);
    return $r;
}

$r       = ruleFixture(1);
$foreign = ruleFixture(2, 2);
$loaded  = ruleLoaded(1);
$before  = LearnedRuleUpdate::shape($loaded);
$hash    = LearnedRuleUpdate::fingerprint($before);
verify(str_contains($loaded->description, '&quot;'), 'Fixture uses real native escaped rule descriptions.');
$reply = LearnedRuleUpdate::update(ruleRequest(true, $hash), $r);
verify($reply->getStatusCode() === 200 && ruleLoaded(1)->active === true, 'Matching native rule snapshot activates atomically.');
$after              = LearnedRuleUpdate::shape(ruleLoaded(1));
$expected           = $before;
$expected['active'] = true;
verify($after === $expected, 'Activation changes only the boolean flag.');
verify(LearnedRuleUpdate::update(ruleRequest(false, $hash), $r)->getStatusCode() === 409, 'A stale active snapshot must reject deactivation.');
$hash = LearnedRuleUpdate::fingerprint($after);
DB::table('rules')->where('id', 1)->update(['title' => 'Owner edited title']);
verify(
    LearnedRuleUpdate::update(ruleRequest(false, $hash), $r)->getStatusCode() === 409 && ruleLoaded(1)->active,
    'A newer owner title must not be disabled after classifier GET.'
);
$hash = LearnedRuleUpdate::fingerprint(LearnedRuleUpdate::shape(ruleLoaded(1)));
DB::table('rule_actions')->where('rule_id', 1)->update(['action_value' => 'Owner category']);
verify(
    LearnedRuleUpdate::update(ruleRequest(false, $hash), $r)->getStatusCode() === 409 && ruleLoaded(1)->active,
    'A newer owner action must reject the automated flag change.'
);
$hash = LearnedRuleUpdate::fingerprint(LearnedRuleUpdate::shape(ruleLoaded(1)));
DB::table('rule_triggers')->where('rule_id', 1)->where('trigger_type', 'description_is')->update(['trigger_value' => 'Owner merchant']);
verify(LearnedRuleUpdate::update(ruleRequest(false, $hash), $r)->getStatusCode() === 409, 'Changed native trigger contents must invalidate the condition.');
$hash = LearnedRuleUpdate::fingerprint(LearnedRuleUpdate::shape(ruleLoaded(1)));
verify(LearnedRuleUpdate::update(ruleRequest(false, $hash, [
    'title' => 'Disallowed update'
]), $r)->getStatusCode() === 422, 'The conditional path cannot replace manual fields.');
verify(LearnedRuleUpdate::update(ruleRequest(false, 'bad'), $r)->getStatusCode() === 422, 'Malformed fingerprints are rejected.');
verify(LearnedRuleUpdate::update(ruleRequest(false, $hash), $foreign)->getStatusCode() === 404, 'A foreign rule remains inaccessible.');
DB::table('rule_groups')->where('id', 1)->update(['active' => 0]);
verify(LearnedRuleUpdate::update(ruleRequest(false, $hash), $r)->getStatusCode() === 409, 'Owner disabling the group must stop learning writes.');
DB::table('rule_groups')->where('id', 1)->update(['active' => 1]);
verify(
    LearnedRuleUpdate::update(ruleRequest(false, $hash), $r)->getStatusCode() === 200 && !ruleLoaded(1)->active,
    'An exact fresh snapshot may deactivate safely.'
);
$shape = LearnedRuleUpdate::shape(ruleLoaded(1));
$other = array_reverse($shape, true);
verify(LearnedRuleUpdate::fingerprint($shape) === LearnedRuleUpdate::fingerprint($other), 'Canonical fingerprints ignore map-key order.');
DB::table('rule_triggers')->where('rule_id', 1)->where('trigger_type', 'has_no_category')->update(['trigger_value' => '']);
$shape  = LearnedRuleUpdate::shape(ruleLoaded(1));
$loaded = ruleLoaded(1);
$realUser->setRelation('userGroup', new \FireflyIII\Models\UserGroup()->forceFill(['id' => 1]));
$loaded->setRelation('user', $realUser);
$native     = app(\FireflyIII\Transformers\RuleTransformer::class)->transform($loaded);
$attributes = [];
foreach (['title', 'description', 'trigger', 'strict', 'active', 'stop_processing', 'order', 'rule_group_id'] as $key) {
    $attributes[$key] = $native[$key];
}
foreach (['triggers', 'actions'] as $kind) {
    $attributes[$kind] = array_map(static fn($r) => array_intersect_key(
        $r,
        array_flip(
            'triggers' === $kind
                ? ['type', 'value', 'active', 'stop_processing', 'order', 'prohibited']
                : ['type', 'value', 'active', 'stop_processing', 'order']
        )
    ), $native[$kind]);
}
verify(
    LearnedRuleUpdate::fingerprint($attributes) === LearnedRuleUpdate::fingerprint($shape),
    'The actual native API transformer and locked-model shape have identical fingerprints.'
);
if (getenv('RULE_HASH_FIXTURE')) {
    file_put_contents(getenv('RULE_HASH_FIXTURE'), json_encode(['shape' => $shape, 'hash' => LearnedRuleUpdate::fingerprint($shape)], JSON_UNESCAPED_UNICODE));
}
echo 'Passed ' . ($checks - $start) . " atomic learned rule API checks.\n";
