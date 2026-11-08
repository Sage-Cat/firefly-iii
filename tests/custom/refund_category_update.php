<?php

declare(strict_types=1);

require __DIR__ . '/payment_review_storage.php';
require dirname(__DIR__, 2) . '/app/Support/Reports/PaymentCategoryUpdate.php';

use FireflyIII\Models\TransactionGroup;
use FireflyIII\Support\Reports\PaymentCategoryUpdate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

fixtureJournal(9, false);
fixtureJournal(10, false);
DB::table('categories')->insert(['id' => 4, 'name' => 'Повернення коштів', 'user_group_id' => 1]);
$financialBefore = financialState();
function categoryRequest(int $journal, string $expected, int $category = 4): Request
{
    $request = Request::create('/api/v1/transactions/' . $journal, 'PUT', [
        'apply_rules'   => false,
        'fire_webhooks' => false,
        'transactions'  => [['transaction_journal_id' => (string) $journal, 'category_id' => (string) $category]]
    ]);
    $request->headers->set('If-Match-Category', $expected);
    return $request;
}

$group = TransactionGroup::query()->findOrFail(9);
$reply = PaymentCategoryUpdate::update(categoryRequest(9, 'none'), $group);
verify($reply->getStatusCode() === 200, 'Conditional refund category write failed.');
verify(DB::table('category_transaction_journal')->where('transaction_journal_id', 9)->value('category_id') === 4, 'Refund category missing.');
$reply = PaymentCategoryUpdate::update(categoryRequest(9, 'none', 1), $group);
verify($reply->getStatusCode() === 409, 'Stale classifier overwrote a newer owner choice.');
verify(DB::table('category_transaction_journal')->where('transaction_journal_id', 9)->value('category_id') === 4, 'Manual category was overwritten.');
$reply = PaymentCategoryUpdate::update(categoryRequest(2, 'none'), TransactionGroup::query()->findOrFail(2));
verify($reply->getStatusCode() === 404, 'Foreign transaction group was accepted.');
$reply = PaymentCategoryUpdate::update(categoryRequest(10, 'none', 2), TransactionGroup::query()->findOrFail(10));
verify($reply->getStatusCode() === 422, 'Foreign category was accepted.');
$request = categoryRequest(9, '4');
$request->merge(['amount' => '999']);
verify(PaymentCategoryUpdate::update($request, $group)->getStatusCode() === 422, 'Financial data entered the category-only path.');
$request           = categoryRequest(9, '4');
$rows              = $request->input('transactions');
$rows[0]['amount'] = '999';
$request->merge(['transactions' => $rows]);
verify(PaymentCategoryUpdate::update($request, $group)->getStatusCode() === 422, 'Financial journal field entered the category-only path.');
$financialAfter = financialState();
verify($financialAfter === $financialBefore, 'Conditional category changed financial rows.');
verify(
    DB::table('transactions')->where('transaction_journal_id', 9)->pluck('amount')->all() === ['-123.45', '123.45'],
    'Conditional category changed bank amounts.'
);
echo "Passed 10 conditional category checks after the storage suite.\n";
