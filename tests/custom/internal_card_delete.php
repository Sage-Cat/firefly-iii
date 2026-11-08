<?php

declare(strict_types=1);

putenv('APP_KEY=base64:' . base64_encode(hash('sha256', 'isolated-internal-card-fixture', true)));
$fixtureAppRoot = getenv('FIREFLY_TEST_APP_ROOT') ?: dirname(__DIR__, 2);
require $fixtureAppRoot . '/vendor/autoload.php';
$app = require $fixtureAppRoot . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
require getenv('INTERNAL_CARD_SOURCE') ?: dirname(__DIR__, 2) . '/app/Support/Reports/PendingInternalCardDelete.php';
require dirname(__DIR__, 2) . '/app/Api/V1/Controllers/Models/Transaction/DestroyController.php';

use FireflyIII\Models\Account;
use FireflyIII\Models\TransactionGroup;
use FireflyIII\Models\TransactionJournal;
use FireflyIII\Repositories\TransactionGroup\TransactionGroupRepository;
use FireflyIII\Support\Facades\Preferences;
use FireflyIII\Support\Reports\PendingInternalCardDelete;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\SQLiteGrammar;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

config([
    'database.default'                           => 'internal_card_fixture',
    'database.connections.internal_card_fixture' => [
        'driver'                  => 'sqlite',
        'database'                => ':memory:',
        'prefix'                  => '',
        'foreign_key_constraints' => true
    ]
]);
DB::purge('internal_card_fixture');
$connection = DB::connection();
$grammar    = new class($connection) extends SQLiteGrammar {
    public array $locked = [];

    public function compileSelect(Builder $query)
    {
        if (true === $query->lock) {
            if (DB::connection()->transactionLevel() < 1) {
                throw new RuntimeException('Lock read outside the atomic transaction.');
            }
            $this->locked[] = $query->from;
        }

        return parent::compileSelect($query);
    }
};
$connection->setQueryGrammar($grammar);
$schema = $connection->getSchemaBuilder();
foreach ([
    'account_types'          => ['type:s'],
    'users'                  => ['user_group_id:i', 'email:s'],
    'user_groups'            => ['title:s'],
    'budgets'                => ['user_id:i', 'user_group_id:i', 'name:s'],
    'categories'             => ['user_id:i', 'user_group_id:i', 'name:s'],
    'attachments'            => ['attachable_id:i', 'attachable_type:s'],
    'locations'              => ['locatable_id:i', 'locatable_type:s'],
    'journal_links'          => ['source_id:i', 'destination_id:i'],
    'piggy_bank_events'      => ['transaction_journal_id:i'],
    'audit_log_entries'      => ['auditable_id:i', 'auditable_type:s'],
    'accounts'               => ['user_id:i', 'user_group_id:i', 'account_type_id:i', 'name:s', 'active:i'],
    'account_meta'           => ['account_id:i', 'name:s', 'data:s'],
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
    'journal_meta'           => ['transaction_journal_id:i', 'name:s', 'data:s', 'hash:s'],
    'transactions'           => [
        'transaction_journal_id:i',
        'account_id:i',
        'transaction_currency_id:i',
        'amount:s',
        'foreign_currency_id:i',
        'foreign_amount:s',
        'reconciled:i'
    ],
    'notes'                  => ['noteable_id:i', 'noteable_type:s', 'title:s', 'text:s'],
    'tags'                   => ['user_id:i', 'user_group_id:i', 'tag:s']
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
foreach (['category' => 'category_id', 'budget' => 'budget_id', 'tag' => 'tag_id'] as $name => $id) {
    $schema->create($name . '_transaction_journal', static function (Blueprint $t) use ($id): void {
        $t->integer('transaction_journal_id');
        $t->integer($id);
    });
}
$user = new \FireflyIII\User();
$user->forceFill(['id' => 1, 'user_group_id' => 1]);
auth()->setUser($user);
Preferences::swap(new class {
    public int $marks = 0;

    public function mark(): void
    {
        ++$this->marks;
    }
});
$checks = 0;
function verify(bool $value, string $message): void
{
    global $checks;
    if (!$value) {
        throw new RuntimeException($message);
    }
    ++$checks;
}

// The repository double proves dispatch happens under the transaction and uses fresh models.
$repository = new class extends TransactionGroupRepository {
    public int  $calls  = 0;
    public bool $fail   = false;
    public bool $native = false;

    public function destroy(TransactionGroup $group): void
    {
        verify(DB::connection()->transactionLevel() === 1, 'Repository destroy must stay under the lock transaction.');
        verify($group->transactionJournals->count() === 1, 'Stale route-bound relations reached repository destroy.');
        ++$this->calls;
        if ($this->native) {
            parent::destroy($group);
            return;
        }
        \Illuminate\Database\Eloquent\Model::withoutEvents(static function () use ($group): void {
            foreach ($group->transactionJournals as $journal) {
                $journal->transactions()->delete();
                $journal->delete();
            }
            $group->delete();
        });
        if ($this->fail) {
            throw new RuntimeException('Synthetic repository failure.');
        }
    }
};
function fixture(): void
{
    foreach ([
        'attachments',
        'locations',
        'journal_links',
        'piggy_bank_events',
        'journal_meta',
        'notes',
        'transactions',
        'transaction_journals',
        'transaction_groups',
        'account_meta',
        'accounts',
        'account_types',
        'transaction_types',
        'transaction_currencies',
        'tag_transaction_journal',
        'category_transaction_journal',
        'budget_transaction_journal',
        'tags'
    ] as $table) {
        DB::table($table)->delete();
    }
    DB::table('account_types')->insert(['id' => 1, 'type' => 'Asset account']);
    DB::table('transaction_types')->insert([['id' => 1, 'type' => 'Transfer'], ['id' => 2, 'type' => 'Deposit']]);
    DB::table('transaction_currencies')->insert(['id' => 1, 'code' => 'UAH']);
    foreach ([11, 12] as $id) {
        DB::table('accounts')->insert([
            'id'              => $id,
            'user_id'         => 1,
            'user_group_id'   => 1,
            'account_type_id' => 1,
            'name'            => 'Synthetic account ' . $id,
            'active'          => 1
        ]);
        DB::table('account_meta')->insert(['account_id' => $id, 'name' => 'currency_id', 'data' => '1']);
    }
    DB::table('notes')->insert(['noteable_id' => 11, 'noteable_type' => Account::class, 'text' => '{"bank_review_suspense":1,"currency":"UAH"}']);
    DB::table('transaction_groups')->insert(['id' => 701, 'user_id' => 1, 'user_group_id' => 1]);
    DB::table('transaction_journals')->insert([
        'id'                      => 1701,
        'user_id'                 => 1,
        'user_group_id'           => 1,
        'transaction_group_id'    => 701,
        'transaction_type_id'     => 1,
        'transaction_currency_id' => 1,
        'description'             => "Synthetic / картки\u{2028}",
        'date'                    => '2026-11-08 11:00:00',
        'date_tz'                 => 'Europe/Kyiv'
    ]);
    foreach ([11 => '-123.45', 12 => '123.45'] as $id => $amount) {
        DB::table('transactions')->insert(['transaction_journal_id' => 1701, 'account_id' => $id, 'transaction_currency_id' => 1, 'amount' => $amount]);
    }
    foreach ([
        'external_id'        => 'synthetic:bank:row',
        'internal_reference' => 'synthetic-import',
        'original_source'    => 'synthetic-source',
        'import_hash_v2'     => 'synthetic-import-hash'
    ] as $name => $value) {
        DB::table('journal_meta')->insert(['transaction_journal_id' => 1701, 'name' => $name, 'data' => json_encode($value)]);
    }
    DB::table('notes')->insert([
        'noteable_id'   => 1701,
        'noteable_type' => TransactionJournal::class,
        'text'          => json_encode([
            'proof'       => 'Synthetic / доказ',
            'bank_review' => [
                'version'       => 1,
                'kind'          => 'own_transfer',
                'status'        => 'pending',
                'account_scope' => 'same_asset_cards',
                'direction'     => 'in',
                'account_id'    => '12',
                'currency'      => 'UAH',
                'amount'        => '123.45'
            ]
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    ]);
    foreach ([1 => 'Банк', 2 => 'imported', 3 => "A\u{2028}"] as $id => $tag) {
        DB::table('tags')->insert(['id' => $id, 'user_id' => 1, 'user_group_id' => 1, 'tag' => $tag]);
        DB::table('tag_transaction_journal')->insert(['transaction_journal_id' => 1701, 'tag_id' => $id]);
    }
}

function fingerprint(): string
{
    $journal = TransactionJournal::findOrFail(1701);
    $legs    = $journal->transactions()->get();
    return PendingInternalCardDelete::fingerprint(
        '701',
        $journal,
        $legs->firstWhere('account_id', 11),
        $legs->firstWhere('account_id', 12),
        $journal->notes()->first()->text,
        DB::table('tags')->pluck('tag')->all(),
        $journal->transactionJournalMeta->pluck('data', 'name')->all()
    );
}

function destroyWith(string $hash, null|TransactionGroup $bound = null): int
{
    global $repository;
    $request = Request::create('/api/v1/transactions/701', 'DELETE', server: ['HTTP_IF_MATCH_INTERNAL_CARD' => $hash]);
    return PendingInternalCardDelete::destroy($request, $bound ?? TransactionGroup::findOrFail(701), $repository)->getStatusCode();
}

function state(): string
{
    $result = [];
    foreach ([
        'transaction_groups',
        'transaction_journals',
        'transactions',
        'notes',
        'tags',
        'category_transaction_journal',
        'budget_transaction_journal',
        'tag_transaction_journal',
        'journal_meta',
        'attachments',
        'locations',
        'journal_links',
        'piggy_bank_events'
    ] as $table) {
        $result[$table] = DB::table($table)->get()->all();
    }
    return json_encode($result, JSON_THROW_ON_ERROR);
}

fixture();
$journal = TransactionJournal::findOrFail(1701);
$legs    = $journal->transactions;
verify(
    PendingInternalCardDelete::fingerprint(
        '701',
        $journal,
        $legs->firstWhere('account_id', 11),
        $legs->firstWhere('account_id', 12),
        '{"proof":"Synthetic / доказ"}',
        ['Банк', 'imported', "A\u{2028}"],
        $journal->transactionJournalMeta->pluck('data', 'name')->all()
    ) === 'b56e14973bd4676077156d7fbd694465737c0cecf1c66bc4ba134580c6bb39a0',
    'Python/PHP fingerprint normalization differs.'
);
$bound = TransactionGroup::findOrFail(701);
$bound->setRelation('transactionJournals', collect());
$grammar->locked = [];
verify(204 === destroyWith(fingerprint(), $bound), 'Unchanged technical staging was not retired.');
verify(
    null !== DB::table('transaction_groups')->where('id', 701)->value('deleted_at')
    && null !== DB::table('transaction_journals')->where('id', 1701)->value('deleted_at')
    && 2 === DB::table('transactions')->whereNotNull('deleted_at')->count(),
    'Native repository dispatch did not soft-retire staging.'
);
foreach ([
    'transaction_groups',
    'transaction_journals',
    'transactions',
    'notes',
    'category_transaction_journal',
    'budget_transaction_journal',
    'tag_transaction_journal',
    'tags',
    'accounts',
    'account_meta',
    'account_types',
    'transaction_types',
    'transaction_currencies',
    'journal_meta',
    'attachments',
    'locations',
    'journal_links',
    'piggy_bank_events'
] as $table) {
    verify(in_array($table, $grammar->locked, true), 'Missing locked snapshot read: ' . $table);
}
$mutations = [
    'amount'             => static fn() => DB::table('transactions')->where('account_id', 12)->update(['amount' => '124.45']),
    'balanced_amount'    => static function (): void {
        DB::table('transactions')->where('account_id', 12)->update(['amount' => '124.45']);
        DB::table('transactions')->where('account_id', 11)->update(['amount' => '-124.45']);
    },
    'group_title'        => static fn() => DB::table('transaction_groups')->update(['title' => 'Owner group title']),
    'note_title'         => static fn() => DB::table('notes')->where('noteable_type', TransactionJournal::class)->update(['title' => 'Owner note title']),
    'attachment'         => static fn() => DB::table('attachments')->insert(['attachable_id' => 1701, 'attachable_type' => TransactionJournal::class]),
    'location'           => static fn() => DB::table('locations')->insert(['locatable_id' => 1701, 'locatable_type' => TransactionJournal::class]),
    'journal_link'       => static fn() => DB::table('journal_links')->insert(['source_id' => 1701, 'destination_id' => 1702]),
    'piggy_event'        => static fn() => DB::table('piggy_bank_events')->insert(['transaction_journal_id' => 1701]),
    'description'        => static fn() => DB::table('transaction_journals')->update(['description' => 'Owner edit']),
    'date'               => static fn() => DB::table('transaction_journals')->update(['date' => '2026-11-09 11:00:00']),
    'notes'              => static fn() => DB::table('notes')->where('noteable_type', TransactionJournal::class)->update(['text' => '{"proof":"Owner note"}']),
    'tag_label'          => static fn() => DB::table('tags')->where('id', 1)->update(['tag' => 'Owner tag']),
    'tag_added'          => static fn() => DB::table('tag_transaction_journal')->insert(['transaction_journal_id' => 1701, 'tag_id' => 99]),
    'category'           => static fn() => DB::table('category_transaction_journal')->insert(['transaction_journal_id' => 1701, 'category_id' => 1]),
    'budget'             => static fn() => DB::table('budget_transaction_journal')->insert(['transaction_journal_id' => 1701, 'budget_id' => 1]),
    'bill'               => static fn() => DB::table('transaction_journals')->update(['bill_id' => 1]),
    'type'               => static fn() => DB::table('transaction_journals')->update(['transaction_type_id' => 2]),
    'foreign_currency'   => static fn() => DB::table('transactions')->update(['foreign_currency_id' => 2]),
    'external_id'        => static fn() => DB::table('journal_meta')->where('name', 'external_id')->update(['data' => '"Owner changed identity"']),
    'internal_reference' => static fn() => DB::table('journal_meta')->where('name', 'internal_reference')->update(['data' => '"Owner edit"']),
    'duplicate_meta'     => static fn() => DB::table('journal_meta')->insert([
        ['transaction_journal_id' => 1701, 'name' => 'external_id', 'data' => '"One"'],
        ['transaction_journal_id' => 1701, 'name' => 'external_id', 'data' => '"Two"']
    ]),
    'typed_meta'         => static fn() => DB::table('journal_meta')->where('name', 'external_id')->update(['data' => '7']),
    'unknown_meta'       => static fn() => DB::table('journal_meta')->insert([
        'transaction_journal_id' => 1701,
        'name'                   => 'external_url',
        'data'                   => '"Owner evidence"'
    ]),
    'reconciled'         => static fn() => DB::table('transactions')->update(['reconciled' => 1]),
    'foreign_amount'     => static fn() => DB::table('transactions')->update(['foreign_amount' => '1']),
    'journal_user'       => static fn() => DB::table('transaction_journals')->update(['user_id' => 2]),
    'journal_group'      => static fn() => DB::table('transaction_journals')->update(['user_group_id' => 2]),
    'account_user'       => static fn() => DB::table('accounts')->where('id', 12)->update(['user_id' => 2]),
    'account_group'      => static fn() => DB::table('accounts')->where('id', 12)->update(['user_group_id' => 2]),
    'account_currency'   => static fn() => DB::table('account_meta')->where('account_id', 12)->update(['data' => '2']),
    'suspense_marker'    => static fn() => DB::table('notes')->where('noteable_type', Account::class)->update(['text' => '{}']),
    'second_journal'     => static fn() => DB::table('transaction_journals')->insert(['id' => 1702, 'transaction_group_id' => 701])
];
foreach ($mutations as $name => $mutate) {
    fixture();
    $hash  = fingerprint();
    $bound = TransactionGroup::with('transactionJournals.transactions')->findOrFail(701);
    $mutate();
    $before = state();
    $calls  = $repository->calls;
    verify(409 === destroyWith($hash, $bound), 'Unsafe/stale state accepted: ' . $name);
    verify($before === state() && $calls === $repository->calls, 'Rejected state changed financial rows: ' . $name);
}
foreach (['user_id', 'user_group_id'] as $owner) {
    fixture();
    $hash = fingerprint();
    DB::table('transaction_groups')->update([$owner => 2]);
    $before = state();
    verify(404 === destroyWith($hash), 'Foreign group accepted: ' . $owner);
    verify($before === state(), 'Foreign group financial state changed.');
}
fixture();
$before = state();
foreach (['bad', str_repeat('A', 64), str_repeat('a', 63), str_repeat('a', 64) . "\n"] as $hash) {
    verify(422 === destroyWith($hash), 'Malformed header accepted.');
    verify($before === state(), 'Malformed header changed financial state.');
}
verify(409 === destroyWith(str_repeat('0', 64)), 'Mismatched hash accepted.');
verify($before === state(), 'Mismatched hash changed financial state.');
$repository->fail = true;
try {
    destroyWith(fingerprint());
    throw new RuntimeException('Repository error was not raised.');
} catch (RuntimeException $exception) {
    verify($exception->getMessage() === 'Synthetic repository failure.', 'Unexpected repository exception.');
}
verify($before === state(), 'Repository failure did not roll back the deletion.');
fixture();
$repository->fail   = false;
$repository->native = true;
DB::table('user_groups')->insert(['id' => 1]);
DB::table('users')->insert(['id' => 1, 'user_group_id' => 1, 'email' => 'synthetic@example.invalid']);
\Illuminate\Support\Facades\Event::forget(\FireflyIII\Events\Model\TransactionGroup\DestroyedSingleTransactionGroup::class);
\Illuminate\Support\Facades\Event::forget(\FireflyIII\Events\Model\Webhook\WebhookMessagesRequestSending::class);
verify(204 === destroyWith(fingerprint()), 'Native repository could not retire the staging group.');
verify(
    1 === TransactionGroup::withTrashed()->whereNotNull('deleted_at')->count()
    && 1 === TransactionJournal::withTrashed()->whereNotNull('deleted_at')->count()
    && 2 === DB::table('transactions')->whereNotNull('deleted_at')->count(),
    'Native soft-delete path did not retire all staging legs.'
);
verify(0 === DB::table('transaction_journals')->where('transaction_type_id', 2)->count(), 'Retirement created income/expense.');
fixture();
$defaultRepository = new class extends TransactionGroupRepository {
    public int $calls = 0;

    public function destroy(TransactionGroup $group): void
    {
        verify(0 === DB::connection()->transactionLevel(), 'Ordinary DELETE unexpectedly entered the conditional path.');
        ++$this->calls;
    }
};
$reflection = new ReflectionClass(\FireflyIII\Api\V1\Controllers\Models\Transaction\DestroyController::class);
$controller = $reflection->newInstanceWithoutConstructor();
$reflection->getProperty('groupRepository')->setValue($controller, $defaultRepository);
$app->instance('request', Request::create('/api/v1/transactions/701', 'DELETE'));
verify(204 === $controller->destroy(TransactionGroup::findOrFail(701))->getStatusCode() && 1 === $defaultRepository->calls, 'Default DELETE behavior changed.');
$app->instance('request', Request::create('/api/v1/transactions/701', 'DELETE', server: ['HTTP_IF_MATCH_INTERNAL_CARD' => 'malformed']));
verify(
    422 === $controller->destroy(TransactionGroup::findOrFail(701))->getStatusCode() && 1 === $defaultRepository->calls,
    'Installed controller guard did not intercept the optional header.'
);
echo 'Passed ' . $checks . " internal card delete checks.\n";
