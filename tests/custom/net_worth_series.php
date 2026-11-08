<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/app/Support/Reports/CurrencyBridge.php';
require dirname(__DIR__, 2) . '/app/Support/Reports/NetWorthSeries.php';

use FireflyIII\Support\Reports\NetWorthSeries;

$checks = 0;
function ensure(bool $condition): void
{
    global $checks;
    ++$checks;
    if (!$condition) {
        throw new RuntimeException('Net-worth series differs.');
    }
}

function fails(callable $fn): void
{
    try {
        $fn();
    } catch (RuntimeException) {
        ensure(true);
        return;
    }
    throw new RuntimeException('Expected a rejected series.');
}

function leg(string $id, string $account, string $day, string $currency, string $amount): array
{
    return ['id' => $id, 'account_id' => $account, 'day' => $day, 'currency' => $currency, 'amount' => $amount];
}

function asset(string $id, string $currency, null|string $known = '2026-10-01', bool $include = true): array
{
    return ['id' => $id, 'currency' => $currency, 'known_from' => $known, 'net_worth' => $include];
}

$accounts = [
    'a'    => asset('a', 'UAH'),
    'b'    => asset('b', 'EUR'),
    'c'    => asset('c', 'USD'),
    'd'    => asset('d', 'USD'),
    'e'    => asset('e', 'UAH'),
    'skip' => asset('skip', 'USD', '2026-11-07', false)
];
$quotes = ['UAH' => '1', 'EUR' => '50', 'USD' => '45', 'CHF' => '52', 'USDT' => '44'];
$legs   = [
    leg('1', 'a', '2026-10-01', 'UAH', '1000'),
    leg('2', 'b', '2026-10-01', 'EUR', '100'),
    leg('3', 'c', '2026-10-01', 'USD', '200'),
    leg('4', 'd', '2026-10-01', 'USD', '-50'),
    leg('5', 'a', '2026-10-10', 'UAH', '-500'),
    leg('6', 'e', '2026-10-10', 'UAH', '500'),
    leg('7', 'c', '2026-10-15', 'USD', '-50'),
    leg('8', 'b', '2026-10-15', 'EUR', '40'),
    leg('9', 'skip', '2026-10-09', 'USD', '99999'),
    leg('10', 'virtual-counterparty', '2026-10-09', 'UAH', '99999'),
    leg('11', 'b', '2026-12-01', 'EUR', '99999')
];
$result = NetWorthSeries::build($accounts, $legs, $quotes, '2026-10-09', '2026-11-07');
ensure(count($result['points']) === 30);
ensure($result['points'][0]['total'] === '255.00');
ensure($result['points'][1]['total'] === '255.00'); // A same-currency own transfer cancels.
ensure($result['points'][6]['total'] === '250.00'); // Executed FX is not replaced by an invented neutral transfer.
ensure($result['points'][29]['total'] === '250.00');
ensure($result['account_count'] === 5);
$late                    = $accounts;
$late['c']['known_from'] = '2026-11-06';
$covered                 = NetWorthSeries::build($late, $legs, $quotes, '2026-10-09', '2026-11-07');
ensure($covered['coverage_start'] === '2026-11-06');
ensure(count(array_filter($covered['points'], static fn($p) => null === $p['total'])) === 28);
ensure($covered['points'][28]['total'] === '250.00');
$round = NetWorthSeries::build(
    ['a' => asset('a', 'UAH')],
    [leg('1', 'a', '2026-10-09', 'UAH', '0.25'), leg('2', 'a', '2026-10-09', 'UAH', '0.25')],
    $quotes,
    '2026-10-09',
    '2026-10-09'
);
ensure($round['points'][0]['total'] === '0.01'); // Sum account balance before final EUR rounding.
$centAccounts = ['a' => asset('a', 'UAH'), 'b' => asset('b', 'UAH')];
$centLegs     = [leg('1', 'a', '2026-10-09', 'UAH', '0.50'), leg('2', 'a', '2026-10-10', 'UAH', '-0.25'), leg('3', 'b', '2026-10-10', 'UAH', '0.25')];
$cents        = NetWorthSeries::build($centAccounts, $centLegs, $quotes, '2026-10-09', '2026-10-10');
ensure(array_column($cents['points'], 'total') === ['0.01', '0.01']);
$negative = NetWorthSeries::build(['d' => asset('d', 'USD')], [leg('1', 'd', '2026-10-09', 'USD', '-50')], $quotes, '2026-10-09', '2026-10-09');
ensure($negative['points'][0]['total'] === '-45.00');
$crypto = NetWorthSeries::build(['x' => asset('x', 'USDT')], [leg('1', 'x', '2026-10-09', 'USDT', '100')], $quotes, '2026-10-09', '2026-10-09');
ensure($crypto['points'][0]['total'] === '90.00' && $crypto['manual_currencies'] === []);
$zero = NetWorthSeries::build(['a' => asset('a', 'UAH', null)], [], $quotes, '2026-10-09', '2026-10-10');
ensure(array_column($zero['points'], 'total') === ['0.00', '0.00']);
$future = NetWorthSeries::build(['a' => asset('a', 'EUR', '2026-12-01')], [leg('1', 'a', '2026-12-01', 'EUR', '100')], $quotes, '2026-10-09', '2026-10-09');
ensure($future['points'][0]['total'] === '0.00');
fails(static fn() => NetWorthSeries::build($accounts, [...$legs, $legs[0]], $quotes, '2026-10-09', '2026-11-07'));
fails(static fn() => NetWorthSeries::build($accounts, $legs, [], '2026-10-09', '2026-11-07'));
fails(static fn() => NetWorthSeries::build($accounts, $legs, $quotes, '2026-02-30', '2026-11-07'));
fails(static fn() => NetWorthSeries::build($accounts, $legs, $quotes, '2026-11-08', '2026-11-07'));
fails(static fn() => NetWorthSeries::build($accounts, [leg('1', 'a', '2026-10-09', 'UAH', 'NaN')], $quotes, '2026-10-09', '2026-10-09'));
$history                       = ['asset' => asset('asset', 'USD', '2026-11-06')];
$history['asset']['snapshots'] = [
    ['day' => '2026-01-01', 'currency' => 'EUR', 'amount' => '1000'],
    ['day' => '2026-02-01', 'currency' => 'USD', 'amount' => '1130'],
    ['day' => '2026-11-07', 'currency' => 'USD', 'amount' => '99999']
];
$legs   = [leg('opening', 'asset', '2026-11-06', 'USD', '1200'), leg('payment', 'asset', '2026-11-07', 'USD', '-100')];
$before = serialize([$history, $legs]);
$r      = NetWorthSeries::build($history, $legs, $quotes, '2026-01-01', '2026-01-02');
ensure(array_column($r['points'], 'total') === ['1000.00', '1000.00']);
$r = NetWorthSeries::build($history, $legs, $quotes, '2026-02-01', '2026-02-02');
ensure(array_column($r['points'], 'total') === ['1017.00', '1017.00']);
$r = NetWorthSeries::build($history, $legs, $quotes, '2026-11-05', '2026-11-07');
ensure(array_column($r['points'], 'total') === ['1017.00', '1080.00', '990.00']);
ensure($r['coverage_start'] === '2026-11-05');
ensure(serialize([$history, $legs]) === $before);
$history['unknown'] = asset('unknown', 'CHF', '2026-11-06');
$r                  = NetWorthSeries::build($history, $legs, $quotes, '2026-11-05', '2026-11-07');
ensure($r['points'][0]['total'] === null && $r['points'][0]['known_total'] === '1017.00' && $r['points'][0]['unknown_accounts'] === 1);
ensure($r['coverage_start'] === '2026-11-06');
$history['unknown']['known_from'] = '2026-10-06';
$r                                = NetWorthSeries::build($history, $legs, $quotes, '2026-11-05', '2026-11-07');
ensure($r['points'][0]['total'] === '1017.00');
$transfer           = leg('known-transfer', 'asset', '2026-11-05', 'USD', '-10');
$transfer['type']   = 'Transfer';
$oldOpening         = leg('old-baseline', 'asset', '2026-09-01', 'USD', '20000');
$oldOpening['type'] = 'Opening balance';
$r                  = NetWorthSeries::build($history, [...$legs, $transfer, $oldOpening], $quotes, '2026-11-05', '2026-11-07');
ensure($r['points'][0]['total'] === '1008.00');
echo 'Passed ' . $checks . " daily net-worth checks.\n";
