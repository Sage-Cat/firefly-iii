<?php

declare(strict_types=1);

namespace Carbon {
    class Carbon extends \DateTime
    {
        public function addDay(): static
        {
            $this->modify('+1 day');
            return $this;
        }

        public function copy(): static
        {
            return clone $this;
        }

        public function endOfDay(): static
        {
            $this->setTime(23, 59, 59);
            return $this;
        }

        public function startOfDay(): static
        {
            $this->setTime(0, 0);
            return $this;
        }

        public function subDay(): static
        {
            $this->modify('-1 day');
            return $this;
        }
    }
}

namespace FireflyIII\Enums {
    enum TransactionTypeEnum: string
    {
        case WITHDRAWAL = 'Withdrawal';
    }
}

namespace FireflyIII\Support\Facades {
    final class Steam
    {
        public static function anonymous(): bool
        {
            return $GLOBALS['anonymous'] ?? false;
        }
    }

    final class Navigation
    {
        public static function addPeriod($start, string $range): object
        {
            return $start->copy()->addDay();
        }

        public static function blockPeriods($start, $end, $range): array
        {
            return [['start' => $start->copy()->startOfDay(), 'end' => $end->copy()->endOfDay(), 'period' => $range]];
        }

        public static function endOfPeriod($start, string $range): object
        {
            return $start->copy()->endOfDay();
        }

        public static function getViewRange(bool $value): string
        {
            return '1M';
        }

        public static function periodShow($start, string $range): string
        {
            return $start->format('1D' === $range ? 'Y-m-d' : 'Y-m');
        }

        public static function preferredRangeFormat($start, $end): string
        {
            return '1D';
        }
    }
}

namespace FireflyIII\Support\Reports {
    final class ReportingCurrency
    {
        public static array $calls = [];

        public static function convert(string $amount, string $source, string $target): string
        {
            self::$calls[] = [$amount, $source, $target];
            $quotes        = ['EUR' => '50', 'USD' => '40', 'CHF' => '55', 'UAH' => '1'];
            if (!isset($quotes[$source], $quotes[$target])) {
                throw new \RuntimeException('Missing quote');
            }
            $negative = bccomp($amount, '0', 12) < 0;
            $value    = bcdiv(bcmul(ltrim($amount, '-'), $quotes[$source], 16), $quotes[$target], 16);
            $value    = bcadd($value, '0.005', 2);

            return $negative ? bcsub('0', $value, 2) : $value;
        }

        public static function currency(string $code): object
        {
            $values = ['EUR' => [1, '€'], 'USD' => [2, '$'], 'CHF' => [3, 'CHF'], 'UAH' => [4, '₴']];
            if (!isset($values[$code])) {
                throw new \RuntimeException('Unsupported currency');
            }

            return (object) ['id' => $values[$code][0], 'name' => $code, 'code' => $code, 'symbol' => $values[$code][1], 'decimal_places' => 2];
        }

        public static function today(): \Carbon\Carbon
        {
            return new \Carbon\Carbon('2026-11-07', new \DateTimeZone('Europe/Kyiv'));
        }
    }
}

namespace FireflyIII\Models {
    class Account
    {
        public function __construct(
            public string $id
        ) {}
    }

    class Category
    {
        public function __construct(
            public string $id
        ) {}
    }

    class Tag
    {
        public function __construct(
            public string $id
        ) {}
    }

    final class TransactionCurrency
    {
        public static function query(): object
        {
            return new class {
                public function find($id): null|object
                {
                    $code = [1 => 'EUR', 2 => 'USD', 3 => 'CHF', 4 => 'UAH'][$id] ?? null;

                    return null === $code ? null : \FireflyIII\Support\Reports\ReportingCurrency::currency($code);
                }
            };
        }
    }
}

namespace Illuminate\Support {
    class Collection
    {
        public function __construct(
            private array $items = []
        ) {}

        public function isEmpty(): bool
        {
            return [] === $this->items;
        }

        public function pluck(string $key): object
        {
            return new class(array_map(static fn($item) => $item->$key, $this->items)) {
                public function __construct(
                    private array $values
                ) {}

                public function all(): array
                {
                    return $this->values;
                }
            };
        }

        public function push(object $item): static
        {
            $this->items[] = $item;
            return $this;
        }
    }
}

namespace {
    use FireflyIII\Support\Reports\ExpenseProjection;
    use FireflyIII\Support\Reports\ReportingCurrency;

    $requestPath     = '';
    $requestType     = '';
    $primary         = 'EUR';
    $assetIds        = [];
    $collectorRows   = [];
    $modelPeriodRows = [];
    $collectorCalls  = 0;
    $checks          = 0;
    function request(): object
    {
        return new class {
            public function path(): string
            {
                return $GLOBALS['requestPath'];
            }

            public function route(string $name, string $default = ''): string
            {
                return $GLOBALS['requestType'] ?: $default;
            }
        };
    }

    function trans(string $key): string
    {
        return 'firefly.no_category' === $key ? 'Без категорії' : 'Витрати';
    }

    function format_amount_by_symbol(string $amount, string $symbol, int $decimals, bool $coloured): string
    {
        return $amount . ' ' . $symbol;
    }

    function auth(): object
    {
        return new class {
            public function user(): object
            {
                return (object) ['id' => 'owner'];
            }
        };
    }

    function config(string $key): array
    {
        return str_contains($key, 'transactionTypesByType') ? ['Withdrawal'] : ['Asset account', 'Default account'];
    }

    function route(string $name, array $parameters): string
    {
        return $name . '?' . http_build_query($parameters);
    }

    function app(string $name): object
    {
        if (str_contains($name, 'AccountRepositoryInterface') || str_contains($name, 'CategoryRepositoryInterface')) {
            return new class {
                public function getAccountsByType(array $types): object
                {
                    return new class {
                        public function filter($callback): static
                        {
                            return $this;
                        }

                        public function isEmpty(): bool
                        {
                            return [] === $GLOBALS['assetIds'];
                        }

                        public function pluck(string $key): object
                        {
                            return new class {
                                public function all(): array
                                {
                                    return $GLOBALS['assetIds'];
                                }
                            };
                        }
                    };
                }

                public function periodCollection(object $model, $start, $end): array
                {
                    return $GLOBALS['modelPeriodRows'];
                }

                public function setUser(object $user): void {}
            };
        }
        if (str_contains($name, 'GroupCollectorInterface')) {
            ++$GLOBALS['collectorCalls'];
            return new class {
                private array $types = [];

                public function getExtractedJournals(): array
                {
                    return array_values(array_filter($GLOBALS['collectorRows'], fn($row) => in_array($row['transaction_type_type'], $this->types, true)));
                }

                public function __call(string $method, array $args): static
                {
                    if ('setTypes' === $method) {
                        $this->types = $args[0];
                    }
                    return $this;
                }
            };
        }
        throw new \RuntimeException('The empty-asset guard allowed another dependency: ' . $name);
    }

    require_once dirname(__DIR__, 2) . '/app/Support/Reports/RefundProjection.php';
    require getenv('EXPENSE_HELPER') ?: dirname(__DIR__, 2) . '/app/Support/Reports/ExpenseProjection.php';

    function check(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new \RuntimeException($message);
        }
        ++$GLOBALS['checks'];
    }

    function rejects(callable $call): void
    {
        try {
            $call();
        } catch (\RuntimeException $exception) {
            check(true, $exception->getMessage());
            return;
        }
        throw new \RuntimeException('Expected an explicit rejection.');
    }

    function row(
        string $id,
        string $amount,
        string $code = 'EUR',
        string $type = 'Withdrawal',
        string $day = '2026-11-01',
        null|string $category = 'Їжа'
    ): array {
        return [
            'transaction_journal_id' => $id,
            'amount'                 => $amount,
            'currency_code'          => $code,
            'transaction_type_type'  => $type,
            'date'                   => $day,
            'category_name'          => $category,
            'pc_amount'              => '-99999',
            'foreign_amount'         => '-88888'
        ];
    }

    $rows   = [row('one', '-2'), row('two', '-50', 'UAH')];
    $before = serialize($rows);
    $amount = ExpenseProjection::amount($rows[0]);
    check($amount['amount'] === '-100.00' && $amount['currency_code'] === 'UAH', 'Withdrawal defaults to its current UAH equivalent.');
    check($amount['original_amount'] === '-2' && $amount['original_currency_code'] === 'EUR', 'Original amount remains available.');
    check(ExpenseProjection::amount($rows[0], 'UAH', true)['amount'] === '100.00', 'Chart resolver returns the positive magnitude.');
    check(
        ExpenseProjection::amount(['amount' => '-2', 'currency_id' => 1])['amount'] === '-100.00',
        'Native amount components can identify source currency by id.'
    );
    rejects(fn() => ExpenseProjection::amount(['amount' => '-2', 'currency_id' => 999]));
    check(ExpenseProjection::groupAmount(['transactions' => $rows])['amount'] === '-150.00', 'Split group total converts each base amount once.');
    $detail = ExpenseProjection::detailAmounts(['transactions' => $rows]);
    check(
        count($detail) === 1 && $detail[0]['amount'] === '-150.00' && $detail[0]['symbol'] === '₴',
        'Detail total has one UAH entry, not source plus foreign plus primary.'
    );
    check(serialize($rows) === $before, 'Projection does not rewrite the original arrays.');

    $requestPath = 'chart/transactions/categories/withdrawal/2026-11-01/2026-11-07';
    check(ExpenseProjection::resolve($rows[0], null, 'EUR')['currency_code'] === 'UAH', 'Expense URI selects UAH.');
    $requestPath = 'chart/account/expense-category/1';
    check(ExpenseProjection::expenseRequest(), 'Hyphenated expense endpoints are detected.');
    $requestPath = 'chart/report/operations/all/20261101/20261107';
    check(
        ExpenseProjection::resolve($rows[1], null, 'EUR')['amount'] === '1.00',
        'Generic comparisons retain their EUR target and use raw UAH, not stored pc.'
    );
    $requestType = 'withdrawal';
    check(ExpenseProjection::expenseRequest(), 'Object type can select an expense view without an expense URI.');
    $requestType = '';

    $periodRows = array_merge($rows, [row('income', '-100', 'EUR', 'Deposit'), row('old', '-100', 'USD', 'Withdrawal', '2026-10-31')]);
    $period     = ExpenseProjection::periodTotalsFrom($periodRows, '2026-11-01', '2026-11-07');
    check($period['count'] === 2 && $period[4]['amount'] === '-150.00', 'Expense period totals exclude other types and dates.');
    $modelRow = ['type' => 'Withdrawal', 'amount' => '2', 'currency_code' => 'EUR', 'date' => '2026-11-01'];
    check(
        ExpenseProjection::periodTotalsFrom([$modelRow], '2026-11-01', '2026-11-07')[4]['amount'] === '-100.00',
        'Positive model legs normalize to a negative expense.'
    );
    $edge = row('edge', '-1', 'EUR', 'Withdrawal', '2026-10-31T23:30:00Z');
    check(ExpenseProjection::periodTotalsFrom([$edge], '2026-11-01', '2026-11-01')['count'] === 1, 'UTC day boundary is evaluated in Kyiv.');
    check(ExpenseProjection::periodTotalsFrom([], '2026-11-01', '2026-11-07') === ['count' => 0], 'An empty period has no invented monetary row.');
    $transfers = [row('in', '100', 'UAH', 'Transfer'), row('away', '-150', 'UAH', 'Transfer')];
    check(
        ExpenseProjection::periodGroupFrom($transfers, '2026-11-01', '2026-11-07', 'EUR', ['Transfer'], 'in')[1]['amount'] === '2.00',
        'Incoming model transfer direction remains distinct.'
    );
    check(
        ExpenseProjection::periodGroupFrom($transfers, '2026-11-01', '2026-11-07', 'EUR', ['Transfer'], 'away')[1]['amount'] === '-3.00',
        'Outgoing model transfer direction remains distinct.'
    );
    check(
        ExpenseProjection::periodGroupFrom([row('income', '-100', 'UAH', 'Deposit')], '2026-11-01', '2026-11-07', 'EUR', ['Deposit'])[1]['amount'] === '-2.00',
        'Income periods remain EUR.'
    );

    $category = ExpenseProjection::categoryChartFrom([
        row('food', '-1'),
        row('local', '-25', 'UAH'),
        row('none', '-2', 'USD', 'Withdrawal', '2026-11-01', null),
        row('deposit', '-100', 'EUR', 'Deposit')
    ]);
    check(count($category) === 1 && $category[0]['currency_code'] === 'UAH', 'Category chart has one UAH series.');
    check(
        $category[0]['entries']['Їжа'] === '75.00' && $category[0]['entries']['Інше'] === '80.00',
        'Categorized and uncategorized expenses use the same conversion.'
    );
    $accounts = ExpenseProjection::accountChartFromBalances(
        ['one' => 'Shop', 'two' => 'Refund'],
        ['one' => ['EUR' => '10', 'UAH' => '100', 'pc_balance' => '10000']],
        ['one' => ['EUR' => '15', 'UAH' => '150', 'balance' => '9999', 'pc_balance' => '50000'], 'two' => ['EUR' => '-1']]
    );
    check($accounts[0]['entries']['Shop'] === '300.00', 'Account charts convert native differences and ignore duplicate generic/pc balance fields.');
    check($accounts[0]['entries']['Refund'] === '-50.00', 'Refund differences are not converted to a new expense.');
    $current         = ['12' => ['EUR' => '10', 'USD' => '20', 'UAH' => '30', 'balance' => '100000', 'pc_balance' => '500000'], '13' => []];
    $start           = ['12' => ['EUR' => '2', 'USD' => '1', 'UAH' => '10']];
    $end             = ['12' => ['EUR' => '3', 'USD' => '0', 'UAH' => '20']];
    $originalPayload = serialize([$current, $start, $end]);
    $display         = ExpenseProjection::merchantDisplayFromBalances($current, $start, $end);
    check(
        $display['12']['current_balance'] === '1330.00' && $display['12']['currency_code'] === 'UAH',
        'Merchant current balance includes all three raw currencies, without generic or stored primary duplicates.'
    );
    check(
        $display['12']['balance_difference'] === '20.00' && $display['12']['balance_difference_sign'] === 1,
        'Merchant period difference converts the signed native changes in every currency.'
    );
    check(
        $display['12']['current_balance_formatted'] === '1330.00 ₴' && $display['12']['as_of'] === '2026-11-07',
        'Merchant fields carry UAH formatting and the current report date together.'
    );
    check($display['13']['current_balance'] === '0.00' && $display['13']['current_balance_sign'] === 0, 'An empty native merchant balance is zero UAH.');
    check(serialize([$current, $start, $end]) === $originalPayload, 'Merchant display retains the original balance payload.');
    check(
        ExpenseProjection::nativeBalanceUAH([], ['USD' => '2']) === '-80.00',
        'A balance currency disappearing after a refund still contributes to the signed difference.'
    );
    check(ExpenseProjection::merchantDisplayFromBalances([], [], []) === [], 'No merchant IDs creates no invented display row.');
    $anonymous = true;
    $masked    = ExpenseProjection::merchantDisplayFromBalances($current, $start, $end);
    check(
        $masked['12']['current_balance'] === '0.00' && $masked['12']['balance_difference'] === '0.00',
        'Anonymous mode keeps actual merchant amounts out of the display store.'
    );
    $anonymous = false;
    check(
        ExpenseProjection::merchantDisplay(new \Carbon\Carbon('2026-11-01'), new \Carbon\Carbon('2026-11-07')) === [],
        'No owned merchant accounts never invokes raw balance aggregation.'
    );
    check(
        ExpenseProjection::withdrawals(new \Carbon\Carbon('2026-11-01'), new \Carbon\Carbon('2026-11-07')) === [],
        'Empty owned assets never cause an unfiltered collector query.'
    );
    check($collectorCalls === 0, 'No collector is resolved when there are no owned assets.');
    $assetIds      = ['own'];
    $collectorRows = [
        row('owned', '-2') + ['source_account_id' => 'own', 'destination_account_id' => 'merchant'],
        row('outsider', '-100') + ['source_account_id' => 'other-owner', 'destination_account_id' => 'merchant'],
        row('internal', '-100') + ['source_account_id' => 'own', 'destination_account_id' => 'own']
    ];
    check(
        count(ExpenseProjection::withdrawals(new \Carbon\Carbon('2026-11-01'), new \Carbon\Carbon('2026-11-07'))) === 1,
        'Expense collection rejects another owner and internal movements.'
    );
    $modelPeriodRows = [
        $modelRow,
        ['type' => 'Deposit', 'amount' => '100', 'currency_code' => 'UAH', 'date' => '2026-11-01'],
        ['type' => 'Transfer', 'amount' => '100', 'currency_code' => 'UAH', 'date' => '2026-11-01']
    ];
    $modelPeriods = ExpenseProjection::modelPeriods(new \FireflyIII\Models\Account('own'), new \Carbon\Carbon('2026-11-01'), new \Carbon\Carbon('2026-11-07'));
    check(
        $modelPeriods[0]['spent'][4]['amount'] === '-100.00' && $modelPeriods[0]['earned'][1]['amount'] === '2.00',
        'Fresh model periods separate UAH expenses and EUR income.'
    );
    check(
        $modelPeriods[0]['transferred_in'][1]['amount'] === '2.00' && $modelPeriods[0]['total_transactions'] === 3,
        'Fresh model periods preserve transfer directions and counts.'
    );
    check(str_starts_with($modelPeriods[0]['route'], 'accounts.show'), 'Native period navigation stays available.');
    $generic = ExpenseProjection::genericPeriods('withdrawal', new \Carbon\Carbon('2026-11-01'), new \Carbon\Carbon('2026-11-07'));
    check(
        $generic[0]['spent'][4]['currency_code'] === 'UAH',
        'Generic period projection can build the native sidebar contract without a statistics repository.'
    );
    $collectorRows = [row('expense', '-2'), row('income', '-100', 'UAH', 'Deposit'), row('move', '-50', 'UAH', 'Transfer')];
    $noCategory    = ExpenseProjection::noModelPeriods('category', new \Carbon\Carbon('2026-11-01'), new \Carbon\Carbon('2026-11-07'));
    check(
        $noCategory[0]['spent'][4]['amount'] === '-100.00' && $noCategory[0]['earned'][1]['amount'] === '-2.00',
        'Uncategorized sidebar is fresh UAH/EUR without persisted statistics.'
    );
    check(
        $noCategory[0]['transferred'][1]['amount'] === '-1.00' && $noCategory[0]['total_transactions'] === 3,
        'Uncategorized transfer and overall counts stay present.'
    );
    $blocks    = [['start' => '2026-11-01', 'end' => '2026-11-01', 'label' => 'First'], ['start' => '2026-11-02', 'end' => '2026-11-02', 'label' => 'Second']];
    $chartRows = [
        row('expense-eur', '-2'),
        row('expense-usd', '-1', 'USD'),
        row('income-usd', '3', 'USD', 'Deposit'),
        row('day2', '-10', 'UAH', 'Withdrawal', '2026-11-02'),
        row('old', '-100', 'EUR', 'Withdrawal', '2026-10-31'),
        row('transfer', '-1000', 'EUR', 'Transfer')
    ];
    $history = ExpenseProjection::categoryHistoryFrom($chartRows, $blocks);
    check(
        count($history) === 2 && $history[0]['currency_code'] === 'UAH' && $history[1]['currency_symbol'] === '₴',
        'Category comparison uses one currency for both expense and income series.'
    );
    check(
        $history[0]['entries'] === ['First' => '140.00', 'Second' => '10.00'],
        'Category history combines native EUR and USD expense rows under a single UAH label.'
    );
    check($history[1]['entries'] === ['First' => '120.00', 'Second' => '0.00'], 'Category income comparison uses the same UAH units and keeps empty periods.');
    $modelPeriodRows = [row('repo-expense', '2'), row('repo-income', '100', 'UAH', 'Deposit')];
    $modelHistory    = ExpenseProjection::categoryHistory(
        new \FireflyIII\Models\Category('category'),
        new \Carbon\Carbon('2026-11-01'),
        new \Carbon\Carbon('2026-11-02'),
        '1D'
    );
    check(
        $modelHistory[0]['entries']['2026-11-01'] === '100.00' && $modelHistory[1]['entries']['2026-11-01'] === '100.00',
        'Native category model charts use raw positive repository legs, not stored primary totals.'
    );
    $beforeCalls = $collectorCalls;
    $emptyReport = ExpenseProjection::categoryReport(
        new \Illuminate\Support\Collection(),
        new \Carbon\Carbon('2026-11-01'),
        new \Carbon\Carbon('2026-11-02'),
        null
    );
    check(
        $collectorCalls === $beforeCalls && $emptyReport[0]['entries']['2026-11-01'] === '0.00',
        'An empty category-report account selection cannot broaden the collector scope.'
    );
    $collectorRows = $chartRows;
    $report        = ExpenseProjection::categoryReport(
        new \Illuminate\Support\Collection([new \FireflyIII\Models\Account('own')]),
        new \Carbon\Carbon('2026-11-01'),
        new \Carbon\Carbon('2026-11-02'),
        null
    );
    check(
        $report[0]['entries']['2026-11-01'] === '140.00' && $report[1]['entries']['2026-11-01'] === '120.00',
        'Selected-account category reports keep correctly labelled UAH amounts for both series.'
    );
    check($primary === 'EUR', 'The projection never changes the primary currency.');
    echo 'Passed ' . $checks . " expense currency checks.\n";
}
