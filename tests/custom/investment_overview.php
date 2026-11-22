<?php

declare(strict_types=1);

require dirname(__DIR__, 2).'/app/Support/Reports/CurrencyBridge.php';

require dirname(__DIR__, 2).'/app/Support/Reports/NetWorthSeries.php';

require dirname(__DIR__, 2).'/app/Support/Reports/InvestmentOverview.php';

use FireflyIII\Support\Reports\InvestmentOverview;

// Laravel turns warnings into exceptions; malformed metadata must still fail as RuntimeException.
set_error_handler(static function (int $level, string $message): never {
    throw new ErrorException($message, 0, $level);
});
$checks                                                = 0;
function ensure(bool $condition, string $message = 'Investment overview differs.'): void
{
    global $checks;
    ++$checks;
    if (!$condition) {
        throw new RuntimeException($message);
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

throw new RuntimeException('Expected a rejected investment input.');
}
function block(array $data): string
{
    return "Owner text.\n\n<!-- firefly-investment:v1\n".json_encode($data, JSON_UNESCAPED_UNICODE)."\n-->\n\n<!-- firefly-balance-history:v1\n{}\n-->";
}
function leg(string $id, string $account, string $day, string $currency, string $amount, string $type, ?string $category = null, ?string $journal = null): array
{
    return ['id' => $id, 'journal_id' => $journal ?? $id, 'account_id' => $account, 'day' => $day, 'currency' => $currency, 'amount' => $amount, 'type' => $type, 'category_name' => $category];
}
function account(string $id, string $name, string $currency, ?string $known, array $snapshots = [], ?array $profile = null, bool $netWorth = true): array
{
    return ['id' => $id, 'name' => $name, 'currency' => $currency, 'known_from' => $known, 'snapshots' => $snapshots, 'net_worth' => $netWorth, 'profile' => $profile];
}
function snapshot(string $day, string $currency, string $amount): array
{
    return ['day' => $day, 'currency' => $currency, 'amount' => $amount];
}

// Parsing is strict: absent means "not an investment", anything malformed is rejected.
ensure(null === InvestmentOverview::read("Plain notes\n<!-- firefly-balance-history:v1\n{}\n-->"));
$bondProfile                                           = ['version' => 1, 'kind' => 'bond', 'maturity' => '2027-12-15', 'yield_to_maturity' => '3.2',
    'purchases'           => [['day' => '2026-06-22', 'amount' => '2000.00', 'currency' => 'EUR']], 'source' => 'Owner'];
ensure(InvestmentOverview::read(block($bondProfile)) === $bondProfile);
fails(static fn () => InvestmentOverview::read(block($bondProfile).block($bondProfile)));
fails(static fn () => InvestmentOverview::read("<!-- firefly-investment:v1\n{broken\n-->"));
fails(static fn () => InvestmentOverview::read("<!-- firefly-investment:v1\n[1]\n-->"));
foreach ([['kind' => 'house'], ['kind'             => []], ['kind' => 1], ['version' => 2], ['version' => '1'], ['extra' => 'x'],
    ['target_share' => '101'], ['target_share'     => '-1'], ['target_share' => 40], ['target_share' => []],
    ['yield_to_maturity' => '2,5'], ['maturity'    => '2027-02-30'], ['maturity' => 20271215], ['maturity' => null],
    ['income_category' => ' '], ['income_category' => []], ['source' => null],
    ['purchases' => 'x'], ['purchases'             => ['a' => ['day' => '2026-06-22', 'amount' => '1', 'currency' => 'EUR']]], ['purchases' => ['x']],
    ['purchases' => [['day' => '2026-06-22', 'amount' => '-1', 'currency' => 'EUR']]],
    ['purchases' => [['day' => '2026-06-22', 'amount' => 1, 'currency' => 'EUR']]],
    ['purchases' => [['day' => '2026-06-22', 'amount' => '1', 'currency' => 'eur']]],
    ['purchases' => [['day' => '2026-06-22', 'amount' => '1', 'currency' => 'EUR', 'note' => 'x']]],
    ['purchases' => [['day' => [], 'amount' => '1', 'currency' => 'EUR']]],
    ['purchases' => [['day' => '22.06.2026', 'amount' => '1', 'currency' => 'EUR']]]] as $change) {
    fails(static fn () => InvestmentOverview::read(block(array_merge($bondProfile, $change))));
}

$quotes                                                = ['UAH' => '1', 'EUR' => '40', 'USD' => '36', 'USDT' => '36'];
$accounts                                              = [
    'cash'   => account('cash', 'Cash', 'UAH', '2026-03-01'),
    'card'   => account('card', 'Card EUR', 'EUR', '2026-03-01'),
    'ubond'  => account(
        'ubond',
        'Bonds UAH',
        'UAH',
        '2026-03-06',
        [snapshot('2026-03-01', 'UAH', '120000'), snapshot('2026-05-04', 'UAH', '75000')],
        ['version' => 1, 'kind' => 'bond', 'income_category' => 'Coupons', 'target_share' => '0']
    ),
    'ebond'  => account('ebond', 'Bonds EUR', 'EUR', '2026-06-22', [snapshot('2026-03-01', 'EUR', '0')], $bondProfile),
    'broker' => account(
        'broker',
        'Broker',
        'USD',
        '2026-06-06',
        [snapshot('2026-03-01', 'USD', '8000'), snapshot('2026-05-19', 'USD', '9500')],
        ['version' => 1, 'kind' => 'broker', 'target_share' => '40']
    ),
    'hidden' => account('hidden', 'Excluded', 'USD', '2026-03-01', [], ['version' => 1, 'kind' => 'other'], false),
];
$legs                                                  = [
    leg('1', 'cash', '2026-03-01', 'UAH', '5000', 'Opening balance'),
    leg('2', 'card', '2026-03-01', 'EUR', '4000', 'Opening balance'),
    leg('3', 'ubond', '2026-03-06', 'UAH', '100000', 'Opening balance'),
    leg('4', 'ubond', '2026-03-19', 'UAH', '-10000', 'Transfer', null, 'j4'), leg('5', 'cash', '2026-03-19', 'UAH', '10000', 'Transfer', null, 'j4'),
    leg('6', 'cash', '2026-03-19', 'UAH', '900', 'Deposit', 'Coupons'),
    leg('7', 'ubond', '2026-04-16', 'UAH', '-15000', 'Transfer', null, 'j7'), leg('8', 'cash', '2026-04-16', 'UAH', '15000', 'Transfer', null, 'j7'),
    leg('9', 'cash', '2026-04-16', 'UAH', '1200.5', 'Deposit', 'Coupons'),
    leg('10', 'cash', '2026-04-20', 'UAH', '-300', 'Withdrawal', 'Coupons'),
    leg('11', 'broker', '2026-06-06', 'USD', '9500', 'Opening balance'),
    leg('12', 'card', '2026-06-22', 'EUR', '-2000', 'Transfer', null, 'j12'), leg('13', 'ebond', '2026-06-22', 'EUR', '2000', 'Transfer', null, 'j12'),
    leg('14', 'hidden', '2026-03-01', 'USD', '999', 'Opening balance'),
    leg('15', 'card', '2026-07-01', 'EUR', '99999', 'Deposit'),
    // Before the window: they move balances but not the period's income or flows.
    leg('16', 'cash', '2026-02-20', 'UAH', '700', 'Deposit', 'Coupons'),
    leg('17', 'card', '2026-02-10', 'EUR', '-500', 'Transfer', null, 'j17'), leg('18', 'ebond', '2026-02-10', 'EUR', '500', 'Transfer', null, 'j17'),
    leg('19', 'ebond', '2026-02-11', 'EUR', '-500', 'Transfer', null, 'j19'), leg('20', 'card', '2026-02-11', 'EUR', '500', 'Transfer', null, 'j19'),
];
$before                                                = serialize([$accounts, $legs]);
$result                                                = InvestmentOverview::build($accounts, $legs, $quotes, '2026-03-01', '2026-06-22');
ensure(serialize([$accounts, $legs]) === $before, 'Inputs must stay unchanged.');
ensure(array_column($result['rows'], 'name') === ['Bonds EUR', 'Bonds UAH', 'Broker'], 'Only included investments, sorted by name.');
ensure($result['excluded'] === ['Excluded'], 'Investments outside net worth are named, not silently dropped.');
[$ebond, $ubond, $broker]                              = $result['rows'];
ensure('2000.00' === $ebond['native'] && '2000.00' === $ebond['eur'] && '80000.00' === $ebond['uah'] && [] === $ebond['foreign_currencies']);
ensure('75000.00' === $ubond['native'] && '1875.00' === $ubond['eur'] && '75000.00' === $ubond['uah']);
ensure('9500.00' === $broker['native'] && '8550.00' === $broker['eur'] && '342000.00' === $broker['uah']);
ensure('12425.00' === $result['total_eur'] && '497000.00' === $result['total_uah']);
ensure('16.10' === $ebond['allocation'] && '15.09' === $ubond['allocation'] && '68.81' === $broker['allocation']);
// Capital: cash 5000+700+10000+900+15000+1200.5-300 UAH = 32500.5 UAH, card 2000 EUR, investments 12425 EUR.
ensure('15237.51' === $result['capital_eur'] && '81.54' === $result['capital_share'], 'Capital is the full net worth on the last day.');
ensure('56.11' === $broker['capital_share'] && '13.13' === $ebond['capital_share'] && '12.31' === $ubond['capital_share']);
ensure('40' === $broker['target_share'] && '0' === $ubond['target_share'] && null === $ebond['target_share']);
// The broker opening balance repeats the 19 May valuation; a 4 May statement confirms the UAH bonds after their transfers.
ensure('2026-06-22' === $ebond['valued_on'] && '2026-05-04' === $ubond['valued_on'] && '2026-05-19' === $broker['valued_on']);
ensure($ubond['income'] === ['UAH' => '2100.50'], 'Only positive coupon deposits inside the window count as income.');
ensure('25000.00' === $ubond['withdrawn'] && '0.00' === $ubond['contributed']);
ensure('2000.00' === $ebond['contributed'] && '0.00' === $ebond['withdrawn'], 'Transfers before the window are not period movements.');
ensure('2027-12-15' === $ebond['bond']['maturity'] && 541 === $ebond['bond']['days_left']);
ensure('2000.00' === $ebond['bond']['invested'] && '94.86' === $ebond['bond']['projected'], '2000 × 3.2% × 541/365.');
ensure(null === $ubond['bond'] && null === $broker['bond'], 'No invented maturity.');
ensure(114 === count($result['history']) && '2026-03-01' === $result['history'][0]['day'] && 0 === $result['incomplete_days']);
ensure('10200.00' === $result['history'][0]['total'], 'Snapshots value the first day: 120000 UAH and 8000 USD.');
ensure('12425.00' === end($result['history'])['total']);
ensure($result['flows'] === [
    ['month' => '2026-03', 'income' => '22.50', 'contributed' => '0.00', 'withdrawn' => '250.00'],
    ['month' => '2026-04', 'income' => '30.01', 'contributed' => '0.00', 'withdrawn' => '375.00'],
    ['month' => '2026-06', 'income' => '0.00', 'contributed' => '2000.00', 'withdrawn' => '0.00'],
]);

// A transfer between two positions counts for each position, but not as money entering or leaving the group.
$inside                                                = array_merge($legs, [leg('21', 'ubond', '2026-06-15', 'UAH', '-3600', 'Transfer', null, 'j21'), leg('22', 'broker', '2026-06-15', 'USD', '100', 'Transfer', null, 'j21')]);
$moved                                                 = InvestmentOverview::build($accounts, $inside, $quotes, '2026-03-01', '2026-06-22');
ensure($moved['flows'] === $result['flows'] && '12425.00' === $moved['total_eur']);
ensure('28600.00' === $moved['rows'][1]['withdrawn'] && '100.00' === $moved['rows'][2]['contributed'] && '2026-06-15' === $moved['rows'][2]['valued_on']);

// Valuation dates follow the value actually used.
$quotes2                                               = ['UAH' => '1', 'EUR' => '50', 'USD' => '45'];
$single                                                = static fn (array $account, array $legs, string $today): array => InvestmentOverview::build([$account['id'] => $account], $legs, $quotes2, '2026-11-01', $today)['rows'][0];
$profile                                               = ['version' => 1, 'kind' => 'broker'];
$later                                                 = $single(account('a', 'A', 'EUR', '2026-11-06', [snapshot('2026-11-20', 'EUR', '99999')], $profile), [leg('a1', 'a', '2026-11-06', 'EUR', '1000', 'Opening balance')], '2026-11-22');
ensure('1000.00' === $later['native'] && '2026-11-06' === $later['valued_on'], 'A snapshot after the ledger start is not the valuation.');
$confirmed                                             = $single(
    account('a', 'A', 'EUR', '2026-11-06', [snapshot('2026-11-01', 'EUR', '900'), snapshot('2026-11-15', 'EUR', '800'), snapshot('2026-11-20', 'EUR', '777')], $profile),
    [leg('a1', 'a', '2026-11-06', 'EUR', '1000', 'Opening balance'), leg('a2', 'a', '2026-11-10', 'EUR', '-200', 'Transfer'), leg('a3', 'a', '2026-11-21', 'EUR', '5', 'Deposit')],
    '2026-11-22'
);
ensure('805.00' === $confirmed['native'] && '2026-11-21' === $confirmed['valued_on']);
$confirmed                                             = $single(
    account('a', 'A', 'EUR', '2026-11-06', [snapshot('2026-11-15', 'EUR', '800')], $profile),
    [leg('a1', 'a', '2026-11-06', 'EUR', '1000', 'Opening balance'), leg('a2', 'a', '2026-11-10', 'EUR', '-200', 'Transfer')],
    '2026-11-22'
);
ensure('800.00' === $confirmed['native'] && '2026-11-15' === $confirmed['valued_on'], 'A matching later valuation confirms the ledger.');
$plain                                                 = $single(account('a', 'A', 'EUR', null, [], $profile), [leg('a1', 'a', '2026-11-02', 'EUR', '10', 'Opening balance'), leg('a2', 'a', '2026-11-04', 'EUR', '5', 'Deposit')], '2026-11-22');
ensure('15.00' === $plain['native'] && '2026-11-04' === $plain['valued_on']);
$before                                                = $single(
    account('a', 'A', 'EUR', '2026-12-01', [snapshot('2026-10-01', 'EUR', '500')], $profile),
    [leg('a1', 'a', '2026-11-10', 'EUR', '50', 'Deposit'), leg('a2', 'a', '2026-12-01', 'EUR', '550', 'Opening balance')],
    '2026-11-22'
);
ensure('550.00' === $before['native'] && '550.00' === $before['eur'] && '2026-11-10' === $before['valued_on'], 'Before the ledger: snapshot plus movements.');
$notYet                                                = $single(account('a', 'A', 'EUR', '2026-12-01', [], $profile), [], '2026-11-22');
ensure('0.00' === $notYet['native'] && '0.00' === $notYet['eur'] && null === $notYet['valued_on']);

// USDT is valued as USD; a deposit in another currency is converted, so all three columns agree.
$coins                                                 = $single(
    account('c', 'Coins', 'USDT', '2026-11-01', [], ['version' => 1, 'kind' => 'crypto']),
    [leg('c1', 'c', '2026-11-01', 'USDT', '100', 'Opening balance'), leg('c2', 'c', '2026-11-10', 'UAH', '4500', 'Deposit')],
    '2026-11-22'
);
ensure('200.00' === $coins['native'] && '9000.00' === $coins['uah'] && '180.00' === $coins['eur'] && $coins['foreign_currencies'] === ['UAH']);
$coins                                                 = $single(
    account('c', 'Coins', 'USDT', '2026-11-01', [snapshot('2026-11-20', 'USDT', '100')], ['version' => 1, 'kind' => 'crypto']),
    [leg('c1', 'c', '2026-11-01', 'USDT', '100', 'Opening balance'), leg('c2', 'c', '2026-11-10', 'UAH', '4500', 'Deposit')],
    '2026-11-22'
);
ensure('2026-11-10' === $coins['valued_on'], 'A valuation that omits another currency does not confirm the balance.');

// Before their ledgers start, positions are valued by the snapshot in use.
$early                                                 = InvestmentOverview::build($accounts, $legs, $quotes, '2026-03-01', '2026-06-05');
ensure('2026-03-01' === $early['rows'][0]['valued_on'] && '2026-05-19' === $early['rows'][2]['valued_on'] && '9500.00' === $early['rows'][2]['native']);
// Shares of non-positive capital or an unvalued day are reported as unknown rather than as a smaller total.
$debt                                                  = InvestmentOverview::build(
    $accounts + ['loan' => account('loan', 'Loan', 'UAH', '2026-03-01')],
    array_merge($legs, [leg('l1', 'loan', '2026-03-01', 'UAH', '-1000000', 'Opening balance')]),
    $quotes,
    '2026-03-01',
    '2026-06-22'
);
ensure(bccomp($debt['capital_eur'], '0', 2) < 0 && null === $debt['capital_share'] && null === $debt['rows'][0]['capital_share'] && '16.10' === $debt['rows'][0]['allocation']);
$late                                                  = $accounts;
$late['ubond']['snapshots']                            = [];
$gaps                                                  = InvestmentOverview::build($late, $legs, $quotes, '2026-03-01', '2026-06-22');
ensure(5 === $gaps['incomplete_days'] && null === $gaps['history'][4]['total'] && '9700.00' === $gaps['history'][5]['total']);
$empty                                                 = InvestmentOverview::build(['ebond' => $accounts['ebond']], $legs, $quotes, '2026-03-01', '2026-06-21');
ensure('0.00' === $empty['total_eur'] && null === $empty['rows'][0]['allocation'] && null === $empty['capital_share']);

// As in net worth, a position whose ledger starts later and has no valuation is not held yet.
$gap                                                   = $accounts;
$gap['broker']['snapshots']                            = [];
$notHeld                                               = InvestmentOverview::build($gap, $legs, $quotes, '2026-03-01', '2026-06-05')['rows'][2];
ensure('0.00' === $notHeld['eur'] && '0.00' === $notHeld['native'] && null === $notHeld['valued_on']);

// A duplicated income category, a wrong purchase currency or a missing rate fails closed.
$twice                                                 = $accounts;
$twice['ebond']['profile']['income_category']          = 'Coupons';
fails(static fn () => InvestmentOverview::build($twice, $legs, $quotes, '2026-03-01', '2026-06-22'));
$wrong                                                 = $accounts;
$wrong['ebond']['profile']['purchases'][0]['currency'] = 'USD';
fails(static fn () => InvestmentOverview::build($wrong, $legs, $quotes, '2026-03-01', '2026-06-22'));
fails(static fn () => InvestmentOverview::build($accounts, $legs, ['UAH' => '1', 'EUR' => '40'], '2026-03-01', '2026-06-22'));
$none                                                  = InvestmentOverview::build(['cash' => $accounts['cash']], $legs, $quotes, '2026-06-01', '2026-06-22');
ensure([] === $none['rows'] && [] === $none['excluded'] && '0' === $none['total_eur'] && '0.00' === $none['capital_share']);

echo 'Passed '.$checks." investment overview checks.\n";
