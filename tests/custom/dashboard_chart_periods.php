<?php

declare(strict_types=1);

namespace FireflyIII\Support\Reports {
    final class ReportingCurrency
    {
        public static string $day   = '2026-11-09';
        public static array  $calls = [];

        public static function convert(string $amount, string $source, string $target): string
        {
            self::$calls[] = [$amount, $source, $target];
            if ('UAH' !== $source || 'UAH' !== $target) {
                throw new \RuntimeException('Unexpected fixture conversion.');
            }
            return bcadd($amount, '0', 2);
        }

        public static function currency(string $code): object
        {
            return (object) ['id' => 4, 'code' => $code, 'name' => 'Українська гривня', 'symbol' => '₴', 'decimal_places' => 2];
        }

        public static function isDisplayRequest(): bool
        {
            return true;
        }

        public static function today(): \Carbon\Carbon
        {
            return \Carbon\Carbon::parse(self::$day, 'Europe/Kyiv');
        }
    }

    final class ExpenseProjection
    {
        public static function accountChart($start, $end, $accounts): array
        {
            return self::row('expense', $start, $end);
        }

        public static function categoryChart($start, $end): array
        {
            return self::row('category', $start, $end);
        }

        public static function revenueChart($start, $end): array
        {
            return self::row('revenue', $start, $end);
        }

        private static function row(string $kind, $start, $end): array
        {
            return ['kind' => $kind, 'start' => $start->format('Y-m-d H:i:s.u e'), 'end' => $end->format('Y-m-d H:i:s.u e')];
        }
    }
}

namespace {
    function request()
    {
        return $GLOBALS['periodRequest'];
    }

    function url($path)
    {
        return 'https://example.test/firefly';
    }

    function session($key, $fallback = null)
    {
        return $GLOBALS['periodSession'][$key] ?? $fallback;
    }

    function trans($key, $replace = [], $locale = null)
    {
        return $key;
    }

    function today($timezone = null)
    {
        return \FireflyIII\Support\Reports\ReportingCurrency::today()->setTimezone($timezone ?? 'Europe/Kyiv');
    }

    function response()
    {
        return new class {
            public function json($data): \Illuminate\Http\JsonResponse
            {
                return new \Illuminate\Http\JsonResponse($data);
            }
        };
    }

    putenv('ACCOUNTING_START_DATE');
    require_once dirname(__DIR__, 2) . '/app/Support/Reports/AccountingPeriod.php';
    require (getenv('FIREFLY_TEST_APP_ROOT') ?: dirname(__DIR__, 2)) . '/vendor/autoload.php';
    require dirname(__DIR__, 2) . '/app/Support/Reports/DashboardChartPeriods.php';
    require dirname(__DIR__, 2) . '/app/Support/Reports/RefundProjection.php';
    require dirname(__DIR__, 2) . '/app/Support/Reports/BudgetProjection.php';

    use Carbon\Carbon;
    use FireflyIII\Helpers\Collector\GroupCollectorInterface;
    use FireflyIII\Repositories\Account\AccountRepositoryInterface;
    use FireflyIII\Repositories\Budget\BudgetLimitRepositoryInterface;
    use FireflyIII\Repositories\Budget\BudgetRepositoryInterface;
    use FireflyIII\Support\Reports\DashboardChartPeriods;
    use FireflyIII\Support\Reports\ReportingCurrency;
    use Illuminate\Config\Repository;
    use Illuminate\Container\Container;
    use Illuminate\Http\Request;
    use Illuminate\Support\Collection;

    $checks = 0;
    function check(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
        ++$GLOBALS['checks'];
    }

    function context(string $query = '', string $method = 'GET'): void
    {
        $GLOBALS['periodRequest'] = Request::create('https://example.test/firefly' . ($query ? '?' . $query : ''), $method);
    }

    function sameDate($left, $right): bool
    {
        return $left->format('Y-m-d H:i:s.u e') === $right->format('Y-m-d H:i:s.u e');
    }

    context();
    foreach ([
        '2026-11-09' => ['30d' => '2026-10-11', '3m' => '2026-08-10', '1y' => '2025-11-10'],
        '2024-05-31' => ['30d' => '2024-05-02', '3m' => '2024-03-01', '1y' => '2023-06-01'],
        '2024-02-29' => ['30d' => '2024-01-31', '3m' => '2023-11-30', '1y' => '2023-03-01'],
        '2025-02-28' => ['30d' => '2025-01-30', '3m' => '2024-11-29', '1y' => '2024-02-29'],
        '2026-03-29' => ['30d' => '2026-02-28', '3m' => '2025-12-30', '1y' => '2025-03-30'],
        '2026-10-25' => ['30d' => '2026-09-26', '3m' => '2026-07-26', '1y' => '2025-10-26']
    ] as $end => $periods) {
        ReportingCurrency::$day = $end;
        foreach ($periods as $period => $start) {
            $range = DashboardChartPeriods::range($period);
            check(
                $range === ['period' => $period, 'start' => $start, 'end' => $end],
                'Rolling Kyiv bounds match capital history, including leap/DST/month ends.'
            );
            context('dashboard_period=' . $period);
            [$first, $last] = DashboardChartPeriods::requestRange(Carbon::parse('2020-01-01'), Carbon::parse('2020-02-01'));
            check($first->format('Y-m-d H:i:s.u e') === $start . ' 00:00:00.000000 Europe/Kyiv', 'Start is an inclusive Kyiv day.');
            check($last->format('Y-m-d H:i:s.u e') === $end . ' 23:59:59.999999 Europe/Kyiv', 'End includes the complete selected local day.');
        }
    }
    ReportingCurrency::$day = '2026-11-09 13:12:11';
    $clock                  = ReportingCurrency::today();
    check(
        DashboardChartPeriods::range('30d')['end'] === '2026-11-09' && $clock->format('H:i:s') === '13:12:11',
        'Date resolution never mutates the shared reporting clock.'
    );
    foreach ([null, [], ['3m'], true, 3, '', '3M', 'all', '../../1y'] as $invalid) {
        check(DashboardChartPeriods::range($invalid)['period'] === '30d', 'Malformed or unsupported periods have the safe 30-day default.');
    }
    ReportingCurrency::$day = '2026-11-09';
    context('capital_period=1y&budget_period=3m&category_period=30d&expense_period=1y&revenue_period=3m&search=a%26b');
    $query  = request()->query();
    $ranges = DashboardChartPeriods::all();
    check(array_keys($ranges) === ['capital', 'budget', 'category', 'expense', 'revenue'], 'Every visible graph has exactly one own range.');
    foreach (['capital' => '1y', 'budget' => '3m', 'category' => '30d', 'expense' => '1y', 'revenue' => '3m'] as $chart => $period) {
        check($ranges[$chart]['period'] === $period, 'Selection reads only its own chart parameter.');
        $link = DashboardChartPeriods::link($chart, '30d');
        parse_str((string) parse_url($link, PHP_URL_QUERY), $actual);
        $expected                     = $query;
        $expected[$chart . '_period'] = '30d';
        check($actual === $expected, 'A chart link preserves all other chart choices and query values.');
        check(parse_url($link, PHP_URL_FRAGMENT) === 'dashboard-' . $chart . '-period', 'Links return to the selected chart controls.');
        check(str_starts_with($link, 'https://example.test/firefly?'), 'Links preserve the application base path.');
    }
    check(request()->query() === $query, 'Rendering links never mutates the current query.');
    foreach (['', 'unknown', '../capital'] as $chart) {
        try {
            DashboardChartPeriods::forChart($chart);
            throw new RuntimeException('Unknown chart accepted.');
        } catch (InvalidArgumentException) {
            ++$checks;
        }
        try {
            DashboardChartPeriods::link($chart, '30d');
            throw new RuntimeException('Unknown chart link accepted.');
        } catch (InvalidArgumentException) {
            ++$checks;
        }
    }
    context('budget_period%5B%5D=1y');
    check(DashboardChartPeriods::forChart('budget')['period'] === '30d', 'Array-valued chart selection cannot escape validation.');
    context('dashboard_period%5B%5D=1y');
    [$first, $last] = DashboardChartPeriods::requestRange(Carbon::parse('2020-01-01'), Carbon::parse('2020-02-01'));
    check($first->format('Y-m-d') === '2026-10-11', 'Array-valued explicit API opt-in falls back to thirty days.');
    $originalStart = Carbon::parse('2025-01-02T09:30:00.123456Z');
    $originalEnd   = Carbon::parse('2025-01-31T19:45:00.654321Z');
    foreach ([
        ['',                    'GET'],
        ['dashboard_period=1y', 'POST'],
        ['dashboard_period=1y', 'HEAD']
    ] as [$query, $method]) {
        context($query, $method);
        [$first, $last] = DashboardChartPeriods::requestRange($originalStart, $originalEnd);
        check(sameDate($first, $originalStart) && sameDate($last, $originalEnd), 'Non-opted-in callers retain their complete original range.');
        check($first !== $originalStart && $last !== $originalEnd, 'Returned fallbacks cannot mutate the caller through shared Carbon instances.');
        check(!DashboardChartPeriods::isDashboardRequest(), 'Only explicit dashboard GET requests opt in.');
    }

    $nativeRoot = dirname(__DIR__, 2);
    $relative   = [
        'account'  => 'app/Http/Controllers/Chart/AccountController.php',
        'category' => 'app/Http/Controllers/Chart/CategoryController.php',
        'budget'   => 'app/Api/V1/Controllers/Chart/BudgetController.php'
    ];
    $after = [];
    foreach ($relative as $key => $file) {
        $after[$key] = file_get_contents($nativeRoot . '/' . $file);
    }
    check(
        substr_count($after['account'], 'DashboardChartPeriods::requestRange') === 2
        && substr_count($after['category'], 'DashboardChartPeriods::requestRange') === 1
        && substr_count($after['budget'], 'DashboardChartPeriods::requestRange') === 1,
        'Exactly four native homepage projection branches select their own request range.'
    );
    // Execute the actual controller projection guards with controlled owner-scoped collaborators.
    $container = new Container();
    Container::setInstance($container);
    $container->instance('config', new Repository([
        'app'     => ['timezone' => 'Europe/Kyiv'],
        'firefly' => ['accountTypesByIdentifier' => ['asset' => ['Asset account']]]
    ]));
    $accounts = new class {
        public object $user;

        public function getAccountsByType($types): Collection
        {
            return new Collection([(object) ['id' => 1]]);
        }

        public function setUser($user): void
        {
            $this->user = $user;
        }
    };
    $budgets = new class {
        public object $user;

        public function getActiveBudgets(): Collection
        {
            return new Collection([(object) ['id' => 10, 'name' => 'Food']]);
        }

        public function setUser($user): void
        {
            $this->user = $user;
        }
    };
    $limits = new class {
        public object $user;
        public array $calls = [];

        public function getBudgetLimits($budget, $start, $end): Collection
        {
            $this->calls[] = [$start->format('Y-m-d'), $end->format('Y-m-d')];
            return new Collection([(object) [
                'id'                  => 20,
                'amount'              => '1000',
                'transactionCurrency' => (object) ['code' => 'UAH'],
                'start_date'          => Carbon::parse('2026-11-01', 'Europe/Kyiv'),
                'end_date'            => Carbon::parse('2026-11-30', 'Europe/Kyiv')
            ]]);
        }

        public function setUser($user): void
        {
            $this->user = $user;
        }
    };
    $collector = new class {
        public array $calls    = [];
        public array $journals = [];

        public function getExtractedJournals(): array
        {
            return $this->journals;
        }

        public function __call($name, $args)
        {
            $this->calls[$name] = $args;
            return $this;
        }
    };
    foreach ([
        AccountRepositoryInterface::class     => $accounts,
        BudgetRepositoryInterface::class      => $budgets,
        BudgetLimitRepositoryInterface::class => $limits,
        GroupCollectorInterface::class        => $collector
    ] as $id => $object) {
        $container->instance($id, $object);
    }
    $generator = new class {
        public function multiSet($rows)
        {
            return $rows;
        }
    };
    $owner                    = (object) ['id' => 123];
    $GLOBALS['periodSession'] = ['start' => Carbon::parse('2025-01-01', 'Europe/Kyiv'), 'end' => Carbon::parse('2025-01-31', 'Europe/Kyiv')];
    $nativeBody               = static function (string $source, string $name): string {
        $position = strpos($source, 'public function ' . $name . '(');
        if (false === $position) {
            throw new RuntimeException('Native method missing.');
        }
        $first = strpos($source, '{', $position);
        $depth = 0;
        $end   = null;
        foreach (PhpToken::tokenize('<?php ' . substr($source, $first)) as $token) {
            if ($token->is('{') || $token->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                ++$depth;
            }
            if ($token->is('}') && --$depth === 0) {
                $end = $first + $token->pos - strlen('<?php ');
                break;
            }
        }
        if (null === $end) {
            throw new RuntimeException('Native body incomplete.');
        }
        return substr($source, $first, $end - $first + 1);
    };
    $probe = static function (string $body) use ($generator, $accounts, $owner): object {
        $body   = str_replace('AccountTypeEnum::', '\\FireflyIII\\Enums\\AccountTypeEnum::', $body);
        $object = eval('return new class { public object $generator; public object $accountRepository; public object $user; public function run($request=null): \\Illuminate\\Http\\JsonResponse '
            . $body
            . ' };');
        $object->generator         = $generator;
        $object->accountRepository = $accounts;
        $object->user              = $owner;
        return $object;
    };
    foreach (['expense' => ['account', 'expenseAccounts'], 'revenue' => ['account', 'revenueAccounts'], 'category' => ['category', 'frontPage']] as $kind => [
        $file,
        $method
    ]) {
        $controller = $probe($nativeBody($after[$file], $method));
        foreach (['30d' => '2026-10-11', '3m' => '2026-08-10', '1y' => '2025-11-10'] as $period => $expectedStart) {
            context('dashboard_period=' . $period);
            $savedSession = serialize($GLOBALS['periodSession']);
            $result       = $controller->run()->getData(true);
            check(
                $result['kind'] === $kind && str_starts_with($result['start'], $expectedStart . ' 00:00:00'),
                'The real native legacy projection receives its selected start.'
            );
            check($result['end'] === '2026-11-09 23:59:59.999999 Europe/Kyiv', 'The real native legacy projection receives the complete selected end day.');
            check(serialize($GLOBALS['periodSession']) === $savedSession, 'Changing a chart never changes the global session range.');
        }
        context();
        $result = $controller->run()->getData(true);
        check(
            str_starts_with($result['start'], '2025-01-01 ') && str_starts_with($result['end'], '2025-01-31 '),
            'The native projection keeps its session dates without the explicit opt-in.'
        );
    }

    ReportingCurrency::$day = '2026-12-15';
    $collector->journals    = [];
    foreach (['before' => ['2026-11-05', '-100.00'], 'inside' => ['2026-11-20', '-12.00'], 'after' => ['2026-12-20', '-90.00']] as $id => [$day, $amount]) {
        $collector->journals[] = [
            'transaction_journal_id' => $id,
            'date'                   => Carbon::parse($day, 'Europe/Kyiv'),
            'transaction_type_type'  => 'Withdrawal',
            'source_account_id'      => 1,
            'destination_account_id' => 900,
            'budget_id'              => 10,
            'currency_code'          => 'UAH',
            'amount'                 => $amount
        ];
    }
    $budgetController = $probe($nativeBody($after['budget'], 'overviewWithBudgetLimits'));
    $rangeRequest     = Request::create('/?start=2026-11-16&end=2026-12-15');
    $rangeRequest->attributes->set('start', Carbon::parse('2026-11-16', 'Europe/Kyiv'));
    $rangeRequest->attributes->set('end', Carbon::parse('2026-12-15', 'Europe/Kyiv')->endOfDay());
    $originalJournalData = serialize($collector->journals);
    foreach (['30d' => ['2026-11-16', '-12.00'], '3m' => ['2026-09-16', '-112.00'], '1y' => ['2025-12-16', '-112.00']] as $period => [$expectedStart, $spent]) {
        context('dashboard_period=' . $period);
        $rows = $budgetController->run($rangeRequest)->getData(true);
        check(count($rows) === 1 && $rows[0]['label'] === 'Food', 'Dashboard budgets aggregate one row per budget, not full overlapping limits.');
        check($rows[0]['entries']['spent'] === $spent, 'Actual budget projection excludes charges outside the selected rolling range.');
        check($rows[0]['entries']['budgeted'] === '1000.00', 'Existing overlapping caps are preserved without invented proration.');
        check(
            str_starts_with($rows[0]['start_date'], $expectedStart) && str_starts_with($rows[0]['end_date'], '2026-12-15'),
            'Budget response date metadata matches the selected interval.'
        );
    }
    context();
    $legacy = $budgetController->run($rangeRequest)->getData(true);
    check(count($legacy) === 1 && str_contains($legacy[0]['label'], '2026-11-01 — 2026-11-30'), 'The non-dashboard API keeps its original per-limit contract.');
    check($legacy[0]['entries']['spent'] === '-112.00', 'Native per-limit callers still include the complete overlapping limit.');
    check(serialize($collector->journals) === $originalJournalData, 'Period switches never rewrite original financial rows.');
    check(
        $accounts->user === $owner && $budgets->user === $owner && $limits->user === $owner && $collector->calls['setUser'] === [$owner],
        'The budget projection remains explicitly owner-scoped.'
    );
    check($collector->calls['setAccounts'][0]->pluck('id')->all() === [1], 'The chart collector still queries only owned asset accounts.');

    ReportingCurrency::$day = '2026-11-09';
    putenv('ACCOUNTING_START_DATE=2026-11-07');
    context('capital_period=1y&budget_period=3m&category_period=1y&expense_period=3m&revenue_period=1y');
    foreach (DashboardChartPeriods::all() as $range) {
        check($range['start'] === '2026-11-07' && $range['end'] === '2026-11-09', 'Every homepage chart caption starts at accounting coverage.');
    }
    foreach (['30d', '3m', '1y'] as $period) {
        context('dashboard_period=' . $period);
        [$first, $last] = DashboardChartPeriods::requestRange(Carbon::parse('2020-01-01'), Carbon::parse('2020-02-01'));
        check(
            $first->format('Y-m-d H:i:s.u e') === '2026-11-07 00:00:00.000000 Europe/Kyiv' && $last->format('Y-m-d') === '2026-11-09',
            'Dashboard API projection and displayed date range share the clamped inclusive boundary.'
        );
    }
    context();
    [$first, $last] = DashboardChartPeriods::requestRange($originalStart, $originalEnd);
    check(sameDate($first, $originalStart) && sameDate($last, $originalEnd), 'Opt-in clamping does not change unrelated caller ranges.');
    putenv('ACCOUNTING_START_DATE');
    echo 'Passed ' . $checks . " independent dashboard chart period checks.\n";
}
