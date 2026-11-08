<?php

declare(strict_types=1);

putenv('APP_KEY=base64:' . base64_encode(hash('sha256', 'isolated-refund-fixture', true)));
$fixtureAppRoot = getenv('FIREFLY_TEST_APP_ROOT') ?: dirname(__DIR__, 2);
require $fixtureAppRoot . '/vendor/autoload.php';
$app = require $fixtureAppRoot . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
require dirname(__DIR__, 2) . '/app/Support/Reports/RefundProjection.php';

use FireflyIII\Support\Reports\RefundProjection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

config([
    'database.default'                    => 'refund_fixture',
    'database.connections.refund_fixture' => [
        'driver'                  => 'sqlite',
        'database'                => ':memory:',
        'prefix'                  => '',
        'foreign_key_constraints' => true
    ]
]);
DB::purge('refund_fixture');
$schema = DB::connection()->getSchemaBuilder();
$tables = [
    'configuration'          => ['name:s', 'data:s'],
    'categories'             => ['user_id:i', 'user_group_id:i', 'name:s'],
    'budgets'                => ['user_id:i', 'user_group_id:i', 'name:s'],
    'transaction_groups'     => ['user_id:i', 'user_group_id:i', 'title:s'],
    'transaction_types'      => ['type:s'],
    'transaction_currencies' => ['code:s'],
    'transaction_journals'   => [
        'user_id:i',
        'user_group_id:i',
        'transaction_group_id:i',
        'transaction_type_id:i',
        'transaction_currency_id:i',
        'bill_id:i',
        'description:s',
        'date:s',
        'date_tz:s'
    ],
    'transactions'           => [
        'transaction_journal_id:i',
        'account_id:i',
        'transaction_currency_id:i',
        'amount:s',
        'foreign_currency_id:i',
        'foreign_amount:s'
    ],
    'link_types'             => ['name:s']
];
foreach ($tables as $table => $columns) {
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
foreach (['category_transaction_journal' => 'category_id', 'budget_transaction_journal' => 'budget_id'] as $name => $column) {
    $schema->create($name, static function (Blueprint $t) use ($column): void {
        $t->integer('transaction_journal_id');
        $t->integer($column);
    });
}
$schema->create('journal_links', static function (Blueprint $t): void {
    $t->increments('id');
    $t->integer('source_id');
    $t->integer('destination_id');
    $t->integer('link_type_id');
    $t->timestamps();
});
DB::table('transaction_types')->insert([['id' => 1, 'type' => 'Withdrawal'], ['id' => 2, 'type' => 'Deposit']]);
DB::table('transaction_currencies')->insert(['id' => 1, 'code' => 'UAH']);
DB::table('categories')->insert([
    ['id' => 1, 'user_group_id' => 1, 'name' => 'Цифрові сервіси'],
    ['id' => 2, 'user_group_id' => 1, 'name' => RefundProjection::CATEGORY],
    ['id' => 3, 'user_group_id' => 2, 'name' => RefundProjection::CATEGORY]
]);
DB::table('budgets')->insert(['id' => 1, 'user_group_id' => 1, 'name' => 'Підписки']);
DB::table('link_types')->insert(['id' => 1, 'name' => 'Refund']);
foreach ([1 => [1, 1, 1], 2 => [1, 1, 2], 3 => [2, 2, 1], 4 => [2, 2, 2], 5 => [1, 2, 2], 6 => [1, 1, 2], 7 => [2, 2, 2]] as $id => $shape) {
    [$owner, $groupOwner, $type] = $shape;
    DB::table('transaction_groups')->insert(['id' => $id, 'user_group_id' => $groupOwner]);
    DB::table('transaction_journals')->insert([
        'id'                      => $id,
        'user_group_id'           => $owner,
        'transaction_group_id'    => $id,
        'transaction_type_id'     => $type,
        'transaction_currency_id' => 1,
        'date'                    => '2026-11-04 12:00:00',
        'date_tz'                 => 'Europe/Kyiv'
    ]);
    DB::table('transactions')->insert([
        'transaction_journal_id'  => $id,
        'account_id'              => $type === 1 ? 10 : 20,
        'transaction_currency_id' => 1,
        'amount'                  => '-125'
    ]);
    DB::table('transactions')->insert([
        'transaction_journal_id'  => $id,
        'account_id'              => $type === 1 ? 20 : 10,
        'transaction_currency_id' => 1,
        'amount'                  => '125'
    ]);
}
DB::table('category_transaction_journal')->insert([
    ['transaction_journal_id' => 1, 'category_id' => 1],
    ['transaction_journal_id' => 6, 'category_id' => 2],
    ['transaction_journal_id' => 7, 'category_id' => 3]
]);
DB::table('budget_transaction_journal')->insert(['transaction_journal_id' => 1, 'budget_id' => 1]);
foreach ([[1, 2], [3, 4], [1, 4], [1, 5]] as [$source, $dest]) {
    DB::table('journal_links')->insert(['source_id' => $source, 'destination_id' => $dest, 'link_type_id' => 1]);
}
$app->instance('request', Request::create('/'));
$user   = (object) ['user_group_id' => 1];
$before = json_encode([DB::table('transaction_journals')->get()->all(), DB::table('transactions')->get()->all()]);
$map    = RefundProjection::metadata($user);
if (array_keys($map) !== [2, 6] || $map[2]['original_id'] !== '1' || $map[2]['category_name'] !== 'Цифрові сервіси' || $map[2]['budget_ids'] !== ['1']) {
    throw new RuntimeException('Scoped native refund metadata is incorrect.');
}
if ($map[2]['amount'] !== '125' || $map[2]['currency'] !== 'UAH' || $map[2]['day'] !== '2026-11-04') {
    throw new RuntimeException('Original refund money or date changed.');
}
if (
    $map[6]['original_id'] !== null
    || $map[6]['category_name'] !== RefundProjection::CATEGORY
    || !RefundProjection::isRefund(RefundProjection::annotate([['id' => '6', 'type' => 'Deposit', 'amount' => '125']], $map)[0])
) {
    throw new RuntimeException('An owned manually categorized refund without link must not become income.');
}
DB::table('budget_transaction_journal')->where('transaction_journal_id', 1)->delete();
$app->instance('request', Request::create('/'));
if (RefundProjection::metadata($user)[2]['budget_ids'] !== []) {
    throw new RuntimeException('A removed fully-refunded budget must not create another budget credit.');
}
if (json_encode([DB::table('transaction_journals')->get()->all(), DB::table('transactions')->get()->all()]) !== $before) {
    throw new RuntimeException('Reporting mutated original financial records.');
}
echo "Passed 5 isolated SQLite refund metadata checks.\n";
