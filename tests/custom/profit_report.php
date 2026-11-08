<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/Support/Reports/RefundProjection.php';
require dirname(__DIR__, 2) . '/app/Support/Reports/ProfitSummary.php';
require_once dirname(__DIR__, 2) . '/app/Support/Reports/CurrencyBridge.php';

use FireflyIII\Support\Reports\ProfitSummary;

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
    } catch (RuntimeException|InvalidArgumentException $exception) {
        check(true, $exception->getMessage());

        return;
    }
    throw new RuntimeException('Expected an explicit rejection.');
}

function journal(
    string $id,
    string $type,
    string $amount,
    string $currency = 'EUR',
    string $day = '2026-11-01',
    string $source = '1',
    string $destination = 'external'
): array {
    return [
        'id'             => $id,
        'type'           => $type,
        'amount'         => $amount,
        'currency'       => $currency,
        'day'            => $day,
        'source_id'      => $source,
        'destination_id' => $destination
    ];
}

$income       = journal('income', 'Deposit', '-100', 'EUR', '2026-11-01', 'revenue', '1');
$expense      = journal('expense', 'Withdrawal', '-30', 'EUR');
$otherAccount = journal('other', 'Deposit', '-50', 'EUR', '2026-11-02', 'revenue', '2');
$ignored      = [
    journal('opening', 'Opening balance', '-9000'),
    journal('transfer', 'Transfer', '-6000'),
    journal('reconcile', 'Reconciliation', '-3000'),
    journal('internal', 'Deposit', '-1000', 'EUR', '2026-11-01', '1', '2'),
    journal('outsider', 'Deposit', '-10000', 'EUR', '2026-11-01', 'external', 'another-owner')
];
$rows = array_merge([$income, $expense, $otherAccount], $ignored);
$eur  = ProfitSummary::build($rows, [], ['1', '2'], '2026-11-01', '2026-11-07', 'EUR');
check(
    $eur['totals'] === ['income' => '150.00', 'expenses' => '30.00', 'net' => '120.00'],
    'Three aggregates must combine all assets with correct Deposit sign.'
);
check($eur['transactions'] === 3, 'Transfers, opening balances, reconciliation and other owners must be excluded.');
check(count($eur['points']) === 7 && $eur['daily'], 'The inclusive monthly range has daily zero-filled points.');
check($eur['points'][1]['net'] === '50.00' && $eur['points'][6]['net'] === '0.00', 'Points must include income-only and empty days.');
check(ProfitSummary::build($rows, [], [], '2026-11-01', '2026-11-07', 'EUR')['transactions'] === 0, 'Empty ownership must never select the entire ledger.');
$rates = ['UAH' => '1', 'EUR' => '50', 'USD' => '45', 'CHF' => '52'];
$uah   = ProfitSummary::build($rows, $rates, ['1', '2'], '2026-11-01', '2026-11-07', 'UAH');
check($uah['totals'] === ['income' => '7500.00', 'expenses' => '1500.00', 'net' => '6000.00'], 'The report-day NBU bridge must be applied before aggregation.');
check($uah['points'][1]['income'] === '2500.00', 'Different historical days use the same report-day rate.');
$foreign = $expense + ['foreign_amount' => '9999', 'pc_amount' => '8888'];
check(
    ProfitSummary::build([$foreign], $rates, ['1'], '2026-11-01', '2026-11-01', 'UAH')['totals']['expenses'] === '1500.00',
    'Foreign and global-primary amounts are not additional flows or report conversion sources.'
);
$negative = ProfitSummary::build([$expense], [], ['1'], '2026-11-01', '2026-11-01', 'EUR');
check($negative['totals']['net'] === '-30.00', 'A loss must remain negative.');
check(ProfitSummary::money('-3000.00') === '-3 000,00', 'The displayed result must preserve its sign.');
check(ProfitSummary::money('1234567.89') === '1 234 567,89', 'Money formatting keeps cents.');
$round = journal('round', 'Withdrawal', '-1', 'USD');
check(
    ProfitSummary::build([$round], ['USD' => '0.25', 'EUR' => '50'], ['1'], '2026-11-01', '2026-11-01', 'EUR')['totals']['expenses'] === '0.01',
    'Converted transactions round half up only after the UAH bridge.'
);
rejects(fn() => ProfitSummary::build([$otherAccount], [], ['1', '2'], '2026-11-01', '2026-11-07', 'UAH'));
rejects(fn() => ProfitSummary::build([$expense], ['EUR' => '0'], ['1'], '2026-11-01', '2026-11-07', 'UAH'));
rejects(fn() => ProfitSummary::build([$expense, $expense], [], ['1'], '2026-11-01', '2026-11-07', 'EUR'));
rejects(fn() => ProfitSummary::build([], [], [], '2026-11-01', '2026-11-07', 'USD'));
rejects(fn() => ProfitSummary::build([], [], [], '2026-02-30', '2026-11-07', 'EUR'));
rejects(fn() => ProfitSummary::build([], [], [], '2026-11-07', '2026-11-01', 'EUR'));
rejects(fn() => ProfitSummary::build([], [], [], '2000-01-01', '2026-11-01', 'EUR'));
$cryptoOpening = journal('crypto-opening', 'Opening balance', '-1000', 'USDT');
check(
    ProfitSummary::build([$cryptoOpening], [], ['1'], '2026-11-01', '2026-11-07', 'EUR')['totals']['net'] === '0.00',
    'An unsupported opening value is not profit.'
);
$cryptoExpense = journal('crypto-expense', 'Withdrawal', '-10', 'USDT');
rejects(fn() => ProfitSummary::build([$cryptoExpense], [], ['1'], '2026-11-01', '2026-11-07', 'EUR'));
$outside = journal('outside-date', 'Withdrawal', '-10', 'USD', '2026-10-31');
check(
    ProfitSummary::build([$outside], [], ['1'], '2026-11-01', '2026-11-07', 'EUR')['transactions'] === 0,
    'Out-of-range dates must not require rates or enter totals.'
);
$annual = ProfitSummary::build([], [], [], '2025-12-01', '2026-11-07', 'EUR');
check(count($annual['points']) === 12 && !$annual['daily'], 'The year preset must produce 12 aggregate monthly buckets.');
check($annual['points'][0]['period'] === '2025-12' && $annual['points'][11]['period'] === '2026-11', 'Calendar buckets must preserve the year boundary.');
check(array_keys($annual['points'][0]) === ['period', 'income', 'expenses', 'net'], 'No per-account series may appear in the report.');

echo 'Passed ' . $checks . " profit report checks.\n";
