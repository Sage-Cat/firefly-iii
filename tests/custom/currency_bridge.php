<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/app/Support/Reports/CurrencyBridge.php';

use FireflyIII\Support\Reports\CurrencyBridge;

$quotes = ['UAH' => '1', 'USD' => '45.50', 'EUR' => '50.00', 'CHF' => '52.10', 'USD/EUR' => '999'];
$cases  = [
    ['100',    'USD',     'UAH',  '4550.00'],
    ['100',    'USDT',    'UAH',  '4550.00'],
    ['100',    'USDT',    'EUR',  '91.00'],
    ['100',    'USDT',    'USD',  '100.00'],
    ['100',    'USD',     'USDT', '100.00'],
    ['4550',   'UAH',     'USD',  '100.00'],
    ['100',    'USD',     'EUR',  '91.00'],
    ['100',    'EUR',     'CHF',  '95.97'],
    ['-100',   'USD',     'EUR',  '-91.00'],
    ['0',      'UNKNOWN', 'EUR',  '0.00'],
    ['12.345', 'EUR',     'EUR',  '12.35'],
    ['10.05',  'USD',     'UAH',  '457.28']
];
foreach ($cases as [$amount, $from, $to, $expected]) {
    if (CurrencyBridge::convert($amount, $from, $to, $quotes) !== $expected) {
        throw new RuntimeException('The UAH bridge or final rounding differs.');
    }
}
if (CurrencyBridge::convert('0.01', 'USD', 'EUR', ['USD' => '0.49', 'EUR' => '0.35']) !== '0.01') {
    throw new RuntimeException('The UAH intermediate must not be rounded to cents.');
}
if (CurrencyBridge::convert('-0.01', 'USD', 'EUR', ['USD' => '0.49', 'EUR' => '0.35']) !== '-0.01') {
    throw new RuntimeException('Negative amounts must round symmetrically.');
}
if (CurrencyBridge::convert('100', 'USD', 'UAH', ['UAH' => '999', 'USD' => '45.5']) !== '4550.00') {
    throw new RuntimeException('UAH must remain the unit of the bridge.');
}
if (CurrencyBridge::convert('100', 'USDT', 'EUR', ['USDT' => '999', 'USD' => '45.5', 'EUR' => '50']) !== '91.00') {
    throw new RuntimeException('Stored token valuations must not override the selected USD proxy.');
}
$rejected = 0;
foreach ([[], ['USD' => '0', 'EUR' => '50'], ['USD' => '-1', 'EUR' => '50'], ['USD' => 'NaN', 'EUR' => '50'], ['USD' => 'Infinity', 'EUR' => '50']] as $bad) {
    try {
        CurrencyBridge::convert('1', 'USD', 'EUR', $bad);
    } catch (RuntimeException $exception) {
        ++$rejected;
    }
}
if (5 !== $rejected) {
    throw new RuntimeException('Missing or invalid quotes must fail closed.');
}
$rows = [];
foreach (['EUR' => '50', 'USD' => '45.5', 'CHF' => '52.1'] as $code => $value) {
    $rows[] = [
        'cc'            => $code,
        'rate'          => bcmul($value, '100', 4),
        'units'         => '100',
        'rate_per_unit' => $value,
        'special'       => null,
        'exchangedate'  => '07.11.2026',
        'calcdate'      => '06.11.2026'
    ];
}
if (CurrencyBridge::nbuQuotes($rows, '07.11.2026') !== ['UAH' => '1', 'EUR' => '50', 'USD' => '45.5', 'CHF' => '52.1']) {
    throw new RuntimeException('NBU per-unit quotes must use the establishment day.');
}
$failures = 0;
foreach (['date', 'missing', 'units', 'negative', 'conditions', 'conflict'] as $kind) {
    $bad = $rows;
    if ('date' === $kind) {
        $bad[0]['exchangedate'] = '06.11.2026';
    }
    if ('missing' === $kind) {
        array_pop($bad);
    }
    if ('units' === $kind) {
        $bad[0]['rate_per_unit'] = '5000';
    }
    if ('negative' === $kind) {
        $bad[0]['rate'] = '-5000';
    }
    if ('conditions' === $kind) {
        $bad[0]['special'] = 'unknown';
    }
    if ('conflict' === $kind) {
        $bad[] = array_replace($bad[0], ['rate' => '5100', 'rate_per_unit' => '51']);
    }
    try {
        CurrencyBridge::nbuQuotes($bad, '07.11.2026');
    } catch (RuntimeException $exception) {
        ++$failures;
    }
}
if (6 !== $failures) {
    throw new RuntimeException('Malformed NBU responses must not become quotes.');
}
echo "Passed 28 UAH bridge and NBU quote checks.\n";
