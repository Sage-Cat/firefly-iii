<?php

declare(strict_types=1);

require __DIR__ . '/payment_review_storage.php';

use FireflyIII\Models\TransactionJournal;
use FireflyIII\Support\Reports\PaymentReview;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

function badgeRequest(): void
{
    $GLOBALS['app']->instance('request', Request::create('https://example.test/transactions/review'));
}

function clearBadgeJournals(): void
{
    foreach ([
        'notes',
        'category_transaction_journal',
        'tag_transaction_journal',
        'budget_transaction_journal',
        'journal_meta',
        'transactions',
        'transaction_journals',
        'transaction_groups'
    ] as $table) {
        DB::table($table)->delete();
    }
}

function badgeHtml(bool $anonymous = false, bool $active = false): string
{
    $route = new \Illuminate\Routing\Route('GET', 'fixture', static fn() => null);
    $route->name($active ? 'transactions.review' : 'index');
    $property = new ReflectionProperty($GLOBALS['app']['router'], 'current');
    $property->setValue($GLOBALS['app']['router'], $route);
    $source   = file_get_contents(getenv('REVIEW_BADGE_VIEW') ?: dirname(__DIR__, 2) . '/resources/views/reports/payment-review-sidebar.blade.php');
    $compiled = $GLOBALS['app']['blade.compiler']->compileString($source);
    $JS_NONCE = 'review-badge-fixture';
    ob_start();
    eval('?>' . $compiled);

    return ob_get_clean();
}

clearBadgeJournals();
badgeRequest();
verify(PaymentReview::counts($user) === ['all' => 0, 'category' => 0, 'transfer' => 0], 'An empty queue has exact zero counts.');
$zero = badgeHtml();
fixtureJournal(101);
badgeRequest();
DB::enableQueryLog();
DB::flushQueryLog();
verify(PaymentReview::counts($user) === ['all' => 1, 'category' => 1, 'transfer' => 0], 'An unclassified owned payment has one category task.');
$queries = DB::getQueryLog();
verify(
    !array_filter($queries, static fn($query) => str_contains($query['query'], 'journal_meta') || str_contains($query['query'], 'limit 2000')),
    'Sidebar counting must not load full fingerprints or recommendation history.'
);
PaymentReview::counts($user);
verify(count(DB::getQueryLog()) === count($queries), 'Repeated counting reuses the request snapshot without another query.');
$one = badgeHtml();
DB::disableQueryLog();
foreach ([102, 103, 104, 105, 106, 107, 108, 109, 110, 111, 112, 113] as $id) {
    fixtureJournal($id, in_array($id, [102, 103, 104, 113], true), 108 === $id ? 2 : 1);
}
foreach ([103 => 'decided', 104 => 'resolved'] as $id => $status) {
    $data                          = json_decode(DB::table('notes')->where('noteable_id', $id)->value('text'), true);
    $data['bank_review']['status'] = $status;
    DB::table('notes')
        ->where('noteable_id', $id)
        ->update(['text' => json_encode($data)]);
}
DB::table('notes')->where('noteable_id', 105)->update(['text' => '{"bank_review":{"version":99}}']);
DB::table('transactions')->where('transaction_journal_id', 106)->where('account_id', 1)->update(['account_id' => 4]);
DB::table('transactions')->where('transaction_journal_id', 108)->where('account_id', 1)->update(['account_id' => 4]);
DB::table('category_transaction_journal')->insert(['transaction_journal_id' => 107, 'category_id' => 1]);
DB::table('transaction_groups')->where('id', 109)->update(['user_group_id' => 2]);
DB::table('categories')->insert(['id' => 4, 'user_group_id' => 1, 'name' => PaymentReview::DEFAULT_CATEGORY]);
DB::table('tags')->insert([
    ['id' => 1, 'user_group_id' => 1, 'tag' => PaymentReview::DEFAULT_MARKER],
    ['id' => 2, 'user_group_id' => 2, 'tag' => PaymentReview::DEFAULT_MARKER]
]);
foreach ([110 => 1, 111 => 2] as $id => $tag) {
    DB::table('category_transaction_journal')->insert(['transaction_journal_id' => $id, 'category_id' => 4]);
    DB::table('tag_transaction_journal')->insert(['transaction_journal_id' => $id, 'tag_id' => $tag]);
}
DB::table('category_transaction_journal')->insert(['transaction_journal_id' => 112, 'category_id' => 3]);
DB::table('notes')->insert([
    'noteable_id'   => 113,
    'noteable_type' => TransactionJournal::class,
    'text'          => DB::table('notes')->where('noteable_id', 113)->value('text')
]);
badgeRequest();
$counts = PaymentReview::counts($user);
verify(
    $counts === ['all' => 7, 'category' => 4, 'transfer' => 3],
    'Blocked, decided, default-marker and legacy cases share the same exact eligibility; resolved/classified/foreign cases are excluded.'
);
foreach (['all' => 7, 'category' => 4, 'transfer' => 3] as $filter => $total) {
    $page = PaymentReview::page($user, 1, $filter);
    verify($page['counts'] === $counts && $page['payments']->total() === $total, 'Page and menu counts agree for every queue filter.');
}
$foreign = (object) ['id' => 2, 'user_group_id' => 2];
verify(PaymentReview::counts($foreign) === ['all' => 1, 'category' => 1, 'transfer' => 0], 'Another owner sees only its own valid account movement.');
verify(PaymentReview::counts($user) === $counts, 'Another owner cannot replace a cached owner snapshot.');
fixtureJournal(114);
verify(PaymentReview::counts($user) === $counts, 'Within one request the shared queue remains a consistent snapshot.');
badgeRequest();
verify(PaymentReview::counts($user)['all'] === 8, 'A new request refreshes counts after a saved/imported change.');
clearBadgeJournals();
DB::transaction(static function (): void {
    for ($id = 1000; $id < 2000; ++$id) {
        fixtureJournal($id);
    }
});
badgeRequest();
verify(PaymentReview::counts($user)['all'] === 1000, 'The counter is exact beyond the first page and the usual badge cap.');
$many   = badgeHtml();
$active = badgeHtml(false, true);
badgeRequest();
DB::enableQueryLog();
DB::flushQueryLog();
$anonymous = badgeHtml(true);
verify(count(DB::getQueryLog()) === 0, 'Anonymous rendering does not fetch a queue count.');
DB::disableQueryLog();
foreach ([$zero, $anonymous] as $html) {
    verify(!preg_match('/<span class="badge rounded-pill payment-review-count"/', $html), 'Zero and anonymous views omit the count badge.');
    verify(!preg_match('/class="nav-link[^\"]*payment-review-attention/', $html), 'Zero and anonymous views omit the attention highlight.');
}
verify(str_contains($one, 'у черзі 1') && str_contains($many, 'у черзі 1000'), 'The link accessible name includes the exact count.');
verify(str_contains($active, 'payment-review-nav-link active'), 'Native active menu state remains present.');
if (in_array('--json', $argv, true)) {
    $manifest = json_decode(file_get_contents('/var/www/html/public/build/manifest.json'), true);
    $css      = '';
    $seen     = [];
    foreach ($manifest as $entry) {
        foreach (array_merge($entry['css'] ?? [], str_ends_with($entry['file'] ?? '', '.css') ? [$entry['file']] : []) as $file) {
            if (isset($seen[$file]))
                continue;
            $seen[$file] = true;
            $path        = '/var/www/html/public/build/' . $file;
            $body        = preg_replace_callback(
                '~url\\(([^)]*\\.woff2[^)]*)\\)~',
                static function ($m) use ($path) {
                    $target = dirname($path) . '/' . trim($m[1], '"\'');
                    return is_file($target) ? 'url(data:font/woff2;base64,' . base64_encode(file_get_contents($target)) . ')' : $m[0];
                },
                file_get_contents($path)
            );
            $css         .= $body;
        }
    }
    echo 'REVIEW_BADGE_FIXTURE=' . json_encode(compact('zero', 'one', 'many', 'active', 'anonymous', 'css'), JSON_THROW_ON_ERROR) . "\n";
}
echo 'Passed ' . $checks . " isolated review count/badge checks.\n";
