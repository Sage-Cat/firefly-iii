<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/app/Support/Reports/BalanceHistory.php';
use FireflyIII\Support\Reports\BalanceHistory;

$checks = 0;
function check(bool $test): void
{
    global $checks;
    ++$checks;
    if (!$test) {
        throw new RuntimeException('History differs.');
    }
}

function notes(array $data): string
{
    return 'User notes' . "\n<!-- firefly-balance-history:v1\n" . json_encode($data) . "\n-->";
}

function fails(string $text): void
{
    try {
        BalanceHistory::read($text);
    } catch (RuntimeException) {
        check(true);
        return;
    }
    throw new RuntimeException('Invalid history accepted.');
}

check(BalanceHistory::read('Unrelated notes') === ['snapshots' => [], 'ledger_from' => null]);
$data = [
    'version'     => 1,
    'ledger_from' => '2026-10-06',
    'snapshots'   => [
        ['day' => '2026-02-05', 'currency' => 'USD', 'amount' => '1130'],
        ['day' => '2026-01-07', 'currency' => 'EUR', 'amount' => '1000']
    ]
];
$result = BalanceHistory::read(notes($data));
check(array_column($result['snapshots'], 'currency') === ['EUR', 'USD']);
check($result['ledger_from'] === '2026-10-06');
fails(notes($data) . notes($data));
fails("<!-- firefly-balance-history:v1\n{broken\n-->");
$bad                        = $data;
$bad['snapshots'][0]['day'] = '2026-02-30';
fails(notes($bad));
$bad                           = $data;
$bad['snapshots'][0]['amount'] = '1e3';
fails(notes($bad));
$bad                             = $data;
$bad['snapshots'][0]['currency'] = '?';
fails(notes($bad));
$bad                = $data;
$bad['ledger_from'] = 'tomorrow';
fails(notes($bad));
$bad                = $data;
$bad['snapshots'][] = $bad['snapshots'][0];
fails(notes($bad));
echo "Passed {$checks} balance history checks.\n";
