<?php

declare(strict_types=1);

putenv('APP_KEY=base64:' . base64_encode(hash('sha256', 'isolated-bank-authorization-fixture', true)));
$fixtureAppRoot = getenv('FIREFLY_TEST_APP_ROOT') ?: dirname(__DIR__, 2);
require $fixtureAppRoot . '/vendor/autoload.php';
$app = require $fixtureAppRoot . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
set_exception_handler(static function (\Throwable $exception): void {
    fwrite(STDERR, (string) $exception . "\n");
    exit(1);
});
require getenv('BANK_AUTHORIZATION_SOURCE') ?: dirname(__DIR__, 2) . '/app/Support/Reports/BankAuthorizationMutation.php';
require dirname(__DIR__, 2) . '/app/Api/V1/Controllers/Models/Transaction/UpdateController.php';
require dirname(__DIR__, 2) . '/app/Api/V1/Controllers/Models/Transaction/DestroyController.php';

use FireflyIII\Api\V1\Requests\Models\Transaction\UpdateRequest;
use FireflyIII\Events\Model\TransactionGroup\TransactionGroupRequestsAuditLogEntry;
use FireflyIII\Events\Model\TransactionGroup\UpdatedSingleTransactionGroup;
use FireflyIII\Models\TransactionGroup;
use FireflyIII\Models\TransactionJournal;
use FireflyIII\Repositories\TransactionGroup\TransactionGroupRepository;
use FireflyIII\Support\Facades\AppConfiguration;
use FireflyIII\Support\Facades\Preferences;
use FireflyIII\Support\Reports\BankAuthorizationMutation;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\SQLiteGrammar;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

config([
    'app.timezone'                               => 'Europe/Kyiv',
    'database.default'                           => 'authorization_fixture',
    'database.connections.authorization_fixture' => [
        'driver'                  => 'sqlite',
        'database'                => ':memory:',
        'prefix'                  => '',
        'foreign_key_constraints' => true
    ]
]);
DB::purge('authorization_fixture');
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
    'bills'                  => ['user_id:i', 'user_group_id:i', 'name:s'],
    'attachments'            => ['attachable_id:i', 'attachable_type:s'],
    'locations'              => ['locatable_id:i', 'locatable_type:s'],
    'journal_links'          => ['source_id:i', 'destination_id:i'],
    'piggy_bank_events'      => ['transaction_journal_id:i'],
    'audit_log_entries'      => ['auditable_id:i', 'auditable_type:s', 'message:s'],
    'accounts'               => ['user_id:i', 'user_group_id:i', 'account_type_id:i', 'name:s', 'active:i', 'iban:s'],
    'account_meta'           => ['account_id:i', 'name:s', 'data:s'],
    'transaction_groups'     => ['user_id:i', 'user_group_id:i', 'title:s'],
    'transaction_types'      => ['type:s'],
    'transaction_currencies' => ['code:s', 'symbol:s', 'decimal_places:i', 'enabled:i'],
    'transaction_journals'   => [
        'user_id:i',
        'user_group_id:i',
        'transaction_group_id:i',
        'transaction_type_id:i',
        'transaction_currency_id:i',
        'bill_id:i',
        'description:s',
        'date:s',
        'date_tz:s',
        'order:i'
    ],
    'journal_meta'           => ['transaction_journal_id:i', 'name:s', 'data:s', 'hash:s'],
    'transactions'           => [
        'transaction_journal_id:i',
        'account_id:i',
        'transaction_currency_id:i',
        'amount:s',
        'foreign_currency_id:i',
        'foreign_amount:s',
        'reconciled:i',
        'balance_dirty:i',
        'native_amount:s',
        'native_foreign_amount:s',
        'native_currency_id:i',
        'pc_amount:s',
        'pc_foreign_amount:s',
        'pc_currency_id:i'
    ],
    'notes'                  => ['noteable_id:i', 'noteable_type:s', 'title:s', 'text:s'],
    'tags'                   => ['user_id:i', 'user_group_id:i', 'tag:s', 'description:s', 'date:s', 'latitude:s', 'longitude:s', 'zoom_level:i']
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

    public function getForUser($user, $name, $default = null): object
    {
        return (object) ['data' => $default];
    }

    public function mark(): void
    {
        ++$this->marks;
    }
});
AppConfiguration::swap(new class {
    public function get($name, $default = null): object
    {
        return (object) ['data' => $default];
    }
});
foreach ([
    TransactionGroupRequestsAuditLogEntry::class,
    UpdatedSingleTransactionGroup::class,
    \FireflyIII\Events\Model\TransactionGroup\DestroyedSingleTransactionGroup::class,
    \FireflyIII\Events\Model\Webhook\WebhookMessagesRequestSending::class
] as $event) {
    Event::forget($event);
}
$checks = 0;
function verify(bool $value, string $message): void
{
    global $checks;
    if (!$value) {
        throw new RuntimeException($message);
    }
    ++$checks;
}

function authorizationNotes(string $status = 'pending', string $amount = '123.45', int $time = 1794128400, string $currency = 'UAH'): string
{
    return json_encode([
        'proof'                  => 'Synthetic / доказ',
        'owner'                  => ['memo' => 'Зберегти', 'typed' => 1],
        'hold'                   => 'pending' === $status,
        'monobank_authorization' => [
            'version'    => 1,
            'key'        => 'monobank:synthetic:row',
            'account_id' => '21',
            'currency'   => $currency,
            'amount'     => $amount,
            'status'     => $status,
            'bank_time'  => $time,
            'evidence'   => 'Synthetic bank evidence'
        ]
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function fixture(string $currency = 'UAH'): void
{
    $currencyId = ['UAH' => 1, 'EUR' => 2, 'USD' => 3, 'CHF' => 4][$currency];
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
        'tags',
        'budgets',
        'categories',
        'bills',
        'users',
        'user_groups',
        'audit_log_entries'
    ] as $table) {
        DB::table($table)->delete();
    }
    DB::table('user_groups')->insert(['id' => 1]);
    DB::table('users')->insert(['id' => 1, 'user_group_id' => 1, 'email' => 'synthetic@example.invalid']);
    DB::table('account_types')->insert([['id' => 1, 'type' => 'Asset account'], ['id' => 2, 'type' => 'Expense account']]);
    DB::table('transaction_types')->insert([['id' => 1, 'type' => 'Withdrawal'], ['id' => 2, 'type' => 'Transfer']]);
    DB::table('transaction_currencies')->insert(['id' => $currencyId, 'code' => $currency, 'symbol' => $currency, 'decimal_places' => 2, 'enabled' => 1]);
    foreach ([21 => 1, 22 => 2] as $id => $type) {
        DB::table('accounts')->insert([
            'id'              => $id,
            'user_id'         => 1,
            'user_group_id'   => 1,
            'account_type_id' => $type,
            'name'            => 'Synthetic account ' . $id,
            'active'          => 1
        ]);
    }
    DB::table('account_meta')->insert(['account_id' => 21, 'name' => 'currency_id', 'data' => (string) $currencyId]);
    DB::table('transaction_groups')->insert(['id' => 901, 'user_id' => 1, 'user_group_id' => 1]);
    DB::table('transaction_journals')->insert([
        'id'                      => 1901,
        'user_id'                 => 1,
        'user_group_id'           => 1,
        'transaction_group_id'    => 901,
        'transaction_type_id'     => 1,
        'transaction_currency_id' => $currencyId,
        'description'             => "Synthetic / авторизація\u{2028}",
        'date'                    => '2026-11-08 11:00:00',
        'date_tz'                 => 'Europe/Kyiv',
        'bill_id'                 => 51
    ]);
    foreach ([21 => '-123.45', 22 => '123.45'] as $id => $amount) {
        DB::table('transactions')->insert([
            'transaction_journal_id'  => 1901,
            'account_id'              => $id,
            'transaction_currency_id' => $currencyId,
            'amount'                  => $amount
        ]);
    }
    foreach ([
        'external_id'        => 'monobank:synthetic:row',
        'internal_reference' => 'monobank',
        'original_source'    => 'synthetic-source',
        'import_hash_v2'     => 'synthetic-import-hash'
    ] as $name => $value) {
        DB::table('journal_meta')->insert(['transaction_journal_id' => 1901, 'name' => $name, 'data' => json_encode($value)]);
    }
    DB::table('notes')->insert(['noteable_id' => 1901, 'noteable_type' => TransactionJournal::class, 'text' => authorizationNotes(currency: $currency)]);
    foreach ([1 => 'Банк', 2 => 'imported', 3 => "A\u{2028}", 4 => BankAuthorizationMutation::PENDING_TAG] as $id => $tag) {
        DB::table('tags')->insert(['id' => $id, 'user_id' => 1, 'user_group_id' => 1, 'tag' => $tag]);
        DB::table('tag_transaction_journal')->insert(['transaction_journal_id' => 1901, 'tag_id' => $id]);
    }
    foreach (['categories' => 31, 'budgets' => 41, 'bills' => 51] as $table => $id) {
        DB::table($table)->insert(['id' => $id, 'user_id' => 1, 'user_group_id' => 1, 'name' => 'Owner assignment']);
    }
    DB::table('category_transaction_journal')->insert(['transaction_journal_id' => 1901, 'category_id' => 31]);
    DB::table('budget_transaction_journal')->insert(['transaction_journal_id' => 1901, 'budget_id' => 41]);
    DB::table('audit_log_entries')->insert([
        'id'             => 61,
        'auditable_id'   => 1901,
        'auditable_type' => TransactionJournal::class,
        'message'        => 'Synthetic owner audit'
    ]);
}

function fingerprint(): string
{
    $journal = TransactionJournal::findOrFail(1901);
    $legs    = $journal->transactions;

    return BankAuthorizationMutation::fingerprint(
        '901',
        $journal,
        $legs->firstWhere('account_id', 21),
        $legs->firstWhere('account_id', 22),
        $journal->notes()->first()->text,
        $journal->tags()->pluck('tag')->all(),
        $journal->transactionJournalMeta->pluck('data', 'name')->all(),
        $journal->categories()->value('categories.id'),
        $journal->budgets()->value('budgets.id')
    );
}

function updateRequest(string $hash, string $status = 'settled', string $amount = '120.00', int $time = 1794132000): UpdateRequest
{
    $currency = DB::table('transaction_currencies')->first();
    return UpdateRequest::create(
        '/api/v1/transactions/901',
        'PUT',
        [
            'apply_rules'   => false,
            'fire_webhooks' => false,
            'transactions'  => [[
                'transaction_journal_id' => '1901',
                'type'                   => 'withdrawal',
                'currency_id'            => (string) $currency->id,
                'source_id'              => '21',
                'destination_id'         => '22',
                'date'                   => gmdate('Y-m-d\TH:i:s+00:00', $time),
                'amount'                 => $amount,
                'notes'                  => authorizationNotes($status, $amount, $time, $currency->code),
                'tags'                   => array_merge(
                    ['Банк', 'imported', "A\u{2028}"],
                    'pending' === $status ? [BankAuthorizationMutation::PENDING_TAG] : []
                )
            ]]
        ],
        server: ['HTTP_IF_MATCH_BANK_AUTHORIZATION' => $hash]
    );
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
        'piggy_bank_events',
        'audit_log_entries'
    ] as $table) {
        $result[$table] = DB::table($table)->get()->all();
    }

    return json_encode($result, JSON_THROW_ON_ERROR);
}

$repository = new class extends TransactionGroupRepository {
    public int  $calls = 0;
    public bool $fail  = false;

    public function destroy(TransactionGroup $group): void
    {
        verify(1 === DB::connection()->transactionLevel(), 'Native destroy escaped atomic transaction.');
        verify(1 === $group->transactionJournals->count(), 'Route-bound stale journals reached native destroy.');
        ++$this->calls;
        parent::destroy($group);
        if ($this->fail) {
            throw new RuntimeException('Synthetic native destroy failure.');
        }
    }

    public function update(TransactionGroup $group, array $data): TransactionGroup
    {
        verify(1 === DB::connection()->transactionLevel(), 'Native update escaped atomic transaction.');
        verify(1 === $group->transactionJournals->count(), 'Route-bound stale journals reached native update.');
        ++$this->calls;
        $result = parent::update($group, $data);
        if ($this->fail) {
            throw new RuntimeException('Synthetic native update failure.');
        }

        return $result;
    }
};
function updateWith(UpdateRequest $request, null|TransactionGroup $bound = null): int
{
    global $repository;
    return BankAuthorizationMutation::update($request, $bound ?? TransactionGroup::findOrFail(901), static function ($locked) use ($request, $repository) {
        $repository->update($locked, $request->getAll());
        return response()->json(['data' => ['id' => (string) $locked->id]]);
    })->getStatusCode();
}

function destroyWith(string $hash, null|TransactionGroup $bound = null): int
{
    global $repository;
    return BankAuthorizationMutation::destroy(Request::create('/api/v1/transactions/901', 'DELETE', server: [
        'HTTP_IF_MATCH_BANK_AUTHORIZATION' => $hash
    ]), $bound ?? TransactionGroup::findOrFail(901), $repository)->getStatusCode();
}

fixture();
$journal = TransactionJournal::findOrFail(1901);
$legs    = $journal->transactions;
verify(
    '60fcad7219927e93b8900e5c4417f2fc6b203eaa4f4ea1354fec4eccf5b472ae' === BankAuthorizationMutation::fingerprint(
        '901',
        $journal,
        $legs->firstWhere('account_id', 21),
        $legs->firstWhere('account_id', 22),
        '{"proof":"Synthetic / доказ"}',
        ['Банк', 'imported', "A\u{2028}"],
        $journal->transactionJournalMeta->pluck('data', 'name')->all(),
        31,
        41
    ),
    'Python/PHP fingerprint normalization differs.'
);
$bound = TransactionGroup::findOrFail(901);
$bound->setRelation('transactionJournals', collect());
$grammar->locked = [];
$oldHash         = fingerprint();
verify(200 === updateWith(updateRequest($oldHash), $bound), 'Native update failed to settle the same journal.');
verify(1 === TransactionGroup::count() && 1 === TransactionJournal::count() && 2 === DB::table('transactions')->count(), 'Settlement duplicated ledger rows.');
verify('120' === bcadd((string) DB::table('transactions')->where('account_id', 22)->value('amount'), '0', 0), 'Native update did not change amount.');
verify(
    31 === DB::table('category_transaction_journal')->value('category_id')
    && 41 === DB::table('budget_transaction_journal')->value('budget_id')
    && 51 === DB::table('transaction_journals')->value('bill_id'),
    'Native partial update lost owner category, budget or subscription.'
);
$stored = json_decode(DB::table('notes')->value('text'));
verify(
    false === $stored->hold
    && 'settled' === $stored->monobank_authorization->status
    && 1794132000 === $stored->monobank_authorization->bank_time
    && 'Зберегти' === $stored->owner->memo,
    'Native update lost status, bank time or unrelated owner notes.'
);
verify(1794132000 === TransactionJournal::findOrFail(1901)->date->getTimestamp(), 'Native journal date diverged from bank time.');
verify(
    ['A' . "\u{2028}", 'imported', 'Банк'] === $journal->tags()->orderBy('tag')->pluck('tag')->all(),
    'Native update lost manual tags or retained pending tag.'
);
verify(409 === destroyWith(fingerprint()), 'Settled authorization was deleted.');
verify(409 === updateWith(updateRequest($oldHash)), 'Stale pending fingerprint updated settled state.');
verify(422 === updateWith(updateRequest(fingerprint(), 'pending')), 'Settled authorization was demoted to pending.');
foreach ([
    'transaction_groups',
    'transaction_journals',
    'transactions',
    'notes',
    'journal_meta',
    'category_transaction_journal',
    'budget_transaction_journal',
    'tag_transaction_journal',
    'tags',
    'categories',
    'budgets',
    'bills',
    'accounts',
    'account_meta',
    'account_types',
    'transaction_types',
    'transaction_currencies'
] as $table) {
    verify(in_array($table, $grammar->locked, true), 'Missing locked snapshot read: ' . $table);
}
fixture();
verify(200 === updateWith(updateRequest(fingerprint(), 'pending')), 'Pending bank revision failed.');
verify(204 === destroyWith(fingerprint()), 'Revised pending authorization could not be cancelled.');
verify(
    1 === TransactionGroup::withTrashed()->whereNotNull('deleted_at')->count()
    && 1 === TransactionJournal::withTrashed()->whereNotNull('deleted_at')->count()
    && 2 === DB::table('transactions')->whereNotNull('deleted_at')->count(),
    'Native cancellation did not soft-delete all legs.'
);
verify(2 === DB::table('notes')->count() && 2 === DB::table('notes')->whereNotNull('deleted_at')->count(), 'Soft cancellation destroyed bank evidence.');
$archive = json_decode(DB::table('notes')->where('title', 'monobank authorization release')->value('text'), true);
verify(
    'pending' === $archive['monobank_authorization']['status']
    && 'monobank:synthetic:row' === $archive['bank_identity']['external_id']
    && 31 === $archive['category_transaction_journal'][0]['category_id']
    && 41 === $archive['budget_transaction_journal'][0]['budget_id']
    && 4 === count($archive['tag_transaction_journal'])
    && 'Synthetic owner audit' === $archive['audit_log_entries'][0]['message'],
    'Release archive lost classification, tags or owner audit.'
);
verify(
    0 === DB::table('category_transaction_journal')->count()
    && 0 === DB::table('budget_transaction_journal')->count()
    && 0 === DB::table('tag_transaction_journal')->count(),
    'Release changed native pivot deletion semantics.'
);
foreach (['EUR', 'USD', 'CHF'] as $currency) {
    fixture($currency);
    $request                          = updateRequest(fingerprint());
    $data                             = $request->all();
    $data['transactions'][0]['notes'] = authorizationNotes('settled', '120.00', 1794132000, 'UAH');
    $request->replace($data);
    verify(422 === updateWith($request), $currency . ' authorization accepted a marker currency change.');
    verify(200 === updateWith(updateRequest(fingerprint(), 'pending')), $currency . ' pending revision failed.');
    $journal = TransactionJournal::findOrFail(1901);
    $stored  = json_decode($journal->notes()->first()->text);
    verify(
        $currency === $stored->monobank_authorization->currency
        && true === $stored->hold
        && null === DB::table('transactions')->value('foreign_currency_id')
        && null === DB::table('transactions')->value('foreign_amount'),
        $currency . ' pending update changed currency or introduced foreign legs.'
    );
    verify(204 === destroyWith(fingerprint()), $currency . ' pending release failed.');
    fixture($currency);
    verify(200 === updateWith(updateRequest(fingerprint())), $currency . ' settlement failed.');
    verify(
        $currency === json_decode(DB::table('notes')->value('text'))->monobank_authorization->currency && 409 === destroyWith(fingerprint()),
        $currency . ' settlement changed currency or permitted deletion.'
    );
}
fixture('EUR');
DB::table('notes')->update(['text' => authorizationNotes(currency: 'UAH')]);
verify(409 === updateWith(updateRequest(fingerprint())) && 409 === destroyWith(fingerprint()), 'Existing marker currency mismatch was accepted.');
fixture('EUR');
DB::table('transaction_currencies')->update(['decimal_places' => 3]);
verify(409 === updateWith(updateRequest(fingerprint())) && 409 === destroyWith(fingerprint()), 'Unsupported currency precision was accepted.');

$mutations = [
    'amount'             => static fn() => DB::table('transactions')->where('account_id', 22)->update(['amount' => '124.45']),
    'balanced_amount'    => static function (): void {
        DB::table('transactions')->where('account_id', 22)->update(['amount' => '124.45']);
        DB::table('transactions')->where('account_id', 21)->update(['amount' => '-124.45']);
    },
    'description'        => static fn() => DB::table('transaction_journals')->update(['description' => 'Owner description']),
    'date'               => static fn() => DB::table('transaction_journals')->update(['date' => '2026-11-09 11:00:00']),
    'group_title'        => static fn() => DB::table('transaction_groups')->update(['title' => 'Owner group title']),
    'note_title'         => static fn() => DB::table('notes')->update(['title' => 'Owner note title']),
    'notes'              => static fn() => DB::table('notes')->update(['text' => '{"proof":"Owner note"}']),
    'tags'               => static fn() => DB::table('tags')->where('id', 1)->update(['tag' => 'Owner tag']),
    'missing_tag'        => static fn() => DB::table('tags')->where('id', 1)->delete(),
    'category'           => static fn() => DB::table('category_transaction_journal')->update(['category_id' => 99]),
    'budget'             => static fn() => DB::table('budget_transaction_journal')->update(['budget_id' => 99]),
    'bill'               => static fn() => DB::table('transaction_journals')->update(['bill_id' => null]),
    'type'               => static fn() => DB::table('transaction_journals')->update(['transaction_type_id' => 2]),
    'foreign_currency'   => static fn() => DB::table('transactions')->update(['foreign_currency_id' => 2]),
    'foreign_amount'     => static fn() => DB::table('transactions')->update(['foreign_amount' => '1']),
    'reconciled'         => static fn() => DB::table('transactions')->update(['reconciled' => 1]),
    'bank_identity'      => static fn() => DB::table('journal_meta')->where('name', 'external_id')->update(['data' => '"monobank:changed"']),
    'internal_reference' => static fn() => DB::table('journal_meta')->where('name', 'internal_reference')->update(['data' => '"changed-import"']),
    'original_source'    => static fn() => DB::table('journal_meta')->where('name', 'original_source')->update(['data' => '"changed-source"']),
    'import_hash_v2'     => static fn() => DB::table('journal_meta')->where('name', 'import_hash_v2')->update(['data' => '"changed-hash"']),
    'typed_meta'         => static fn() => DB::table('journal_meta')->where('name', 'external_id')->update(['data' => '7']),
    'duplicate_meta'     => static fn() => DB::table('journal_meta')->insert([
        'transaction_journal_id' => 1901,
        'name'                   => 'external_id',
        'data'                   => '"monobank:duplicate"'
    ]),
    'unknown_meta'       => static fn() => DB::table('journal_meta')->insert([
        'transaction_journal_id' => 1901,
        'name'                   => 'external_url',
        'data'                   => '"Owner evidence"'
    ]),
    'journal_owner'      => static fn() => DB::table('transaction_journals')->update(['user_id' => 2]),
    'journal_group'      => static fn() => DB::table('transaction_journals')->update(['user_group_id' => 2]),
    'account_owner'      => static fn() => DB::table('accounts')->where('id', 21)->update(['user_id' => 2]),
    'account_group'      => static fn() => DB::table('accounts')->where('id', 22)->update(['user_group_id' => 2]),
    'account_currency'   => static fn() => DB::table('account_meta')->update(['data' => '2']),
    'own_destination'    => static fn() => DB::table('accounts')->where('id', 22)->update(['account_type_id' => 1]),
    'second_journal'     => static fn() => DB::table('transaction_journals')->insert(['id' => 1902, 'transaction_group_id' => 901])
];
foreach ($mutations as $name => $mutate) {
    fixture();
    $hash  = fingerprint();
    $bound = TransactionGroup::with('transactionJournals.transactions')->findOrFail(901);
    $mutate();
    $before = state();
    $calls  = $repository->calls;
    verify(409 === updateWith(updateRequest($hash), $bound), 'Stale/unsafe update accepted: ' . $name);
    verify(409 === destroyWith($hash, $bound), 'Stale/unsafe deletion accepted: ' . $name);
    verify($before === state() && $calls === $repository->calls, 'Rejected mutation changed rows: ' . $name);
}
foreach ([
    'attachments'       => ['attachable_id' => 1901, 'attachable_type' => TransactionJournal::class],
    'locations'         => ['locatable_id' => 1901, 'locatable_type' => TransactionJournal::class],
    'journal_links'     => ['source_id' => 1901, 'destination_id' => 1902],
    'piggy_bank_events' => ['transaction_journal_id' => 1901]
] as $table => $values) {
    fixture();
    $hash = fingerprint();
    DB::table($table)->insert($values);
    $before = state();
    verify(409 === destroyWith($hash), 'Deletion discarded related evidence: ' . $table);
    verify($before === state(), 'Rejected evidence deletion changed rows.');
}
foreach (['user_id', 'user_group_id'] as $owner) {
    fixture();
    $hash = fingerprint();
    DB::table('transaction_groups')->update([$owner => 2]);
    $before = state();
    verify(404 === updateWith(updateRequest($hash)) && 404 === destroyWith($hash), 'Foreign group was accessible.');
    verify($before === state(), 'Foreign group changed.');
}
fixture();
$before = state();
foreach (['bad', str_repeat('A', 64), str_repeat('a', 63), str_repeat('a', 64) . "\n"] as $hash) {
    verify(422 === updateWith(updateRequest($hash)) && 422 === destroyWith($hash), 'Malformed header accepted.');
}
foreach (['If-Match-Tags', 'If-Match-Category', 'If-Match-Notes', 'If-Match-Internal-Card'] as $header) {
    $request = updateRequest(fingerprint());
    $request->headers->set($header, 'none');
    verify(422 === updateWith($request), 'Mixed conditional headers accepted.');
}
$request = updateRequest(fingerprint());
$request->headers->set('If-Match-Bank-Authorization', [fingerprint(), fingerprint()]);
verify(422 === updateWith($request), 'Duplicate conditional header accepted.');
foreach ([
    'category_id'    => '32',
    'budget_id'      => '42',
    'bill_id'        => '52',
    'description'    => 'Owner override',
    'reconciled'     => false,
    'external_id'    => 'monobank:other',
    'source_id'      => '23',
    'destination_id' => '23',
    'currency_id'    => '2',
    'type'           => 'transfer'
] as $field => $value) {
    $request                         = updateRequest(fingerprint());
    $data                            = $request->all();
    $data['transactions'][0][$field] = $value;
    $request->replace($data);
    verify(422 === updateWith($request), 'Unrelated field accepted: ' . $field);
}
foreach ([
    'version'    => 2,
    'key'        => 'monobank:other',
    'account_id' => '22',
    'currency'   => 'EUR',
    'amount'     => '121.00',
    'bank_time'  => 1794132001,
    'status'     => 'cancelled'
] as $field => $value) {
    $request                                 = updateRequest(fingerprint());
    $data                                    = $request->all();
    $notes                                   = json_decode($data['transactions'][0]['notes']);
    $notes->monobank_authorization->{$field} = $value;
    $data['transactions'][0]['notes']        = json_encode($notes);
    $request->replace($data);
    verify(422 === updateWith($request), 'Invalid authorization protocol accepted: ' . $field);
}
foreach (['owner_notes', 'typed_owner_notes', 'hold', 'pending_tag', 'manual_tag_removed', 'unrelated_tag_added'] as $mutation) {
    $request = updateRequest(fingerprint());
    $data    = $request->all();
    $row     = $data['transactions'][0];
    $notes   = json_decode($row['notes']);
    if ('owner_notes' === $mutation) {
        $notes->owner->memo = 'Changed';
    }
    if ('typed_owner_notes' === $mutation) {
        $notes->owner->typed = '1';
    }
    if ('hold' === $mutation) {
        $notes->hold = true;
    }
    if ('pending_tag' === $mutation) {
        $row['tags'][] = BankAuthorizationMutation::PENDING_TAG;
    }
    if ('manual_tag_removed' === $mutation) {
        array_pop($row['tags']);
    }
    if ('unrelated_tag_added' === $mutation) {
        $row['tags'][] = 'Unrelated';
    }
    $row['notes']            = json_encode($notes);
    $data['transactions'][0] = $row;
    $request->replace($data);
    verify(422 === updateWith($request), 'Unrelated notes/tag mutation accepted: ' . $mutation);
}
verify($before === state(), 'Invalid body changed financial state.');
$repository->fail = true;
foreach (['update', 'destroy'] as $action) {
    try {
        'update' === $action ? updateWith(updateRequest(fingerprint())) : destroyWith(fingerprint());
        throw new RuntimeException('Native failure was not propagated.');
    } catch (RuntimeException $exception) {
        verify('Synthetic native ' . $action . ' failure.' === $exception->getMessage(), 'Unexpected native failure: ' . $exception->getMessage());
    }
    verify($before === state(), 'Native failure did not roll back ' . $action . '.');
}
$repository->fail = false;
$request          = updateRequest(fingerprint());
try {
    BankAuthorizationMutation::update($request, TransactionGroup::findOrFail(901), static function ($locked) use ($request, $repository) {
        $repository->update($locked, $request->getAll());
        DB::table('category_transaction_journal')->delete();
        return response()->json([]);
    });
    throw new RuntimeException('Native readback mismatch was accepted.');
} catch (RuntimeException $exception) {
    verify('Native update did not preserve the guarded bank authorization.' === $exception->getMessage(), 'Unexpected readback exception.');
}
verify($before === state(), 'Native readback failure did not roll back all ledger changes.');

// Exercise the installed wrapper through the real controller and repository up to its native event.
$reflection = new ReflectionClass(\FireflyIII\Api\V1\Controllers\Models\Transaction\UpdateController::class);
$controller = $reflection->newInstanceWithoutConstructor();
$reflection->getProperty('groupRepository')->setValue($controller, $repository);
Event::listen(UpdatedSingleTransactionGroup::class, static function ($event): void {
    verify(1 === DB::connection()->transactionLevel(), 'Native update event escaped CAS transaction.');
    verify(false === $event->flags->applyRules && false === $event->flags->fireWebhooks, 'Native event enabled rules or webhooks.');
    verify('120' === bcadd((string) DB::table('transactions')->where('account_id', 22)->value('amount'), '0', 0), 'Native event ran before the ledger update.');
    throw new RuntimeException('Synthetic native controller event failure.');
});
$request = updateRequest(fingerprint());
$app->instance('request', $request);
try {
    $controller->update($request, TransactionGroup::findOrFail(901));
    throw new RuntimeException('Installed update wrapper was not reached.');
} catch (RuntimeException $exception) {
    verify('Synthetic native controller event failure.' === $exception->getMessage(), 'Unexpected controller error: ' . $exception->getMessage());
}
verify($before === state(), 'Native controller event failure did not roll back the ledger.');
$source = file_get_contents(dirname(__DIR__, 2) . '/app/Api/V1/Controllers/Models/Transaction/UpdateController.php');
verify(
    strpos($source, "has('If-Match-Bank-Authorization')") < strpos($source, "has('If-Match-Tags')")
    && strpos($source, "has('If-Match-Tags')") < strpos($source, "has('If-Match-Category')"),
    'Existing conditional update guard order changed.'
);
$destroyReflection = new ReflectionClass(\FireflyIII\Api\V1\Controllers\Models\Transaction\DestroyController::class);
$destroyController = $destroyReflection->newInstanceWithoutConstructor();
$destroyReflection->getProperty('groupRepository')->setValue($destroyController, $repository);
$app->instance('request', Request::create('/api/v1/transactions/901', 'DELETE', server: ['HTTP_IF_MATCH_BANK_AUTHORIZATION' => 'malformed']));
verify(
    422 === $destroyController->destroy(TransactionGroup::findOrFail(901))->getStatusCode(),
    'Installed delete guard did not intercept the optional header.'
);
echo 'Passed ' . $checks . " bank authorization checks.\n";
