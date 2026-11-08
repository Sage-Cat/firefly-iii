<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/app/Support/Reports/PaymentReview.php';

use FireflyIII\Support\Reports\PaymentReview;

$checks = 0;
function check(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) {
        throw new RuntimeException($message);
    }
    ++$checks;
}

function rejects(callable $call): void
{
    try {
        $call();
    } catch (InvalidArgumentException|RuntimeException $exception) {
        ++$GLOBALS['checks'];

        return;
    }
    throw new RuntimeException('Expected explicit rejection.');
}

$categories = ['1' => 'Продукти', '2' => 'Покупки', '3' => 'Послуги', '4' => 'Доставка їжі', '5' => 'Потребує уточнення'];
$payment    = ['description' => '  SOME SHOP  ', 'merchant' => 'Shop A', 'type' => 'Withdrawal'];
$history    = [
    ['description' => 'some shop', 'merchant' => 'Shop A', 'type' => 'Withdrawal', 'category_id' => '2'],
    ['description' => 'another shop', 'merchant' => 'Shop A', 'type' => 'Withdrawal', 'category_id' => '3'],
    ['description' => 'some shop', 'merchant' => 'Shop A', 'type' => 'Deposit', 'category_id' => '1'],
    ['description' => 'some shop', 'merchant' => 'Shop A', 'type' => 'Withdrawal', 'category_id' => '5'],
    ['description' => 'some shop', 'merchant' => 'Shop A', 'type' => 'Withdrawal', 'category_id' => 'another-owner']
];
$before          = serialize([$payment, $history, $categories]);
$recommendations = PaymentReview::recommendations($payment, $history, $categories);
check($recommendations[0]['id'] === '2', 'Exact normalized descriptions rank ahead of opposing-merchant matches.');
check($recommendations[1]['id'] === '3', 'A confirmed matching merchant can contribute a separate choice.');
check(count($recommendations) === 3 && count(array_unique(array_column($recommendations, 'id'))) === 3, 'At most three distinct recommendations appear.');
check(!in_array('5', array_column($recommendations, 'id'), true), 'The legacy pending label is not a real category choice.');
check($before === serialize([$payment, $history, $categories]), 'Recommendations do not mutate payment classification or source inputs.');
check(PaymentReview::normalize(' SHOP 42 ') !== PaymentReview::normalize('SHOP 43'), 'Distinct numerical identifiers must not be collapsed into one merchant.');
$only = PaymentReview::recommendations($payment, $history, ['4' => 'Доставка їжі']);
check($only === [], 'Unrelated histories and unavailable category IDs cannot become recommendations.');
$mcc = PaymentReview::recommendations(['description' => 'Unknown', 'type' => 'Withdrawal', 'mcc' => 5411], [], $categories);
check($mcc[0]['name'] === 'Продукти' && str_contains($mcc[0]['reason'], 'перевір'), 'MCC is labelled as a tentative suggestion.');
$fallback = PaymentReview::recommendations(['description' => 'Unknown', 'type' => 'Withdrawal'], [], $categories);
check($fallback[0]['name'] === 'Покупки' && $fallback[0]['reason'] === 'Можливий варіант', 'Fallbacks are labelled as possibilities, not confirmations.');
$generic = PaymentReview::recommendations(
    ['description' => 'Unknown', 'merchant' => 'monobank', 'type' => 'Withdrawal'],
    [['description' => 'Different', 'merchant' => 'monobank', 'type' => 'Withdrawal', 'category_id' => '4']],
    ['4' => 'Доставка їжі']
);
check($generic === [], 'Generic bank counterparty names are not category evidence.');

$deposit = ['description' => 'Unknown income', 'merchant' => 'Sender', 'type' => 'Deposit', 'mcc' => 5411];
check(PaymentReview::recommendations($deposit, [], $categories) === [], 'Deposits never receive spending fallbacks or spending MCC suggestions.');
$incomeCategories = $categories + ['6' => 'Дохід ФОП', '7' => 'Повернення коштів', '8' => 'Подарунки', '9' => 'Інші доходи'];
$income           = PaymentReview::recommendations($deposit, [], $incomeCategories);
check(array_column($income, 'id') === ['6', '7', '8'], 'Deposit fallback choices use only existing income-style categories.');
check(PaymentReview::recommendations($deposit, [], ['9' => 'Інші доходи'])[0]['id'] === '9', 'A single existing income fallback remains available.');
$depositHistory = [
    ['description' => 'Unknown income', 'merchant' => 'Sender', 'type' => 'Deposit', 'category_id' => '7'],
    ['description' => 'Unknown income', 'merchant' => 'Sender', 'type' => 'Withdrawal', 'category_id' => '2']
];
$historicalIncome = PaymentReview::recommendations($deposit, $depositHistory, $incomeCategories);
check(
    $historicalIncome[0]['id'] === '7' && $historicalIncome[0]['reason'] === 'За попередніми подібними платежами',
    'Confirmed same-type income history outranks generic income fallbacks.'
);
check(!in_array('2', array_column($historicalIncome, 'id'), true), 'An opposite-type historical spending category is never suggested for a deposit.');
check(
    PaymentReview::recommendations(['description' => 'Unknown', 'type' => 'Transfer', 'mcc' => 5411], [], $categories) === [],
    'An unsupported transaction type does not inherit spending fallbacks.'
);

$review = [
    'version'    => 1,
    'kind'       => 'own_transfer',
    'status'     => 'pending',
    'key'        => 'provider:opaque',
    'account_id' => '10',
    'direction'  => 'in',
    'currency'   => 'UAH',
    'amount'     => '20000.00',
    'date'       => '2026-11-08T02:57:57+02:00'
];
$note = ['mcc' => 4829, 'bank_review_aliases' => ['other-provider:opaque'], 'bank_review' => $review];
check(PaymentReview::review(json_encode($note)) === $review, 'Structured review notes are parsed without changing bank metadata.');
check(PaymentReview::review('Original user note') === null, 'Plain user notes remain ordinary notes.');
check(PaymentReview::review('{"bank_review":{"version":99}}') === null, 'Unknown versions are not accepted as valid decisions.');
check(PaymentReview::decodeNote('["unexpected"]') === [], 'A JSON list is not a note dictionary.');
$accounts = [
    '10' => ['currency' => 'UAH', 'active' => true, 'suspense' => false],
    '11' => ['currency' => 'UAH', 'active' => true, 'suspense' => false],
    '12' => ['currency' => 'EUR', 'active' => true, 'suspense' => false],
    '13' => ['currency' => 'UAH', 'active' => true, 'suspense' => true],
    '14' => ['currency' => 'UAH', 'active' => false, 'suspense' => false]
];
$decision = PaymentReview::decide($review, 'transfer', null, '11', $categories, $accounts);
check(
    $decision['status'] === 'decided' && $decision['decision'] === ['kind' => 'transfer', 'account_id' => '11'],
    'A user-selected owned counterpart becomes a queued decision.'
);
foreach (array_keys($review) as $key) {
    if ($key !== 'status') {
        check($decision[$key] === $review[$key], 'Original review evidence must remain immutable: ' . $key);
    }
}
rejects(static fn() => PaymentReview::decide($decision, 'transfer', null, '11', $categories, $accounts));
$external = PaymentReview::decide($review, 'external', '3', null, $categories, $accounts);
check($external['decision'] === ['kind' => 'external', 'category_id' => '3'], 'An external payment decision records only the chosen category.');
foreach (['10', '12', '13', '14', 'another-owner'] as $id) {
    rejects(static fn() => PaymentReview::decide($review, 'transfer', null, $id, $categories, $accounts));
}
rejects(static fn() => PaymentReview::decide($review, 'external', '5', null, $categories, $accounts));
rejects(static fn() => PaymentReview::decide($review, 'external', 'another-owner', null, $categories, $accounts));
rejects(static fn() => PaymentReview::decide($review, 'unknown', null, null, $categories, $accounts));
rejects(static fn() => PaymentReview::decide(array_replace($review, ['status' => 'resolved']), 'transfer', null, '11', $categories, $accounts));

$state = ['id' => '1', 'owner' => '1', 'amount' => '20000.00', 'categories' => [], 'notes' => [$note]];
$token = PaymentReview::fingerprint($state);
PaymentReview::assertFingerprint($state, $token);
check(strlen($token) === 64 && $token === PaymentReview::fingerprint($state), 'The state fingerprint is deterministic.');
rejects(static fn() => PaymentReview::assertFingerprint(array_replace($state, ['amount' => '20001.00']), $token));
rejects(static fn() => PaymentReview::assertFingerprint(array_replace($state, ['owner' => 'another-owner']), $token));
rejects(static fn() => PaymentReview::assertFingerprint(array_replace($state, ['categories' => ['3']]), $token));
rejects(static fn() => PaymentReview::assertFingerprint(array_replace($state, ['notes' => []]), $token));
rejects(static fn() => PaymentReview::assertFingerprint($state, 'not-a-token'));

echo 'Passed ' . $checks . " payment review checks.\n";
