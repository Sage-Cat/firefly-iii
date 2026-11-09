<?php

declare(strict_types=1);

require __DIR__ . '/payment_review_storage.php';
require dirname(__DIR__, 2) . '/app/Support/Reports/PaymentCategoryUpdate.php';
require dirname(__DIR__, 2) . '/app/Support/Reports/PaymentCategoryFinalization.php';

use FireflyIII\Models\TransactionGroup;
use FireflyIII\Models\TransactionJournal;
use FireflyIII\Support\Reports\PaymentCategoryFinalization;
use FireflyIII\Support\Reports\PaymentCategoryUpdate;
use FireflyIII\Support\Reports\PaymentMetadataState;
use FireflyIII\Support\Reports\PaymentReview;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\SQLiteGrammar;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

$fallbackChecks = $checks;
$grammar        = new class(DB::connection()) extends SQLiteGrammar {
    public array $locked = [];

    public function compileSelect(Builder $query)
    {
        if (true === $query->lock) {
            if (DB::connection()->transactionLevel() < 1) {
                throw new RuntimeException('Classification lock read outside a transaction.');
            }
            $this->locked[] = $query->from;
        }
        return parent::compileSelect($query);
    }
};
DB::connection()->setQueryGrammar($grammar);
DB::table('accounts')->where('id', 2)->update(['user_group_id' => 2]);
DB::table('categories')->insert(['id' => 4, 'name' => PaymentReview::DEFAULT_CATEGORY, 'user_group_id' => 1]);
DB::table('tags')->insert([
    ['id' => 1, 'tag' => PaymentReview::DEFAULT_MARKER, 'user_group_id' => 1],
    ['id' => 2, 'tag' => 'Owner tag', 'user_group_id' => 1],
    ['id' => 3, 'tag' => 'Очікує підтвердження банком', 'user_group_id' => 1]
]);
foreach ([20, 21, 22, 23, 24, 25] as $id) {
    fixtureJournal($id, 21 === $id);
    DB::table('tag_transaction_journal')->insert(['transaction_journal_id' => $id, 'tag_id' => 2]);
}
DB::table('transaction_journals')->where('id', 22)->update(['transaction_type_id' => 3]);
DB::table('transactions')->where('transaction_journal_id', 22)->where('account_id', 1)->update(['amount' => '123.45']);
DB::table('transactions')->where('transaction_journal_id', 22)->where('account_id', 2)->update(['amount' => '-123.45']);
DB::table('category_transaction_journal')->insert(['transaction_journal_id' => 23, 'category_id' => 4]);
DB::table('tag_transaction_journal')->insert(['transaction_journal_id' => 24, 'tag_id' => 3]);
DB::table('category_transaction_journal')->insert(['transaction_journal_id' => 25, 'category_id' => 3]);
$financialBefore = financialState();
$notesBefore     = DB::table('notes')->orderBy('id')->get()->all();

function fallbackRequest(int $journal, string $expected = 'none', int $category = 4, bool $default = true): Request
{
    $request = Request::create('/api/v1/transactions/' . $journal, 'PUT', [
        'apply_rules'   => false,
        'fire_webhooks' => false,
        'transactions'  => [['transaction_journal_id' => (string) $journal, 'category_id' => (string) $category]]
    ]);
    $request->headers->set('If-Match-Category', $expected);
    if ($default) {
        $request->headers->set('If-Category-Default', '1');
    }

    return $request;
}

function fallbackTags(int $id): array
{
    return TransactionJournal::query()
        ->findOrFail($id)
        ->tags()
        ->orderBy('tags.id')
        ->pluck('tag')
        ->all();
}

foreach ([20, 22, 24, 25] as $id) {
    $request = fallbackRequest($id, 25 === $id ? '3' : 'none');
    $hash    = DB::transaction(static function () use ($id): string {
        $journal = TransactionJournal::query()->where('id', $id)->lockForUpdate()->firstOrFail();
        PaymentMetadataState::lock($journal);
        return PaymentMetadataState::fingerprint($journal);
    });
    $request->headers->set('If-Match-Payment', $hash);
    $reply = PaymentCategoryUpdate::update($request, TransactionGroup::query()->findOrFail($id));
    verify(200 === $reply->getStatusCode(), 'Ordinary unknown income or spending failed default assignment.');
    verify(DB::table('category_transaction_journal')->where('transaction_journal_id', $id)->value('category_id') === 4, 'Actual fallback category is missing.');
    verify(in_array(PaymentReview::DEFAULT_MARKER, fallbackTags($id), true), 'Review marker is missing.');
    verify(in_array('Owner tag', fallbackTags($id), true), 'Owner tags were overwritten.');
}
verify(in_array('Очікує підтвердження банком', fallbackTags(24), true), 'Authorization status marker was lost.');
$hash = DB::transaction(static function (): string {
    $journal = TransactionJournal::query()->where('id', 25)->lockForUpdate()->firstOrFail();
    PaymentMetadataState::lock($journal);
    return PaymentMetadataState::fingerprint($journal);
});
$request = fallbackRequest(25, '4', 1, false);
$request->headers->set('If-Match-Payment', $hash);
DB::table('transaction_journals')->where('id', 25)->update(['description' => 'Owner changed merchant']);
verify(
    409 === PaymentCategoryUpdate::update($request, TransactionGroup::query()->findOrFail(25))->getStatusCode(),
    'Stale description was classified before the scope check.'
);
verify(in_array(PaymentReview::DEFAULT_MARKER, fallbackTags(25), true), 'Stale classification removed the marker.');
verify(4 === DB::table('category_transaction_journal')->where('transaction_journal_id', 25)->value('category_id'), 'Stale rule changed the category.');
DB::table('transaction_journals')->where('id', 25)->update(['description' => 'Synthetic bank payment 25']);

PaymentCategoryFinalization::journal(TransactionJournal::query()->findOrFail(25), ['amount' => '123.45'], $realUser);
verify(in_array(PaymentReview::DEFAULT_MARKER, fallbackTags(25), true), 'Partial amount update confirmed a pending category.');
PaymentCategoryFinalization::journal(TransactionJournal::query()->findOrFail(25), ['category_name' => PaymentReview::DEFAULT_CATEGORY], $realUser);
verify(fallbackTags(25) === ['Owner tag'], 'Native explicit Other did not finalize only its managed marker.');
$page = PaymentReview::page($realUser, 1, 'all');
$ids  = array_column($page['payments']->items(), 'id');
verify(in_array('20', $ids, true) && in_array('22', $ids, true), 'Provisional fallback disappeared from review.');
verify(!in_array('23', $ids, true), 'Existing manual Other was reopened for review.');
$row = array_values(array_filter($page['payments']->items(), static fn($row): bool => '20' === $row['id']))[0];
verify($row['category_pending'] && null === $row['category_id'], 'Provisional Other was treated as confirmed history.');
$token = tokenFor(20);
PaymentReview::save($realUser, 20, ['fingerprint' => $token, 'action' => 'category', 'category_id' => '4']);
verify(fallbackTags(20) === ['Owner tag'], 'Confirming Other did not clear only the review marker.');
verify(
    DB::table('category_transaction_journal')->where('transaction_journal_id', 20)->value('category_id') === 4,
    'Confirming Other changed its actual category.'
);
verify(!in_array('20', array_column(PaymentReview::page($realUser, 1, 'all')['payments']->items(), 'id'), true), 'Confirmed Other remained in the queue.');
$hash = DB::transaction(static function (): string {
    $journal = TransactionJournal::query()->where('id', 20)->lockForUpdate()->firstOrFail();
    PaymentMetadataState::lock($journal);
    return PaymentMetadataState::fingerprint($journal);
});
verify('a0d1b7d13c0da1f4a56c8028301f1bcfef6a5690c0399279f5753c1fa65149a9' === $hash, 'Cross-language payment fingerprint changed.');
verify(
    409 === PaymentCategoryUpdate::update(fallbackRequest(20, '4', 1, false), TransactionGroup::query()->findOrFail(20))->getStatusCode(),
    'A stale classifier overwrote final Other.'
);
$reply = PaymentCategoryUpdate::update(fallbackRequest(22, '4', 1, false), TransactionGroup::query()->findOrFail(22));
verify(200 === $reply->getStatusCode(), 'A confirmed category could not replace provisional Other.');
verify(fallbackTags(22) === ['Owner tag'], 'Confirmed category failed to remove only the marker.');

verify(
    409 === PaymentCategoryUpdate::update(fallbackRequest(23, '4'), TransactionGroup::query()->findOrFail(23))->getStatusCode(),
    'Defaulting reopened manual Other.'
);
verify(
    409 === PaymentCategoryUpdate::update(fallbackRequest(21), TransactionGroup::query()->findOrFail(21))->getStatusCode(),
    'Unknown own transfer received an ordinary fallback.'
);
verify(
    422 === PaymentCategoryUpdate::update(fallbackRequest(21, 'none', 1), TransactionGroup::query()->findOrFail(21))->getStatusCode(),
    'Default header accepted an unrelated category.'
);
verify(
    409 === PaymentCategoryUpdate::update(fallbackRequest(24), TransactionGroup::query()->findOrFail(24))->getStatusCode(),
    'Repeated default assignment was not guarded.'
);
$stale = tokenFor(24);
DB::table('tag_transaction_journal')->where('transaction_journal_id', 24)->where('tag_id', 1)->delete();
rejected(static fn() => PaymentReview::save($realUser, 24, ['fingerprint' => $stale, 'action' => 'category', 'category_id' => '1']), RuntimeException::class);
verify(
    DB::table('category_transaction_journal')->where('transaction_journal_id', 24)->value('category_id') === 4,
    'Marker removal race changed final category.'
);
verify(financialState() === $financialBefore, 'Default or final category assignment changed financial fields.');
verify(DB::table('notes')->orderBy('id')->get()->all() == $notesBefore, 'Default assignment changed bank evidence or notes.');
foreach ([
    'transaction_groups',
    'transaction_journals',
    'transactions',
    'notes',
    'journal_meta',
    'categories',
    'tags',
    'accounts',
    'category_transaction_journal',
    'budget_transaction_journal',
    'tag_transaction_journal'
] as $table) {
    verify(in_array($table, $grammar->locked, true), 'Owner-state range was not locked: ' . $table);
}
echo 'Passed ' . ($checks - $fallbackChecks) . " actual fallback category checks after the storage suite.\n";
