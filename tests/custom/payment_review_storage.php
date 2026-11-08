<?php

declare(strict_types=1);

putenv('APP_KEY=base64:' . base64_encode(hash('sha256', 'isolated-review-fixture', true)));
$fixtureAppRoot = getenv('FIREFLY_TEST_APP_ROOT') ?: dirname(__DIR__, 2);
require $fixtureAppRoot . '/vendor/autoload.php';
$app = require $fixtureAppRoot . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
require getenv('REVIEW_SOURCE') ?: dirname(__DIR__, 2) . '/app/Support/Reports/PaymentReview.php';
require getenv('REVIEW_CONTROLLER') ?: dirname(__DIR__, 2) . '/app/Http/Controllers/PaymentReviewController.php';

use FireflyIII\Http\Controllers\PaymentReviewController;
use FireflyIII\Models\Account;
use FireflyIII\Models\TransactionJournal;
use FireflyIII\Repositories\Account\AccountRepositoryInterface;
use FireflyIII\Support\Reports\PaymentReview;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

config([
    'database.default'                    => 'review_fixture',
    'database.connections.review_fixture' => [
        'driver'                  => 'sqlite',
        'database'                => ':memory:',
        'prefix'                  => '',
        'foreign_key_constraints' => true
    ]
]);
DB::purge('review_fixture');
$schema = DB::connection()->getSchemaBuilder();
$tables = [
    'configuration'          => ['name:s', 'data:s'],
    'categories'             => ['user_id:i', 'user_group_id:i', 'name:s'],
    'accounts'               => ['user_id:i', 'user_group_id:i', 'name:s', 'active:i'],
    'transaction_groups'     => ['user_id:i', 'user_group_id:i', 'title:s'],
    'transaction_types'      => ['type:s'],
    'transaction_currencies' => ['code:s'],
    'transaction_journals'   => [
        'user_id:i',
        'user_group_id:i',
        'transaction_group_id:i',
        'transaction_type_id:i',
        'transaction_currency_id:i',
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
    'journal_meta'           => ['transaction_journal_id:i', 'name:s', 'data:s', 'hash:s'],
    'notes'                  => ['noteable_id:i', 'noteable_type:s', 'title:s', 'text:s']
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
$schema->create('category_transaction_journal', static function (Blueprint $t): void {
    $t->integer('transaction_journal_id');
    $t->integer('category_id');
});
foreach ([
    ['id' => 1, 'name' => 'Продукти', 'user_group_id' => 1],
    ['id' => 2, 'name' => 'Чужа категорія', 'user_group_id' => 2],
    ['id' => 3, 'name' => PaymentReview::LEGACY_CATEGORY, 'user_group_id' => 1]
] as $row) {
    DB::table('categories')->insert($row);
}
foreach ([1 => 'Withdrawal', 2 => 'Transfer', 3 => 'Deposit'] as $id => $type) {
    DB::table('transaction_types')->insert(compact('id', 'type'));
}
DB::table('transaction_currencies')->insert(['id' => 1, 'code' => 'UAH']);
foreach ([1 => 1, 2 => 1, 3 => 1, 4 => 2, 5 => 1, 6 => 1] as $id => $group) {
    DB::table('accounts')->insert(['id' => $id, 'user_group_id' => $group, 'name' => 'Account ' . $id, 'active' => 1]);
}
function fixtureJournal(int $id, bool $pending = false, int $group = 1): void
{
    DB::table('transaction_groups')->insert(['id' => $id, 'user_group_id' => $group]);
    DB::table('transaction_journals')->insert([
        'id'                      => $id,
        'user_group_id'           => $group,
        'transaction_group_id'    => $id,
        'transaction_type_id'     => $pending ? 2 : 1,
        'transaction_currency_id' => 1,
        'description'             => 'Synthetic bank payment ' . $id,
        'date'                    => '2026-11-08 09:00:00',
        'date_tz'                 => 'Europe/Kyiv'
    ]);
    foreach ([1 => '-123.45', $pending ? 3 : 2 => '123.45'] as $account => $amount) {
        DB::table('transactions')->insert(['transaction_journal_id' => $id, 'account_id' => $account, 'transaction_currency_id' => 1, 'amount' => $amount]);
    }
    $note = ['proof' => 'Original bank evidence', 'bank_review_aliases' => ['synthetic:other']];
    if ($pending) {
        $note['bank_review'] = [
            'version'    => 1,
            'kind'       => 'own_transfer',
            'status'     => 'pending',
            'key'        => 'synthetic:' . $id,
            'account_id' => '1',
            'direction'  => 'out',
            'currency'   => 'UAH',
            'amount'     => '123.45',
            'date'       => '2026-11-08T09:00:00+02:00'
        ];
    }
    DB::table('notes')->insert(['noteable_id' => $id, 'noteable_type' => TransactionJournal::class, 'text' => json_encode($note, JSON_UNESCAPED_UNICODE)]);
}

foreach ([1, 2, 3, 4, 5, 6, 7] as $id) {
    fixtureJournal($id, $id >= 3, 2 === $id ? 2 : 1);
}
$repository = new class {
    public function getAccountCurrency($account): object
    {
        return (object) ['code' => 5 === $account->id ? 'EUR' : 'UAH'];
    }

    public function getAccountsByType($types)
    {
        return Account::query()->get();
    }

    public function getNoteText($account): string
    {
        return 3 === $account->id ? '{"bank_review_suspense":1,"currency":"UAH"}' : '';
    }

    public function setUser($user): void {}
};
$app->instance(AccountRepositoryInterface::class, $repository);
$user   = (object) ['user_group_id' => 1];
$checks = 0;
function verify(bool $value, string $message): void
{
    global $checks;
    if (!$value) {
        throw new RuntimeException($message);
    }
    ++$checks;
}

function stateOf(int $id): array
{
    return PaymentReview::state(
        TransactionJournal::query()->with(['categories', 'notes', 'transactionType', 'transactions', 'transactionJournalMeta'])->findOrFail($id)
    );
}

function tokenFor(int $id): string
{
    return PaymentReview::fingerprint(stateOf($id));
}

function rejected(callable $call, string $type): void
{
    try {
        $call();
    } catch (Throwable $e) {
        verify($e instanceof $type, 'Unexpected rejection: ' . get_class($e) . ' ' . $e->getMessage());
        return;
    }
    throw new RuntimeException('The unsafe change was accepted.');
}

function financialState(): string
{
    return json_encode([
        'journals' => DB::table('transaction_journals')->orderBy('id')->get()->all(),
        'legs'     => DB::table('transactions')->orderBy('id')->get()->all()
    ], JSON_THROW_ON_ERROR);
}

$before = financialState();
$stale  = tokenFor(1);
verify(false === PaymentReview::save($user, 1, [
    'fingerprint' => $stale,
    'action'      => 'category',
    'category_id' => '1'
]), 'Ordinary category write must not queue a financial decision.');
verify(DB::table('category_transaction_journal')->where('transaction_journal_id', 1)->value('category_id') === 1, 'Chosen category missing.');
rejected(static fn() => PaymentReview::save($user, 1, ['fingerprint' => $stale, 'action' => 'category', 'category_id' => '1']), RuntimeException::class);
rejected(static fn() => PaymentReview::save($user, 2, [
    'fingerprint' => tokenFor(2),
    'action'      => 'category',
    'category_id' => '1'
]), \Illuminate\Database\Eloquent\ModelNotFoundException::class);
rejected(static fn() => PaymentReview::save($user, 7, [
    'fingerprint' => tokenFor(7),
    'action'      => 'external',
    'category_id' => '2'
]), InvalidArgumentException::class);
verify(true === PaymentReview::save($user, 3, [
    'fingerprint' => tokenFor(3),
    'action'      => 'transfer',
    'account_id'  => '2'
]), 'Own-transfer decision must queue.');
$note = json_decode(DB::table('notes')->where('noteable_id', 3)->value('text'), true);
verify($note['proof'] === 'Original bank evidence' && $note['bank_review_aliases'] === ['synthetic:other'], 'Bank evidence or alias was changed.');
verify($note['bank_review']['status'] === 'decided' && $note['bank_review']['decision'] === ['kind' => 'transfer', 'account_id' => '2'], 'Decision missing.');
foreach (['1', '3', '4', '5'] as $peer) {
    rejected(static fn() => PaymentReview::save($user, 4, [
        'fingerprint' => tokenFor(4),
        'action'      => 'transfer',
        'account_id'  => $peer
    ]), InvalidArgumentException::class);
}
verify(true === PaymentReview::save($user, 5, ['fingerprint' => tokenFor(5), 'action' => 'external', 'category_id' => '1']), 'External decision must queue.');
verify(
    DB::table('category_transaction_journal')->where('transaction_journal_id', 5)->count() === 0,
    'Queued transfer decision must not prematurely set ordinary category.'
);
verify(financialState() === $before, 'Classification or queued decisions changed original financial rows.');
DB::table('transaction_groups')->where('id', 6)->update(['user_group_id' => 2]);
$before = financialState();
rejected(static fn() => PaymentReview::save($user, 6, [
    'fingerprint' => tokenFor(6),
    'action'      => 'external',
    'category_id' => '1'
]), \Illuminate\Database\Eloquent\ModelNotFoundException::class);
verify(financialState() === $before, 'Category or decision changes mutated financial rows.');
rejected(static fn() => new PaymentReviewController()->store(Request::create('/transactions/review/7', 'POST', [
    'amount' => '1'
]), 7), \Symfony\Component\HttpKernel\Exception\HttpException::class);
$csrf    = new \FireflyIII\Http\Middleware\VerifyCsrfToken($app, $app['encrypter']);
$session = new \Illuminate\Session\Store('review-fixture', new \Illuminate\Session\ArraySessionHandler(120));
$session->start();
$session->put('_token', 'synthetic-csrf-token');
$request = Request::create('/transactions/review/7', 'POST');
$request->setLaravelSession($session);
rejected(static fn() => $csrf->handle($request, static fn() => response('accepted')), \Illuminate\Session\TokenMismatchException::class);
$request = Request::create('/transactions/review/7', 'POST', ['_token' => 'synthetic-csrf-token']);
$request->setLaravelSession($session);
verify($csrf->handle($request, static fn() => response('accepted'))->getContent() === 'accepted', 'Valid CSRF token was rejected.');
fixtureJournal(8, false);
$realUser = new \FireflyIII\User();
$realUser->forceFill(['id' => 1, 'user_group_id' => 1]);
auth()->shouldUse('web');
auth()->guard('web')->setUser($realUser);
config(['session.driver' => 'array']);
$preferences = new class {
    public int $marks = 0;

    public function mark(): void
    {
        ++$this->marks;
    }
};
\FireflyIII\Support\Facades\Preferences::swap($preferences);
$beforeController = financialState();
$controller       = new PaymentReviewController();
$reply            = $controller->store(Request::create('/transactions/review/8', 'POST', [
    'fingerprint' => tokenFor(8),
    'action'      => 'category',
    'category_id' => '1'
]), 8);
verify($reply->getStatusCode() === 302 && str_contains($reply->getTargetUrl(), '/transactions/review'), 'Category form did not redirect to review.');
verify(
    DB::table('category_transaction_journal')->where('transaction_journal_id', 8)->value('category_id') === 1,
    'Controller did not save the chosen category.'
);
$reply = $controller->store(Request::create('/transactions/review/7', 'POST', [
    'fingerprint' => tokenFor(7),
    'action'      => 'external',
    'category_id' => '1'
]), 7);
verify($reply->getStatusCode() === 302 && $preferences->marks === 2, 'Successful forms must invalidate display caches.');
verify(
    json_decode(DB::table('notes')->where('noteable_id', 7)->value('text'), true)['bank_review']['status'] === 'decided',
    'Controller did not queue the owner decision.'
);
verify(financialState() === $beforeController, 'Successful forms mutated financial rows.');
echo 'Passed ' . $checks . " isolated SQLite review storage checks.\n";
