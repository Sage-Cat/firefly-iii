<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/Support/Reports/AccountingPeriod.php';
require_once dirname(__DIR__, 2) . '/app/Support/Reports/ProfitSummary.php';
require_once dirname(__DIR__, 2) . '/app/Support/Reports/CurrencyBridge.php';
require_once dirname(__DIR__, 2) . '/app/Support/Reports/RefundProjection.php';

use FireflyIII\Support\Reports\AccountingPeriod;
use FireflyIII\Support\Reports\ProfitSummary;

$checks = 0;
function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
    ++$GLOBALS['checks'];
}

function rejects(callable $call): void
{
    try {
        $call();
    } catch (RuntimeException $exception) {
        ++$GLOBALS['checks'];
        return;
    }
    throw new RuntimeException('An excluded or malformed accounting range was accepted.');
}

putenv('ACCOUNTING_START_DATE');
check(
    AccountingPeriod::boundary() === null && AccountingPeriod::start('2024-01-01', '2024-03-17') === '2024-01-01',
    'Unconfigured installations retain their original start.'
);
putenv('ACCOUNTING_START_DATE=');
check(AccountingPeriod::boundary() === null, 'An explicitly empty optional setting stays disabled.');
foreach (['2024-02-30', '2024-2-29', '2024-13-01', 'bad', ' 2024-02-29', "2024-02-29\n"] as $invalid) {
    putenv('ACCOUNTING_START_DATE=' . $invalid);
    rejects(fn() => AccountingPeriod::boundary());
}
putenv('ACCOUNTING_START_DATE=2024-02-29');
check(AccountingPeriod::start('2023-12-01', '2024-03-17') === '2024-02-29', 'A valid leap-day boundary clamps inclusive starts.');
check(AccountingPeriod::start('2024-03-05', '2024-03-17') === '2024-03-05', 'Later starts never move backward.');
rejects(fn() => AccountingPeriod::start('2023-12-01', '2024-02-28'));
rejects(fn() => AccountingPeriod::start('2024-03-18', '2024-03-17'));
$rows = [
    [
        'id'             => 'before',
        'type'           => 'Deposit',
        'day'            => '2024-02-28',
        'amount'         => '9999',
        'currency'       => 'EUR',
        'source_id'      => 'external',
        'destination_id' => 'owned'
    ],
    [
        'id'             => 'income',
        'type'           => 'Deposit',
        'day'            => '2024-02-29',
        'amount'         => '100',
        'currency'       => 'EUR',
        'source_id'      => 'external',
        'destination_id' => 'owned'
    ],
    [
        'id'             => 'expense',
        'type'           => 'Withdrawal',
        'day'            => '2024-03-01',
        'amount'         => '25',
        'currency'       => 'EUR',
        'source_id'      => 'owned',
        'destination_id' => 'external'
    ]
];
foreach ([
    'EUR' => ['income' => '100.00', 'expenses' => '25.00', 'net' => '75.00'],
    'UAH' => ['income' => '5000.00', 'expenses' => '1250.00', 'net' => '3750.00']
] as $currency => $totals) {
    foreach (['weekly', 'monthly', 'auto'] as $granularity) {
        $summary = ProfitSummary::build($rows, ['UAH' => '1', 'EUR' => '50'], ['owned'], '2023-12-01', '2024-03-17', $currency, $granularity);
        check(
            $summary['totals'] === $totals && $summary['transactions'] === 2,
            'Clamped totals retain the original FX and owner rules while excluding earlier flows.'
        );
        check(
            $summary['points'][0]['period'] === ('weekly' === $granularity ? '2024-W09' : '2024-02'),
            'The first label cannot describe a pre-boundary month.'
        );
        check($summary['points'][0]['start'] === '2024-02-29', 'Partial weekly/monthly buckets show their actual clamped start.');
        check(end($summary['points'])['cumulative_net'] === $totals['net'], 'Accumulation starts at the accounting boundary.');
    }
}
rejects(fn() => ProfitSummary::build([], [], [], '2023-12-01', '2024-02-28', 'EUR'));
check(
    ProfitSummary::period('2024-03-17', 12) === ['start' => '2024-02-29', 'end' => '2024-03-17'],
    'Preset bounds are clamped without invented earlier months.'
);
putenv('ACCOUNTING_START_DATE');
echo 'Passed ' . $checks . " accounting boundary checks.\n";
