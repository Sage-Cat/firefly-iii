<?php

declare(strict_types=1);

namespace Carbon {
    class Carbon extends \DateTimeImmutable {}
}

namespace Illuminate\Support {
    class Collection
    {
        public function isEmpty(): bool
        {
            return true;
        }
    }
}

namespace FireflyIII\Support\Reports {
    final class ReportingCurrency
    {
        public static array $quotes = ['UAH' => '1', 'EUR' => '50', 'USD' => '40'];

        public static function convert(string $amount, string $source, string $target): string
        {
            return CurrencyBridge::convert($amount, $source, $target, self::$quotes);
        }

        public static function currency(string $code): object
        {
            $values = ['EUR' => [1, '€'], 'USD' => [2, '$'], 'UAH' => [3, '₴']];

            return (object) ['id' => $values[$code][0], 'code' => $code, 'name' => $code, 'symbol' => $values[$code][1], 'decimal_places' => 2];
        }
    }
}

namespace {
    use Carbon\Carbon;
    use FireflyIII\Support\Reports\ExpenseProjection;
    use FireflyIII\Support\Reports\ProfitSummary;
    use FireflyIII\Support\Reports\ReportingCurrency;

    require dirname(__DIR__, 2) . '/app/Support/Reports/CurrencyBridge.php';
    require dirname(__DIR__, 2) . '/app/Support/Reports/RefundProjection.php';
    require dirname(__DIR__, 2) . '/app/Support/Reports/ExpenseProjection.php';
    require dirname(__DIR__, 2) . '/app/Support/Reports/ProfitSummary.php';

    $checks          = 0;
    $dependencyCalls = [];
    function check(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
        ++$GLOBALS['checks'];
    }

    function rejects(callable $call, string $message): void
    {
        try {
            $call();
        } catch (RuntimeException) {
            check(true, $message);
            return;
        }
        throw new RuntimeException($message);
    }

    function trans(string $key): string
    {
        return 'firefly.earned' === $key ? 'Доходи' : 'Витрати';
    }

    function auth(): object
    {
        return new class {
            public function user(): object
            {
                return (object) ['user_group_id' => 'owner'];
            }
        };
    }

    function config(string $key): array
    {
        return ['Asset account', 'Default account'];
    }

    function app(string $class): object
    {
        $GLOBALS['dependencyCalls'][] = $class;
        if (str_contains($class, 'AccountRepositoryInterface')) {
            return new class {
                public function getAccountsByType(array $types): object
                {
                    return new \Illuminate\Support\Collection();
                }

                public function setUser(object $user): void {}
            };
        }
        throw new RuntimeException('An empty owned-account set reached another dependency.');
    }

    function deposit(string $id, string $amount, string $code, string $name, array $extra = []): array
    {
        return array_replace([
            'transaction_journal_id' => $id,
            'transaction_type_type'  => 'Deposit',
            'amount'                 => $amount,
            'currency_code'          => $code,
            'date'                   => '2026-11-04',
            'source_account_id'      => 'client',
            'source_account_name'    => $name,
            'destination_account_id' => 'fop',
            'category_name'          => 'Дохід',
            'user_group_id'          => 'owner',
            'pc_amount'              => '-999999.99',
            'foreign_amount'         => '-888888.88'
        ], $extra);
    }

    $owned = ['personal', 'fop'];
    $rows  = [
        deposit('invoice-usd', '-125', 'USD', 'Client A'),
        deposit('invoice-uah', '-1000', 'UAH', 'Client A'),
        deposit('invoice-eur', '-40', 'EUR', 'Client B'),
        deposit('linked-refund', '-2', 'EUR', 'Refund A', ['is_refund' => true, 'refund_original_id' => 'charge']),
        deposit('unlinked-refund', '-100', 'UAH', 'Refund B', ['category_name' => 'Повернення коштів']),
        deposit('own-deposit', '-999', 'UAH', 'Own', ['source_account_id' => 'personal']),
        deposit('transfer', '-999', 'EUR', 'Own', ['transaction_type_type' => 'Transfer', 'source_account_id' => 'personal']),
        deposit('opening', '-999', 'EUR', 'Opening', ['transaction_type_type' => 'Opening balance']),
        deposit('expense', '-999', 'EUR', 'Shop', [
            'transaction_type_type'  => 'Withdrawal',
            'source_account_id'      => 'personal',
            'destination_account_id' => 'shop'
        ]),
        deposit('outside', '-999', 'EUR', 'Outside', ['destination_account_id' => 'other-bank']),
        deposit('other-owner', '-999', 'UAH', 'Other owner', ['user_group_id' => 'other']),
        deposit('old', '-999', 'EUR', 'Old', ['date' => '2026-10-31']),
        deposit('future', '-999', 'EUR', 'Future', ['date' => '2026-11-10']),
        deposit('unknown-name', '-50', 'UAH', '  '),
        deposit('kyiv-edge', '-100', 'UAH', 'Client B', ['date' => '2026-10-31T23:30:00Z'])
    ];
    $before  = serialize($rows);
    $chart   = ExpenseProjection::revenueChartFrom($rows, $owned, '2026-11-01', '2026-11-09', 'owner');
    $entries = $chart[0]['entries'];
    check(
        $entries === ['Client A' => '120.00', 'Client B' => '42.00', 'Інше' => '1.00'],
        'All genuine income uses original native amounts converted through the current UAH bridge to EUR.'
    );
    check(
        $chart[0]['currency_code'] === 'EUR' && $chart[0]['currency_symbol'] === '€' && $chart[0]['currency_decimal_places'] === 2,
        'Revenue chart data and metadata stay EUR.'
    );
    check(!isset($entries['Refund A']) && !isset($entries['Refund B']), 'Neither linked nor unlinked refunds may create an income source.');
    check(count($entries) === 3, 'Opening balances, transfers, other owners, external destinations and outside dates do not become income.');
    check(serialize($rows) === $before, 'The income display preserves the original financial records and historical primary amounts.');
    $ledgerRows = [];
    foreach ($rows as $row) {
        if ($row['user_group_id'] !== 'owner') {
            continue;
        }
        $day = new DateTimeImmutable($row['date'], new DateTimeZone('UTC'))
            ->setTimezone(new DateTimeZone('Europe/Kyiv'))
            ->format('Y-m-d');

        $ledgerRows[] = $row
        + [
            'id'             => $row['transaction_journal_id'],
            'type'           => $row['transaction_type_type'],
            'day'            => $day,
            'source_id'      => $row['source_account_id'],
            'destination_id' => $row['destination_account_id'],
            'currency'       => $row['currency_code']
        ];
    }
    $summary = ProfitSummary::build($ledgerRows, ReportingCurrency::$quotes, $owned, '2026-11-01', '2026-11-09', 'EUR');
    $total   = array_reduce($entries, static fn(string $sum, string $amount): string => bcadd($sum, $amount, 2), '0.00');
    check($total === '163.00' && $total === $summary['totals']['income'], 'Revenue source totals exactly reconcile with the EUR income ledger.');
    check(
        ExpenseProjection::revenueChartFrom($rows, $owned, '2026-11-09', '2026-11-01', 'owner')[0]['entries'] === $entries,
        'Reversed dates preserve the selected income period.'
    );
    check(
        ExpenseProjection::revenueChartFrom([$rows[3], $rows[4]], $owned, '2026-11-01', '2026-11-09', 'owner')[0]['entries'] === [],
        'A refund-only period has no income bars.'
    );
    check(
        ExpenseProjection::revenueChartFrom($rows, [], '2026-11-01', '2026-11-09', 'owner')[0]['entries'] === [],
        'No owned accounts can never expose an income row.'
    );
    check(
        ExpenseProjection::revenueChart(new Carbon('2026-11-01'), new Carbon('2026-11-09'))[0]['entries'] === [],
        'Empty owned accounts bypass collection and refund metadata queries.'
    );
    check(count($dependencyCalls) === 1, 'An empty asset set resolves only its scoped account repository.');
    rejects(fn() => ExpenseProjection::revenueChartFrom(
        [$rows[0], $rows[0]],
        $owned,
        '2026-11-01',
        '2026-11-09',
        'owner'
    ), 'Duplicate income journals must fail closed.');
    ReportingCurrency::$quotes['EUR'] = '100';
    check(
        ExpenseProjection::revenueChartFrom([$rows[0]], $owned, '2026-11-01', '2026-11-09', 'owner')[0]['entries']['Client A'] === '50.00',
        'A regenerated chart uses the current quote rather than saved primary values.'
    );
    unset(ReportingCurrency::$quotes['USD']);
    rejects(fn() => ExpenseProjection::revenueChartFrom(
        [$rows[0]],
        $owned,
        '2026-11-01',
        '2026-11-09',
        'owner'
    ), 'Missing quotes must not become guessed income amounts.');
    echo 'Passed ' . $checks . " revenue chart checks.\n";
}
