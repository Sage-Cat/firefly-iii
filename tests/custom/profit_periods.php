<?php

declare(strict_types=1);

namespace FireflyIII\Http\Controllers {
    class Controller {}
}

namespace Illuminate\View {
    class View
    {
        public function __construct(
            public string $name,
            public array $data
        ) {}
    }
}

namespace FireflyIII\Support\Reports {
    class ReportingCurrency
    {
        public static int    $quoteCalls = 0;
        public static int    $todayCalls = 0;
        public static string $day        = '2026-11-07';

        public static function quotes(): array
        {
            ++self::$quoteCalls;

            return ['UAH' => '1', 'EUR' => '50', 'USD' => '45', 'CHF' => '52'];
        }

        public static function today(): \Carbon\Carbon
        {
            ++self::$todayCalls;

            return \Carbon\Carbon::parse(self::$day, 'Europe/Kyiv');
        }
    }
}

namespace {
    function app($interface)
    {
        return str_contains($interface, 'AccountRepository') ? $GLOBALS['repository'] : $GLOBALS['collector'];
    }

    function auth()
    {
        return new class {
            public function user()
            {
                return (object) ['id' => 'owner'];
            }
        };
    }

    function config($key)
    {
        return ['Asset account'];
    }

    function abort_if($condition, $status, $message)
    {
        if ($condition) {
            throw new \RuntimeException($message, $status);
        }
    }

    function view($name, $data)
    {
        return new \Illuminate\View\View($name, $data);
    }

    require dirname(__DIR__, 2) . '/vendor/autoload.php';
    require dirname(__DIR__, 2) . '/app/Support/Reports/ProfitSummary.php';
    require dirname(__DIR__, 2) . '/app/Support/Reports/CurrencyBridge.php';
    require dirname(__DIR__, 2) . '/app/Http/Controllers/ProfitReportController.php';

    use Carbon\Carbon;
    use FireflyIII\Http\Controllers\ProfitReportController;
    use FireflyIII\Support\Reports\ProfitSummary;
    use FireflyIII\Support\Reports\ReportingCurrency;

    $checks = 0;
    function check(bool $condition, string $message): void
    {
        global $checks;
        if (!$condition) {
            throw new \RuntimeException($message);
        }
        ++$checks;
    }

    function row(string $id, string $day, string $amount, string $type = 'Withdrawal', string $source = 'owned', string $destination = 'external'): array
    {
        return [
            'id'             => $id,
            'day'            => $day,
            'amount'         => $amount,
            'type'           => $type,
            'source_id'      => $source,
            'destination_id' => $destination,
            'currency'       => 'EUR'
        ];
    }

    function sumPoints(array $summary, string $key): string
    {
        return array_reduce($summary['points'], static fn($sum, $point) => bcadd($sum, $point[$key], 2), '0.00');
    }

    foreach ([
        ['2026-11-07', '2026-10-09'],
        ['2026-01-01', '2025-12-03'],
        ['2024-03-01', '2024-02-01'],
        ['2025-03-01', '2025-01-31'],
        ['2024-03-30', '2024-03-01']
    ] as [$today, $start]) {
        $period = ProfitSummary::period($today);
        check($period === ['start' => $start, 'end' => $today], 'The 30-day preset must cross month, leap and year boundaries without losing today.');
        $summary = ProfitSummary::build([], [], [], $period['start'], $period['end'], 'EUR');
        check(
            $summary['daily'] && count($summary['points']) === 30 && $summary['points'][0]['period'] === $start && $summary['points'][29]['period'] === $today,
            'The default chart must have exactly 30 inclusive daily points.'
        );
    }
    check(
        ProfitSummary::period('2026-01-07', 3) === ['start' => '2025-11-01', 'end' => '2026-01-07'],
        'The three-month preset must retain calendar months across years.'
    );
    check(
        ProfitSummary::period('2024-02-29', 12) === ['start' => '2023-03-01', 'end' => '2024-02-29'],
        'The annual preset must retain 12 calendar months through leap day.'
    );

    $rows = [
        row('before', '2025-12-30', '-999'),
        row('first', '2025-12-31', '-10'),
        row('sunday', '2026-01-04', '-20'),
        row('monday', '2026-01-05', '-30'),
        row('last', '2026-01-06', '-40'),
        row('after', '2026-01-07', '-999'),
        row('income', '2026-01-01', '-200', 'Deposit', 'revenue', 'owned'),
        row('transfer', '2026-01-02', '-900', 'Transfer'),
        row('internal', '2026-01-02', '-900', 'Deposit', 'owned', 'second'),
        row('outsider', '2026-01-02', '-900', 'Withdrawal', 'other-owner')
    ];
    $rates = ['UAH' => '1', 'EUR' => '50'];
    foreach (['EUR', 'UAH'] as $currency) {
        $daily   = ProfitSummary::build($rows, $rates, ['owned', 'second'], '2025-12-31', '2026-01-06', $currency);
        $monthly = ProfitSummary::build($rows, $rates, ['owned', 'second'], '2025-12-31', '2026-01-06', $currency, 'monthly');
        $weekly  = ProfitSummary::build($rows, $rates, ['owned', 'second'], '2025-12-31', '2026-01-06', $currency, 'weekly');
        check(!$monthly['daily'] && count($monthly['points']) === 2, 'Explicit monthly aggregation must override a daily chart period.');
        check(
            $monthly['points'][0]['period'] === '2025-12'
            && $monthly['points'][0]['start'] === '2025-12-31'
            && $monthly['points'][0]['end'] === '2025-12-31'
            && $monthly['points'][1]['start'] === '2026-01-01'
            && $monthly['points'][1]['end'] === '2026-01-06',
            'Monthly table dates must show only the selected portions of boundary months.'
        );
        check(
            count($weekly['points']) === 2
            && $weekly['points'][0]['period'] === '2026-W01'
            && $weekly['points'][0]['start'] === '2025-12-31'
            && $weekly['points'][0]['end'] === '2026-01-04'
            && $weekly['points'][1]['period'] === '2026-W02'
            && $weekly['points'][1]['start'] === '2026-01-05'
            && $weekly['points'][1]['end'] === '2026-01-06',
            'ISO weeks must group Monday through Sunday under the ISO year and clip both edges.'
        );
        check(
            $weekly['points'][0]['expenses'] === ('EUR' === $currency ? '30.00' : '1500.00')
            && $weekly['points'][1]['expenses'] === ('EUR' === $currency ? '70.00' : '3500.00'),
            'Sunday and Monday expenses must enter separate weeks using the same current quote.'
        );
        check(
            $daily['transactions'] === 5 && $monthly['transactions'] === 5 && $weekly['transactions'] === 5,
            'Every granularity must exclude transfers, other owners and out-of-range flows.'
        );
        foreach ([$monthly, $weekly] as $summary) {
            check($summary['totals'] === $daily['totals'], 'Table totals must equal chart totals for matching periods.');
            foreach (['income', 'expenses', 'net'] as $key) {
                check(sumPoints($summary, $key) === $summary['totals'][$key], 'Displayed buckets must sum exactly to each converted total.');
            }
        }
    }
    $leap = ProfitSummary::build([row('leap', '2024-02-29', '-7')], [], ['owned'], '2024-02-28', '2024-03-03', 'EUR', 'weekly');
    check(
        count($leap['points']) === 1
        && $leap['points'][0]['period'] === '2024-W09'
        && $leap['points'][0]['start'] === '2024-02-28'
        && $leap['points'][0]['end'] === '2024-03-03'
        && $leap['totals']['expenses'] === '7.00',
        'A partial leap-week must keep February 29 and Sunday.'
    );
    $sunday = ProfitSummary::build([], [], [], '2026-01-04', '2026-01-05', 'EUR', 'weekly');
    check(
        count($sunday['points']) === 2
        && $sunday['points'][0]['start'] === $sunday['points'][0]['end']
        && $sunday['points'][1]['start'] === $sunday['points'][1]['end'],
        'A Sunday-to-Monday range must have two one-day partial weeks.'
    );
    $empty = ProfitSummary::build([], [], [], '2026-01-01', '2026-01-31', 'EUR', 'weekly');
    check(count($empty['points']) === 5 && sumPoints($empty, 'expenses') === '0.00', 'Empty weeks must remain visible with zero expenses.');
    try {
        ProfitSummary::build([], [], [], '2026-01-01', '2026-01-31', 'EUR', 'daily');
        throw new \RuntimeException('Unsupported grouping was accepted.');
    } catch (\InvalidArgumentException $exception) {
        check(true, 'Unsupported grouping is rejected explicitly.');
    }

    class ProfitRequest extends \Illuminate\Http\Request
    {
        public array $rules = [];

        public function __construct(
            private array $input = []
        ) {}

        public function validate(array $rules): array
        {
            $this->rules = $rules;
            return $this->input;
        }
    }

    class ProfitAssets
    {
        public function __construct(
            private array $values = [['id' => 'owned'], ['id' => 'second']]
        ) {}

        public function all(): array
        {
            return $this->values;
        }

        public function isEmpty(): bool
        {
            return [] === $this->values;
        }

        public function map($callback): self
        {
            return new self(array_map($callback, $this->values));
        }

        public function pluck($key): self
        {
            return new self(array_column($this->values, $key));
        }
    }

    $repository = new class {
        public function getAccountsByType($types): ProfitAssets
        {
            return new ProfitAssets();
        }

        public function setUser($user) {}
    };
    $collector = new class {
        public array $types = [];

        public function getExtractedJournals(): array
        {
            $rows = [
                row('before-window', '2026-08-07', '-999'),
                row('window-first', '2026-08-08', '-7'),
                row('older', '2026-09-01', '-5'),
                row('chart', '2026-10-09', '-10'),
                row('today', '2026-11-07', '-20'),
                row('income', '2026-11-07', '-100', 'Deposit', 'revenue', 'owned'),
                row('internal', '2026-11-07', '-999', 'Withdrawal', 'owned', 'second')
            ];

            return array_map(static fn($row) => [
                'transaction_journal_id' => $row['id'],
                'date'                   => Carbon::parse($row['day'], 'Europe/Kyiv'),
                'transaction_type_type'  => $row['type'],
                'source_account_id'      => $row['source_id'],
                'destination_account_id' => $row['destination_id'],
                'currency_code'          => $row['currency'],
                'amount'                 => $row['amount']
            ], $rows);
        }

        public function setAccounts($accounts): self
        {
            return $this;
        }

        public function setTypes($types): self
        {
            $this->types = $types;
            return $this;
        }

        public function setUser($user): self
        {
            return $this;
        }

        public function withAccountInformation(): self
        {
            return $this;
        }
    };
    $request = new ProfitRequest();
    $data    = new ProfitReportController()->index($request)->data;
    check(
        $data['start']->format('Y-m-d') === '2026-10-09'
        && $data['end']->format('Y-m-d') === '2026-11-07'
        && count($data['summary']['points']) === 30
        && $data['summary']['daily'],
        'The controller default must feed the 30-day daily chart.'
    );
    check(
        $data['weeklyStart']->format('Y-m-d') === '2026-08-08'
        && $data['weeklyEnd']->format('Y-m-d') === '2026-11-07'
        && $data['weeklySummary']['totals']['expenses'] === '2100.00',
        'Weekly spending must include the rolling three-month window, including its first day and excluding the previous day.'
    );
    check(
        $data['weeklySummary']['points'][0]['start'] === '2026-08-08'
        && $data['weeklySummary']['points'][0]['end'] === '2026-08-09'
        && end($data['weeklySummary']['points'])['start'] === '2026-11-02'
        && end($data['weeklySummary']['points'])['end'] === '2026-11-07',
        'The rolling window must retain the actual dates of partial first and last ISO weeks.'
    );
    check(
        $data['monthlyStart']->format('Y-m-d') === '2026-09-01'
        && $data['monthlyEnd']->format('Y-m-d') === '2026-11-07'
        && count($data['monthlySummary']['points']) === 3
        && $data['monthlySummary']['totals']['expenses'] === '1750.00'
        && $data['summary']['totals']['expenses'] === '1500.00',
        'The monthly table must cover the last three calendar months independently, excluding internal transfers.'
    );
    check(
        ReportingCurrency::$quoteCalls === 1
        && ReportingCurrency::$todayCalls === 1
        && str_contains($request->rules['end'], 'before_or_equal:2026-11-07')
        && $data['valuationDay']->format('Y-m-d') === '2026-11-07',
        'One request must reuse one quote snapshot and one stable valuation day for validation and all periods.'
    );
    check($collector->types === ['Deposit', 'Withdrawal'], 'The controller must preserve the no-transfer collector types.');
    $custom = new ProfitReportController()->index(new ProfitRequest([
        'currency' => 'EUR',
        'months'   => '12',
        'start'    => '2026-11-01',
        'end'      => '2026-11-03'
    ]))->data;
    check(
        $custom['start']->format('Y-m-d') === '2026-11-01'
        && $custom['end']->format('Y-m-d') === '2026-11-03'
        && count($custom['summary']['points']) === 3
        && count($custom['monthlySummary']['points']) === 3
        && $custom['monthlySummary']['totals']['expenses'] === '35.00'
        && $custom['monthlyStart']->format('Y-m-d') === '2026-09-01',
        'Explicit dates affect the chart while the monthly table keeps the last three calendar months.'
    );
    check(
        $custom['weeklySummary']['points']
        === ProfitSummary::build(
            [
                row('window-first', '2026-08-08', '-7'),
                row('older', '2026-09-01', '-5'),
                row('chart', '2026-10-09', '-10'),
                row('today', '2026-11-07', '-20'),
                row('income', '2026-11-07', '-100', 'Deposit', 'revenue', 'owned')
            ],
            [],
            ['owned'],
            '2026-08-08',
            '2026-11-07',
            'EUR',
            'weekly'
        )['points'],
        'Custom chart dates must leave weekly dates unchanged while using the selected EUR currency.'
    );
    foreach ([
        ['2026-01-07', '2025-10-08'],
        ['2024-05-31', '2024-03-01'],
        ['2025-05-31', '2025-03-01'],
        ['2024-03-31', '2024-01-01'],
        ['2025-02-28', '2024-11-29']
    ] as [$today, $start]) {
        ReportingCurrency::$day = $today;
        $data                   = new ProfitReportController()->index(new ProfitRequest())->data;
        check(
            count($data['monthlySummary']['points']) === 3
            && $data['monthlyStart']->format('Y-m-d') === Carbon::parse($today)->startOfMonth()->subMonthsNoOverflow(2)->format('Y-m-d')
            && $data['monthlyEnd']->format('Y-m-d') === $today,
            'The monthly table keeps exactly three calendar months through leap and year boundaries.'
        );
        check(
            $data['weeklyStart']->format('Y-m-d') === $start
            && $data['weeklyEnd']->format('Y-m-d') === $today
            && $data['valuationDay']->format('Y-m-d') === $today,
            'The rolling weekly range must subtract three months without overflow, add one day, and preserve today across leap and year boundaries.'
        );
    }
    echo 'Passed ' . $checks . " profit period checks.\n";
}
