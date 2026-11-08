<?php

declare(strict_types=1);

namespace FireflyIII\Support\Reports {
    final class DashboardLedger
    {
        public static array $fixture = ['accounts' => [], 'legs' => []];
        public static int   $calls   = 0;

        public static function data(): array
        {
            ++self::$calls;
            return self::$fixture;
        }
    }

    final class ReportingCurrency
    {
        public static function convert(...$arguments): never
        {
            throw new \RuntimeException('No conversion is allowed.');
        }

        public static function currency(string $code): object
        {
            $symbols = ['USD' => '$', 'EUR' => '€', 'UAH' => '₴', 'CHF' => 'CHF', 'USDT' => '₮'];
            if (!isset($symbols[$code])) {
                throw new \RuntimeException('Unexpected currency.');
            }
            return (object) [
                'id'             => array_search($code, array_keys($symbols), true) + 1,
                'code'           => $code,
                'symbol'         => $symbols[$code],
                'decimal_places' => 'USDT' === $code ? 6 : 2
            ];
        }

        public static function today(): \Carbon\Carbon
        {
            return \Carbon\Carbon::parse('2026-11-07', 'Europe/Kyiv');
        }

        public static array $uahQuotes = ['UAH' => '1', 'EUR' => '50', 'USD' => '45', 'CHF' => '52', 'USDT' => '44'];

        public static function quotes(): array
        {
            return self::$uahQuotes;
        }

        public static function rate(...$arguments): never
        {
            throw new \RuntimeException('No rates are allowed.');
        }
    }
}

namespace FireflyIII\Support\Facades {
    final class Amount
    {
        public static function formatAnything($currency, $value, $colored): string
        {
            return bcadd((string) $value, '0', $currency->decimal_places) . ' ' . $currency->code;
        }
    }
}

namespace {
    function auth()
    {
        return new class {
            public function user(): object
            {
                return $GLOBALS['user'];
            }
        };
    }

    function trans($key, $parameters = []): string
    {
        return $key;
    }

    $fixtureAppRoot = getenv('FIREFLY_TEST_APP_ROOT') ?: dirname(__DIR__, 2);
    require $fixtureAppRoot . '/vendor/autoload.php';
    require_once dirname(__DIR__, 2) . '/app/Support/Reports/RefundProjection.php';
    require dirname(__DIR__, 2) . '/app/Support/Reports/ReportingSubscriptions.php';
    require dirname(__DIR__, 2) . '/app/Support/Reports/CurrencyBridge.php';
    require dirname(__DIR__, 2) . '/app/Support/Reports/NetWorthSeries.php';
    require dirname(__DIR__, 2) . '/app/Support/Reports/DashboardCards.php';

    use Carbon\Carbon;
    use FireflyIII\Repositories\Budget\BudgetLimitRepositoryInterface;
    use FireflyIII\Repositories\Budget\BudgetRepositoryInterface;
    use FireflyIII\Support\Reports\DashboardCards;
    use FireflyIII\Support\Reports\DashboardLedger;
    use Illuminate\Container\Container;
    use Illuminate\Support\Collection;

    $checks = 0;
    function check(bool $condition, string $message): void
    {
        global $checks;
        if (!$condition) {
            throw new RuntimeException($message);
        }
        ++$checks;
    }

    function leg(
        string $id,
        string $account,
        string $currency,
        string $amount,
        string $type = 'Withdrawal',
        string $day = '2026-11-03',
        array $budgets = [],
        null|string $journal = null
    ): array {
        return [
            'id'             => $id,
            'journal_id'     => $journal ?? $id,
            'account_id'     => $account,
            'currency'       => $currency,
            'amount'         => $amount,
            'type'           => $type,
            'day'            => $day,
            'budget_ids'     => $budgets,
            'pc_amount'      => '888888',
            'native_amount'  => '777777',
            'foreign_amount' => '666666'
        ];
    }

    function account(string $id, string $currency, bool $included = true, null|string $known = '2026-09-01'): array
    {
        return ['id' => $id, 'currency' => $currency, 'net_worth' => $included, 'known_from' => $known, 'virtual_balance' => '999999'];
    }

    function limit(string $id, string $amount, string $currency = 'UAH', string $first = '2026-11-01', string $last = '2026-11-30'): object
    {
        return (object) [
            'id'                  => $id,
            'amount'              => $amount,
            'transactionCurrency' => (object) ['code' => $currency],
            'start_date'          => Carbon::parse($first),
            'end_date'            => Carbon::parse($last),
            'native_amount'       => '999999'
        ];
    }

    final class Bill
    {
        public object $transactionCurrency;

        public function __construct(
            public bool $active,
            public string $amount_min,
            public string $amount_max,
            string $code,
            public int $expected,
            private array $ids = []
        ) {
            $this->transactionCurrency = (object) ['code' => $code];
        }

        public function transactionJournals(): object
        {
            return new class($this->ids) {
                public function __construct(
                    private array $ids
                ) {}

                public function after($day): self
                {
                    return $this;
                }

                public function before($day): self
                {
                    return $this;
                }

                public function get(): array
                {
                    return array_map(static fn($id): object => (object) ['id' => $id], $this->ids);
                }

                public function whereHas($relation): self
                {
                    if ('transactionGroup' !== $relation) {
                        throw new RuntimeException('Deleted groups must be excluded.');
                    }
                    return $this;
                }
            };
        }
    }

    $user      = (object) ['id' => 'owner'];
    $container = new Container();
    Container::setInstance($container);
    $budgets = new class {
        public array       $rows = [];
        public null|object $user = null;

        public function getActiveBudgets(): Collection
        {
            return new Collection($this->rows);
        }

        public function setUser($user): void
        {
            $this->user = $user;
        }
    };
    $limits = new class {
        public array       $rows  = [];
        public array       $calls = [];
        public null|object $user  = null;

        public function getBudgetLimits($budget, $start, $end): Collection
        {
            $this->calls[] = [$budget->id, $start->format('Y-m-d'), $end->format('Y-m-d')];
            return new Collection($this->rows[$budget->id] ?? []);
        }

        public function setUser($user): void
        {
            $this->user = $user;
        }
    };
    $bills = new class {
        public array $rows = [];

        public function getBills(): Collection
        {
            return new Collection($this->rows);
        }

        public function getPayDatesInRange($bill, $start, $end): Collection
        {
            return new Collection(array_fill(0, $bill->expected, 'date'));
        }
    };
    $container->instance(BudgetRepositoryInterface::class, $budgets);
    $container->instance(BudgetLimitRepositoryInterface::class, $limits);
    $start = Carbon::parse('2026-11-01', 'Europe/Kyiv');
    $end   = Carbon::parse('2026-11-30', 'Europe/Kyiv');
    check(DashboardCards::rows($bills, $start, $end) === [], 'No source currencies means no invented primary-currency rows.');

    DashboardLedger::$fixture = [
        'accounts' => [
            'usd'      => account('usd', 'USD'),
            'uah'      => account('uah', 'UAH'),
            'eur'      => account('eur', 'EUR'),
            'chf'      => account('chf', 'CHF'),
            'usdt'     => account('usdt', 'USDT'),
            'debt'     => account('debt', 'EUR'),
            'inactive' => account('inactive', 'USD'),
            'excluded' => account('excluded', 'UAH', false),
            'future'   => account('future', 'CHF', true, '2026-12-01')
        ],
        'legs'     => [
            leg('usd-opening', 'usd', 'USD', '1000', 'Opening balance', '2026-09-01'),
            leg('usd-income', 'usd', 'USD', '100', 'Deposit'),
            leg('usd-expense', 'usd', 'USD', '-20', 'Withdrawal', '2026-11-03', ['10']),
            leg('inactive-income', 'inactive', 'USD', '3', 'Deposit'),
            leg('uah-opening', 'uah', 'UAH', '10000', 'Opening balance', '2026-09-01'),
            leg('uah-expense', 'uah', 'UAH', '-100', 'Withdrawal', '2026-11-03', ['10', '20']),
            leg('quarter-history', 'uah', 'UAH', '-30', 'Withdrawal', '2026-09-10', ['10']),
            leg('before-limit', 'uah', 'UAH', '-900', 'Withdrawal', '2026-08-31', ['10']),
            leg('unbudgeted', 'uah', 'UAH', '-7'),
            leg('eur-income', 'eur', 'EUR', '50', 'Deposit'),
            leg('eur-expense', 'eur', 'EUR', '-10'),
            leg('debt-opening', 'debt', 'EUR', '-200', 'Opening balance', '2026-09-01'),
            leg('chf-income', 'chf', 'CHF', '7.5', 'Deposit'),
            leg('chf-expense', 'chf', 'CHF', '-2.25'),
            leg('usdt-income', 'usdt', 'USDT', '5.000001', 'Deposit'),
            leg('usdt-expense', 'usdt', 'USDT', '-1.000001'),
            leg('transfer-out', 'usd', 'USD', '-15', 'Transfer', '2026-11-03', [], 'transfer'),
            leg('transfer-in', 'eur', 'EUR', '14', 'Transfer', '2026-11-03', [], 'transfer'),
            leg('owned-out', 'usd', 'USD', '-900', 'Withdrawal', '2026-11-03', ['10'], 'owned-withdrawal'),
            leg('owned-in', 'uah', 'UAH', '500', 'Withdrawal', '2026-11-03', ['10'], 'owned-withdrawal'),
            leg('reconciliation', 'uah', 'UAH', '100', 'Reconciliation'),
            leg('excluded-opening', 'excluded', 'UAH', '9999', 'Opening balance'),
            leg('foreign-owner', 'another-owner', 'UAH', '9999', 'Deposit'),
            leg('future-income', 'usd', 'USD', '1000', 'Deposit', '2026-12-01'),
            leg('future-account', 'future', 'CHF', '1000', 'Opening balance', '2026-12-01')
        ]
    ];
    $quarter       = limit('quarter', '1000', 'UAH', '2026-09-01');
    $budgets->rows = [(object) ['id' => '10'], (object) ['id' => '20']];
    $limits->rows  = ['10' => [$quarter, $quarter], '20' => [limit('monthly', '200')]];
    $bills->rows   = [
        new Bill(true, '120', '120', 'USD', 1, ['usd-expense', 'usd-expense']),
        new Bill(true, '10', '20', 'EUR', 2, ['uah-expense']),
        new Bill(false, '10', '10', 'CHF', 0, ['chf-expense']),
        new Bill(true, '0.000003', '0.000005', 'USDT', 3),
        new Bill(false, '0', '0', 'USD', 0, ['owned-withdrawal', 'foreign-owner', 'future-income'])
    ];
    $before = serialize([DashboardLedger::$fixture, $bills->rows, $budgets->rows, $limits->rows]);
    $calls  = DashboardLedger::$calls;
    $rows   = DashboardCards::rows($bills, $start, $end);
    check(DashboardLedger::$calls === ($calls + 1), 'The four cards share one owner-scoped raw ledger.');
    check($rows['balance-in-USD']['monetary_value'] === '83.00', 'USD income minus USD withdrawals stays USD, including inactive-account history.');
    check(
        $rows['spent-in-USD']['monetary_value'] === '-20.00' && $rows['earned-in-USD']['monetary_value'] === '103.00',
        'Internal owned effects, transfers and future entries are excluded from cash flow.'
    );
    check($rows['balance-in-EUR']['monetary_value'] === '40.00', 'EUR original income and spending are separate from USD.');
    check($rows['balance-in-UAH']['monetary_value'] === '-107.00', 'UAH flow excludes opening balances, reconciliation and other owners.');
    check($rows['balance-in-CHF']['monetary_value'] === '5.25', 'CHF uses its original raw amounts without a quote.');
    check($rows['balance-in-USDT']['monetary_value'] === '4.000000', 'USDT arithmetic keeps source precision.');
    check($rows['balance-in-USD']['sub_title'] === '-20.00 USD + 103.00 USD', 'Balance subtitle formats original-currency spending and income.');
    check($rows['bills-paid-in-USD']['monetary_value'] === '20.00', 'Paid bills read the actual owned original debit once.');
    check($rows['bills-unpaid-in-USD']['monetary_value'] === '0.00', 'A repeated matching journal covers only one expected bill occurrence.');
    check($rows['bills-paid-in-UAH']['monetary_value'] === '100.00', 'A foreign-price bill paid from UAH has a UAH actual payment.');
    check($rows['bills-unpaid-in-EUR']['monetary_value'] === '-15.00', 'Remaining bill occurrences retain the original EUR forecast price.');
    check($rows['bills-paid-in-CHF']['monetary_value'] === '2.25', 'Inactive bill history remains in actual payments.');
    check($rows['bills-unpaid-in-USDT']['monetary_value'] === '-0.000012', 'Forecast averages keep the original currency precision.');
    check(
        $rows['left-to-spend-in-UAH']['monetary_value'] === '1070.00',
        'Full quarterly coverage includes September; overlapping limits, budgets and repeated limits do not duplicate spending.'
    );
    check($rows['left-per-day-to-spend-in-UAH']['monetary_value'] === '44.58', 'The native JavaScript per-day key is emitted separately.');
    check($rows['left-to-spend-in-UAH']['sub_title'] === '44.58 UAH', 'Left-to-spend subtitle agrees with the separate per-day amount.');
    check(
        !isset($rows['left-to-spend-in-USD']) && !isset($rows['left-to-spend-in-EUR']),
        'Foreign spending is never converted into the UAH cap or shown as a fabricated foreign cap.'
    );
    check($budgets->user === $user && $limits->user === $user, 'Budget reads are explicitly scoped to the current owner.');
    check(
        $limits->calls[count($limits->calls) - 1] === ['20', '2026-11-01', '2026-11-30'],
        'Limits use the requested interval without extending or mutating it.'
    );
    check(
        $rows['net-worth-in-EUR']['monetary_value'] === '205.52',
        'All signed net-worth balances use the NBU UAH bridge and one final EUR rounding, including liabilities and the USD-proxy token valuation.'
    );
    check(
        array_keys(array_filter($rows, static fn($row) => str_starts_with($row['key'], 'net-worth-in-'))) === ['net-worth-in-EUR'],
        'Net worth emits one combined EUR value, while the other cards retain their source currencies.'
    );
    $series = \FireflyIII\Support\Reports\NetWorthSeries::build(
        DashboardLedger::$fixture['accounts'],
        DashboardLedger::$fixture['legs'],
        \FireflyIII\Support\Reports\ReportingCurrency::$uahQuotes,
        $end->format('Y-m-d'),
        $end->format('Y-m-d')
    );
    check(
        $series['points'][0]['total'] === $rows['net-worth-in-EUR']['monetary_value'],
        'The card and chart agree for matching dates and the same NBU snapshot.'
    );
    check(
        $before === serialize([DashboardLedger::$fixture, $bills->rows, $budgets->rows, $limits->rows]),
        'Original ledger, bill prices and editable limits remain unchanged.'
    );
    check($start->format('Y-m-d') === '2026-11-01' && $end->format('Y-m-d') === '2026-11-30', 'Caller dates remain unchanged.');
    foreach ($rows as $key => $row) {
        check(
            $key === $row['key'] && !str_starts_with($key, 'pc-') && !array_key_exists('pc_amount', $row),
            'API rows use their native keys without primary aliases.'
        );
        check(
            isset(
                $row['title'],
                $row['currency_id'],
                $row['currency_code'],
                $row['currency_symbol'],
                $row['currency_decimal_places'],
                $row['value_parsed'],
                $row['sub_title']
            ),
            'Each API row has native metadata and formatted values.'
        );
    }
    $filtered = DashboardCards::rows($bills, $start, $end, 'CHF');
    check(array_unique(array_column($filtered, 'currency_code')) === ['CHF'], 'The explicit currency-code filter keeps only that original currency.');
    check(DashboardCards::rows($bills, $start, $end, 'JPY') === [], 'An absent requested currency does not cause conversion or a primary fallback.');
    $limits->rows['10'][] = limit('usd-cap', '100', 'USD');
    $parallel             = DashboardCards::rows($bills, $start, $end);
    check(
        $parallel['left-to-spend-in-USD']['monetary_value'] === '80.00' && $parallel['left-to-spend-in-UAH']['monetary_value'] === '1070.00',
        'Parallel currency caps charge only same-currency withdrawals.'
    );
    $limits->rows = ['10' => [limit('overspent', '10', 'USD')]];
    $overspent    = DashboardCards::rows($bills, $start, $end);
    check(
        $overspent['left-to-spend-in-USD']['monetary_value'] === '-10.00' && $overspent['left-per-day-to-spend-in-USD']['monetary_value'] === '0.00',
        'An overspent cap keeps a negative remainder and zero daily allowance.'
    );
    $quotes = \FireflyIII\Support\Reports\ReportingCurrency::$uahQuotes;
    unset(\FireflyIII\Support\Reports\ReportingCurrency::$uahQuotes['CHF']);
    try {
        DashboardCards::rows($bills, $start, $end);
        throw new RuntimeException('Missing NBU rate was accepted.');
    } catch (RuntimeException $exception) {
        check(str_contains($exception->getMessage(), 'CHF'), 'A missing rate must not produce a partial net-worth total.');
    }
    \FireflyIII\Support\Reports\ReportingCurrency::$uahQuotes = $quotes;
    DashboardLedger::$fixture                                 = [
        'accounts' => ['a' => account('a', 'UAH'), 'b' => account('b', 'UAH')],
        'legs'     => [
            leg('first-cent', 'a', 'UAH', '0.25', 'Opening balance'),
            leg('second-cent', 'b', 'UAH', '0.25', 'Opening balance')
        ]
    ];
    check(
        DashboardCards::rows($bills, $start, $end)['net-worth-in-EUR']['monetary_value'] === '0.01',
        'Sub-cent account values are combined before rounding the portfolio.'
    );
    $bills->rows                     = [new Bill(false, '125', '125', 'UAH', 0, ['refund-charge'])];
    $budgets->rows                   = [(object) ['id' => '10']];
    $limits->rows                    = ['10' => [limit('refund-limit', '1000')]];
    $refundLeg                       = leg('refund-credit', 'uah', 'UAH', '125', 'Deposit');
    $refundLeg['is_refund']          = true;
    $refundLeg['refund_original_id'] = 'refund-charge';
    $refundLeg['refund_budget_ids']  = ['10'];
    DashboardLedger::$fixture        = [
        'accounts' => ['uah' => account('uah', 'UAH')],
        'legs'     => [
            leg('refund-opening', 'uah', 'UAH', '1000', 'Opening balance', '2026-09-01'),
            leg('refund-charge', 'uah', 'UAH', '-125', 'Withdrawal', '2026-11-03', ['10']),
            $refundLeg
        ]
    ];
    $refundBefore = serialize(DashboardLedger::$fixture);
    $refundedRows = DashboardCards::rows($bills, $start, $end);
    check(
        $refundedRows['spent-in-UAH']['monetary_value'] === '0.00' && $refundedRows['earned-in-UAH']['monetary_value'] === '0.00',
        'The homepage treats a refund as expense credit, never new income.'
    );
    check($refundedRows['left-to-spend-in-UAH']['monetary_value'] === '1000.00', 'Refund reverses its original budget expense once.');
    check($refundedRows['bills-paid-in-UAH']['monetary_value'] === '0.00', 'Refund reverses actual paid subscription spending.');
    check($refundedRows['net-worth-in-EUR']['monetary_value'] === '20.00', 'Capital retains both unchanged bank movements.');
    check(serialize(DashboardLedger::$fixture) === $refundBefore, 'Refund reporting does not rewrite ledger amounts.');
    DashboardLedger::$fixture['legs'][1]['budget_ids']        = [];
    DashboardLedger::$fixture['legs'][2]['refund_budget_ids'] = [];
    check(
        DashboardCards::rows($bills, $start, $end)['left-to-spend-in-UAH']['monetary_value'] === '1000.00',
        'A previously cleared full-refund budget does not receive a second credit.'
    );
    echo "Passed {$checks} dashboard card checks.\n";
}
