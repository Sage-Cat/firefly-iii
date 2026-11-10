<?php

declare(strict_types=1);

namespace FireflyIII\Support\Reports {
    class ReportingCurrency
    {
        public static function currency(string $code): object
        {
            return (object) ['id' => 1, 'name' => 'Euro', 'code' => 'EUR', 'symbol' => '€', 'decimal_places' => 2];
        }

        public static function quotes(): array
        {
            return ['UAH' => '1', 'EUR' => '50', 'USD' => '45'];
        }

        public static function today(): \Carbon\Carbon
        {
            return \Carbon\Carbon::parse('2026-11-08', 'Europe/Kyiv');
        }
    }

    class DashboardLedger
    {
        public static function data(): array
        {
            return [
                'accounts' => [
                    'bank'      => ['net_worth' => true, 'known_from' => '2026-08-06', 'currency' => 'USD'],
                    'portfolio' => [
                        'net_worth'  => true,
                        'known_from' => '2026-11-06',
                        'currency'   => 'USD',
                        'snapshots'  => [
                            ['day' => '2026-01-01', 'currency' => 'EUR', 'amount' => '1000'],
                            ['day' => '2026-09-01', 'currency' => 'USD', 'amount' => '1200']
                        ]
                    ]
                ],
                'legs'     => [
                    ['id' => '1', 'account_id' => 'bank', 'day' => '2026-08-06', 'currency' => 'USD', 'amount' => '1000'],
                    ['id' => '2', 'account_id' => 'portfolio', 'day' => '2026-11-06', 'currency' => 'USD', 'amount' => '1400']
                ]
            ];
        }
    }
}

namespace {
    function request()
    {
        return $GLOBALS['request'];
    }

    $fixtureAppRoot = getenv('FIREFLY_TEST_APP_ROOT') ?: dirname(__DIR__, 2);
    putenv('ACCOUNTING_START_DATE');
    require_once dirname(__DIR__, 2) . '/app/Support/Reports/AccountingPeriod.php';
    require $fixtureAppRoot . '/vendor/autoload.php';
    require dirname(__DIR__, 2) . '/app/Support/Reports/CurrencyBridge.php';
    require dirname(__DIR__, 2) . '/app/Support/Reports/NetWorthSeries.php';
    require dirname(__DIR__, 2) . '/app/Support/Reports/DashboardCapital.php';
    use FireflyIII\Support\Reports\DashboardCapital;

    $checks = 0;
    function check(bool $condition): void
    {
        global $checks;
        ++$checks;
        if (!$condition) {
            throw new RuntimeException('Dashboard capital differs.');
        }
    }

    foreach (['30d' => ['2026-10-10', 30, 1], '3m' => ['2026-08-09', 92, 1], '1y' => ['2025-11-09', 365, 2]] as $period => [$start, $count, $series]) {
        $GLOBALS['request'] = \Illuminate\Http\Request::create('/?capital_period=' . $period);
        $data               = DashboardCapital::data();
        $api                = DashboardCapital::api();
        check($data['start'] === $start && count($data['points']) === $count);
        check(count($api) === $series && end($api[0]['entries']) === '2160.00');
        check(count($api[0]['entries']) === $count && $api[0]['entries'] === $api[0]['pc_entries']);
        check($api[0]['currency_code'] === 'EUR' && $api[0]['primary_currency_code'] === 'EUR');
        if ($series === 2) {
            check(str_contains($api[1]['label'], 'неповні дані'));
            check(count(array_filter($api[1]['entries'], static fn($v) => null !== $v)) > 0);
        }
    }
    $GLOBALS['request'] = \Illuminate\Http\Request::create('/?capital_period=invalid');
    check(DashboardCapital::data()['period'] === '30d');
    putenv('ACCOUNTING_START_DATE=2026-11-05');
    foreach (['30d', '3m', '1y'] as $period) {
        $GLOBALS['request'] = \Illuminate\Http\Request::create('/?capital_period=' . $period);
        $data               = DashboardCapital::data();
        $api                = DashboardCapital::api();
        check($data['start'] === '2026-11-05' && count($data['points']) === 4);
        check($data['points'][0]['total'] === '1980.00' && end($data['points'])['total'] === '2160.00');
        check(array_key_first($api[0]['entries']) === '2026-11-05T12:00:00+02:00');
    }
    putenv('ACCOUNTING_START_DATE=2027-01-01');
    $GLOBALS['request'] = \Illuminate\Http\Request::create('/?capital_period=1y');
    check(DashboardCapital::data()['error'] !== null && DashboardCapital::api() === []);
    putenv('ACCOUNTING_START_DATE');
    echo "Passed {$checks} dashboard history period checks.\n";
}
