<?php

declare(strict_types=1);

require __DIR__ . '/payment_review_storage.php';
require dirname(__DIR__, 2) . '/app/Support/Reports/PaymentTagsUpdate.php';

use FireflyIII\Models\TransactionGroup;
use FireflyIII\Support\Reports\PaymentTagsUpdate;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

$tagChecks = $checks;
foreach ([
    1 => ['Owner tag', 1],
    2 => ['Кешбек', 1],
    3 => ['Foreign tag', 2],
    4 => ['Fresh owner tag', 1],
    5 => ['Duplicate', 1],
    6 => ['Duplicate', 1]
] as $id => [$name, $owner]) {
    DB::table('tags')->insert(['id' => $id, 'tag' => $name, 'user_group_id' => $owner]);
}
fixtureJournal(20, false);
fixtureJournal(21, false);
fixtureJournal(22, false);
fixtureJournal(23, false);
$staleGroup = TransactionGroup::query()->findOrFail(22);
DB::table('transaction_groups')->where('id', 22)->update(['user_group_id' => 2]);
DB::table('transaction_journals')->where('id', 23)->update(['user_group_id' => 2]);
DB::table('tag_transaction_journal')->insert(['tag_id' => 1, 'transaction_journal_id' => 20]);
$financialBefore = financialState();
$otherBefore     = json_encode([
    DB::table('notes')->orderBy('id')->get()->all(),
    DB::table('journal_meta')->orderBy('id')->get()->all(),
    DB::table('category_transaction_journal')->orderBy('transaction_journal_id')->get()->all()
], JSON_THROW_ON_ERROR);

function tagRequest(int $journal, array $tags, null|string $expected = null, null|string $category = null): Request
{
    $request = Request::create('/api/v1/transactions/' . $journal, 'PUT', [
        'apply_rules'   => false,
        'fire_webhooks' => false,
        'transactions'  => [['transaction_journal_id' => (string) $journal, 'tags' => $tags]]
    ]);
    $request->headers->set('If-Match-Tags', $expected ?? PaymentTagsUpdate::fingerprint(['Owner tag']));
    if (null !== $category) {
        $request->headers->set('If-Match-Category', $category);
    }

    return $request;
}

function tagNames(int $journal): array
{
    return DB::table('tag_transaction_journal')
        ->join('tags', 'tags.id', '=', 'tag_id')
        ->where('transaction_journal_id', $journal)
        ->orderBy('tags.id')
        ->pluck('tag')
        ->all();
}

$group = TransactionGroup::query()->findOrFail(20);
$reply = PaymentTagsUpdate::update(tagRequest(20, ['Owner tag', 'Кешбек']), $group);
verify($reply->getStatusCode() === 200, 'Conditional additive tag write failed.');
verify(tagNames(20) === ['Owner tag', 'Кешбек'], 'Existing owner tag was lost.');
verify(count(DB::table('tags')->get()) === 6, 'Conditional path created or replaced tag models.');
$before = DB::table('tag_transaction_journal')->orderBy('tag_id')->get()->all();
$reply  = PaymentTagsUpdate::update(tagRequest(20, ['Owner tag', 'Кешбек'], PaymentTagsUpdate::fingerprint(tagNames(20))), $group);
verify($reply->getStatusCode() === 200, 'Idempotent additive update failed.');
verify(DB::table('tag_transaction_journal')->orderBy('tag_id')->get()->all() == $before, 'Repeat duplicated tag pivots.');

// The owner adds a tag after a classifier GET and immediately before its PUT.
$stale = PaymentTagsUpdate::fingerprint(tagNames(20));
DB::table('tag_transaction_journal')->insert(['tag_id' => 4, 'transaction_journal_id' => 20]);
$reply = PaymentTagsUpdate::update(tagRequest(20, ['Owner tag', 'Кешбек'], $stale), $group);
verify($reply->getStatusCode() === 409, 'Stale full-list tag replacement was accepted.');
verify(tagNames(20) === ['Owner tag', 'Кешбек', 'Fresh owner tag'], 'A concurrent owner tag was lost.');

$categoryBefore = DB::table('category_transaction_journal')->where('transaction_journal_id', 20)->get()->all();
DB::table('category_transaction_journal')->insert(['transaction_journal_id' => 20, 'category_id' => 1]);
$reply = PaymentTagsUpdate::update(tagRequest(20, tagNames(20), PaymentTagsUpdate::fingerprint(tagNames(20)), 'none'), $group);
verify($reply->getStatusCode() === 409, 'Owner category changed after GET but the tag write was accepted.');
verify(tagNames(20) === ['Owner tag', 'Кешбек', 'Fresh owner tag'], 'Category conflict changed tags.');
verify(DB::table('category_transaction_journal')->where('transaction_journal_id', 20)->value('category_id') === 1, 'Category guard changed the owner choice.');
verify(
    PaymentTagsUpdate::update(tagRequest(20, tagNames(20), PaymentTagsUpdate::fingerprint(tagNames(20)), '1'), $group)->getStatusCode() === 200,
    'Matching category guard was rejected.'
);
DB::table('category_transaction_journal')->where('transaction_journal_id', 20)->delete();
verify(
    DB::table('category_transaction_journal')->where('transaction_journal_id', 20)->get()->all() === $categoryBefore,
    'Category fixture did not restore its original state.'
);

foreach ([
    [],
    ['Кешбек'],
    ['Owner tag', 'Кешбек', 'Fresh owner tag', 'Foreign tag'],
    ['Owner tag', 'Кешбек', 'Fresh owner tag', 'Missing tag'],
    ['Owner tag', 'Кешбек', 'Fresh owner tag', 'Duplicate'],
    ['Owner tag', 'Кешбек', 'Fresh owner tag', 'КЕШБЕК'],
    ['Owner tag', 'Кешбек', 'Fresh owner tag', 'Кешбек'],
    ['Owner tag', 'Кешбек', 'Fresh owner tag', null]
] as $invalid) {
    $reply = PaymentTagsUpdate::update(tagRequest(20, $invalid, PaymentTagsUpdate::fingerprint(tagNames(20))), $group);
    verify($reply->getStatusCode() === 422, 'Removal, unknown, foreign, duplicate or invalid tag was accepted.');
    verify(tagNames(20) === ['Owner tag', 'Кешбек', 'Fresh owner tag'], 'Invalid request changed existing tags.');
}
$request = tagRequest(20, tagNames(20), PaymentTagsUpdate::fingerprint(tagNames(20)));
$request->merge(['amount' => '999']);
verify(PaymentTagsUpdate::update($request, $group)->getStatusCode() === 422, 'Top-level financial field entered the tags-only path.');
$request                = tagRequest(20, tagNames(20), PaymentTagsUpdate::fingerprint(tagNames(20)));
$rows                   = $request->input('transactions');
$rows[0]['category_id'] = '1';
$request->merge(['transactions' => $rows]);
verify(PaymentTagsUpdate::update($request, $group)->getStatusCode() === 422, 'Unrelated journal metadata entered the tags-only path.');
$request = tagRequest(20, tagNames(20), PaymentTagsUpdate::fingerprint(tagNames(20)));
$request->merge(['apply_rules' => true]);
verify(PaymentTagsUpdate::update($request, $group)->getStatusCode() === 422, 'Rule execution was allowed.');
$request = tagRequest(20, tagNames(20), PaymentTagsUpdate::fingerprint(tagNames(20)));
$request->merge(['fire_webhooks' => true]);
verify(PaymentTagsUpdate::update($request, $group)->getStatusCode() === 422, 'Webhook execution was allowed.');
verify(PaymentTagsUpdate::update(tagRequest(21, ['Кешбек']), $group)->getStatusCode() === 404, 'Journal from another group was accepted.');
verify(PaymentTagsUpdate::update(tagRequest(2, ['Кешбек']), TransactionGroup::query()->findOrFail(2))->getStatusCode() === 404, 'Foreign group was accepted.');
verify(PaymentTagsUpdate::update(tagRequest(22, ['Кешбек']), $staleGroup)->getStatusCode() === 404, 'Group ownership was not reread under the lock.');
verify(
    PaymentTagsUpdate::update(tagRequest(23, ['Кешбек']), TransactionGroup::query()->findOrFail(23))->getStatusCode() === 404,
    'Foreign journal in an owned group was accepted.'
);
verify(PaymentTagsUpdate::update(tagRequest(20, tagNames(20), str_repeat('0', 64)), $group)->getStatusCode() === 409, 'Wrong expected hash was accepted.');
verify(PaymentTagsUpdate::update(tagRequest(20, tagNames(20), 'invalid'), $group)->getStatusCode() === 422, 'Malformed expected hash was accepted.');
verify(
    PaymentTagsUpdate::update(tagRequest(20, tagNames(20), PaymentTagsUpdate::fingerprint(tagNames(20)), 'invalid'), $group)->getStatusCode() === 422,
    'Malformed category guard was accepted.'
);

$empty = TransactionGroup::query()->findOrFail(21);
verify(
    PaymentTagsUpdate::update(tagRequest(21, ['Кешбек'], PaymentTagsUpdate::fingerprint([])), $empty)->getStatusCode() === 200,
    'First tag on a journal could not be added.'
);
verify(tagNames(21) === ['Кешбек'], 'Empty initial tag set was not preserved correctly.');
verify(financialState() === $financialBefore, 'Conditional tags changed financial rows.');
verify(json_encode([
    DB::table('notes')->orderBy('id')->get()->all(),
    DB::table('journal_meta')->orderBy('id')->get()->all(),
    DB::table('category_transaction_journal')->orderBy('transaction_journal_id')->get()->all()
], JSON_THROW_ON_ERROR) === $otherBefore, 'Conditional tags changed notes, categories or journal metadata.');
verify(PaymentTagsUpdate::fingerprint(['Кешбек', 'a/b', "\u{2028}\u{2029}", 'Owner tag'])
=== '9b4d09c9f6fe37bcabf7522a4a66ebc983c55e5183da05360182baadfd0912c7', 'Cross-language tag hash fixture changed.');
$controller    = file_get_contents(dirname(__DIR__, 2) . '/app/Api/V1/Controllers/Models/Transaction/UpdateController.php');
$tagGuard      = strpos($controller, "has('If-Match-Tags')");
$categoryGuard = strpos($controller, "has('If-Match-Category')");
verify(false !== $tagGuard && false !== $categoryGuard && $tagGuard < $categoryGuard, 'Combined tag/category headers entered the category-only path.');
verify($tagGuard < strpos($controller, '$request->getAll()'), 'Tag update entered the ordinary financial controller.');

echo 'Passed ' . ($checks - $tagChecks) . " conditional additive tag checks after the storage suite.\n";
