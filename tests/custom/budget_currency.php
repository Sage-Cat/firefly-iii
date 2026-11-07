<?php

declare(strict_types=1);

namespace FireflyIII\Support\Reports {
    /** Fixed current-day quote fixture: deliberately unrelated to journal dates. */
    final class ReportingCurrency
    {
        public static array $calls = [];

        public static function convert(string $amount, string $source, string $target): string
        {
            self::$calls[] = [$amount, $source, $target];
            $quotes        = ['UAH' => '1', 'EUR' => '50', 'USD' => '40'];
            if (!isset($quotes[$source], $quotes[$target])) {
                throw new \RuntimeException('Missing fixture quote.');
            }
            $value = bcdiv(bcmul($amount, $quotes[$source], 12), $quotes[$target], 12);
            return bcadd($value, bccomp($value, '0', 12) < 0 ? '-0.005' : '0.005', 2);
        }

        public static function currency(string $code): object
        {
            return new class($code) {
                public int    $id             = 7;
                public string $symbol         = '₴';
                public string $name           = 'Hryvnia';
                public int    $decimal_places = 2;

                public function __construct(
                    public string $code
                ) {}

                public function toArray(): array
                {
                    return get_object_vars($this);
                }
            };
        }

        public static function isDisplayRequest(): bool
        {
            return true;
        }

        public static function today(): \Carbon\Carbon
        {
            return \Carbon\Carbon::parse('2026-12-01', 'Europe/Kyiv');
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

    require dirname(__DIR__, 2) . '/vendor/autoload.php';
    require dirname(__DIR__, 2) . '/app/Support/Reports/BudgetProjection.php';

    use Carbon\Carbon;
    use FireflyIII\Support\Reports\BudgetProjection;
    use FireflyIII\Support\Reports\ReportingCurrency;
    use Illuminate\Config\Repository;
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

    function rejects(callable $call): void
    {
        try {
            $call();
        } catch (RuntimeException $exception) {
            check(true, $exception->getMessage());
            return;
        }
        throw new RuntimeException('Expected explicit rejection.');
    }

    function journal(
        string $id,
        string $amount,
        string $currency = 'UAH',
        null|string $budget = '10',
        string $day = '2026-11-03',
        string $source = '1',
        string $destination = 'merchant',
        string $type = 'Withdrawal'
    ): array {
        return [
            'id'             => $id,
            'day'            => $day,
            'amount'         => $amount,
            'currency'       => $currency,
            'budget_id'      => $budget,
            'source_id'      => $source,
            'destination_id' => $destination,
            'type'           => $type
        ];
    }

    function limit(string $id, string $amount, string $currency = 'UAH', string $start = '2026-11-01', string $end = '2026-11-30'): array
    {
        return [
            'id'                       => $id,
            'amount'                   => $amount,
            'currency_id'              => 'original-' . $currency,
            'projection_currency_code' => $currency,
            'projection_start'         => $start,
            'projection_end'           => $end
        ];
    }

    $start     = '2026-11-01';
    $end       = '2026-11-30';
    $inputs    = [journal('eur', '-2', 'EUR'), journal('usd', '-3', 'USD'), journal('uah', '-5')];
    $inputs[0] += ['foreign_amount' => '9999', 'pc_amount' => '8888', 'native_amount' => '7777'];
    $original  = $inputs;
    $projected = BudgetProjection::project($inputs, ['1'], $start, $end, ['10']);
    check(array_column($projected, 'amount') === ['-100.00', '-120.00', '-5.00'], 'Each original transaction converts once at current-day quotes.');
    check(BudgetProjection::sum($projected, $start, $end, ['10']) === '-225.00', 'Foreign expenses enter the UAH budget total.');
    check($inputs === $original, 'Projection cannot rewrite original, native or primary-currency fields.');
    check($projected[0]['source_amount'] === '-2' && $projected[0]['source_currency'] === 'EUR', 'Original values remain available in the display copy.');
    check(array_column($projected, 'currency') === ['UAH', 'UAH', 'UAH'], 'All projected rows use UAH metadata.');

    $ignored = [
        journal('unbudgeted', '-999', 'UNKNOWN', null),
        journal('other-budget', '-999', 'UNKNOWN', '20'),
        journal('other-owner', '-999', 'UNKNOWN', '10', '2026-11-03', 'foreign-owner'),
        journal('internal', '-999', 'UNKNOWN', '10', '2026-11-03', '1', '2'),
        journal('outside', '-999', 'UNKNOWN', '10', '2026-10-31'),
        journal('income', '-999', 'UNKNOWN', '10', '2026-11-03', '1', 'merchant', 'Deposit'),
        journal('transfer', '-999', 'UNKNOWN', '10', '2026-11-03', '1', 'merchant', 'Transfer')
    ];
    check(
        BudgetProjection::project($ignored, ['1', '2'], $start, $end, ['10']) === [],
        'Ownership, type, dates and concrete budget filter precede conversion.'
    );
    check(BudgetProjection::project($inputs, [], $start, $end, null) === [], 'Empty asset selection never exposes all accounts.');
    check(BudgetProjection::project($inputs, ['1'], $start, $end, []) === [], 'No selected budgets means no selected expenses.');
    check(BudgetProjection::sum($projected, $start, $end, ['20']) === '0.00', 'A concrete budget never includes another budget.');
    check(BudgetProjection::sum($projected, '2026-11-04', $end, null) === '0.00', 'Period totals exclude outside dates.');
    rejects(fn() => BudgetProjection::project([journal('unsupported', '-1', 'UNKNOWN')], ['1'], $start, $end, ['10']));
    rejects(fn() => BudgetProjection::project([$inputs[0], $inputs[0]], ['1'], $start, $end, ['10']));
    rejects(fn() => BudgetProjection::project([journal('invalid', '1e3')], ['1'], $start, $end, ['10']));

    $budgets         = [['id' => '10', 'budgeted' => [limit('a', '10.00', 'EUR')]], ['id' => '20', 'budgeted' => []]];
    $originalBudgets = $budgets;
    $index           = BudgetProjection::indexRows($budgets, $projected, $start, $end);
    check(
        $index[0]['budgeted'][0]['amount'] === '10.00' && $index[0]['budgeted'][0]['currency_id'] === 'original-EUR',
        'Editable cap amount and currency ID remain original.'
    );
    check(
        $index[0]['budgeted'][0]['display_amount'] === '500.00'
        && $index[0]['budgeted'][0]['display_spent'] === '-225.00'
        && $index[0]['budgeted'][0]['display_left'] === '275.00',
        'Foreign cap and mixed expenses have consistent UAH equivalents.'
    );
    check($index[0]['display_outside'] === '0.00' && $index[1]['display_spent'] === '0.00', 'Assigned spending stays within its concrete budget.');
    check($budgets === $originalBudgets, 'Budget projection does not mutate original limit inputs.');

    $historical = BudgetProjection::project(
        [journal('sept', '-1', 'EUR', '10', '2026-09-10'), journal('oct', '-2', 'USD', '10', '2026-10-10'), journal('nov', '-3', 'UAH', '10', '2026-11-10')],
        ['1'],
        '2026-09-01',
        $end,
        ['10']
    );
    $multi   = [['id' => '10', 'budgeted' => [limit('sept', '100', 'UAH', '2026-09-01', '2026-09-30'), limit('nov', '100', 'UAH')]]];
    $history = BudgetProjection::indexRows($multi, $historical, '2026-09-01', $end)[0];
    check(
        $history['display_amount'] === '200.00' && $history['display_spent'] === '-133.00',
        'History combines distinct monthly caps and each withdrawal once.'
    );
    check(
        $history['budgeted'][0]['display_spent'] === '-50.00'
        && $history['budgeted'][1]['display_spent'] === '-3.00'
        && $history['display_outside'] === '-80.00',
        'Expenses without a matching limit remain visible without duplicating capped months.'
    );
    $parallel = BudgetProjection::indexRows(
        [['id' => '10', 'budgeted' => [limit('eur', '10', 'EUR'), limit('uah', '100', 'UAH')]]],
        $projected,
        $start,
        $end
    )[0];
    check(
        $parallel['budgeted'][0]['display_spent'] === '-100.00'
        && $parallel['budgeted'][1]['display_spent'] === '-5.00'
        && $parallel['display_outside'] === '-120.00',
        'Parallel original-currency caps do not charge the same expense twice.'
    );
    check($parallel['display_spent'] === '-225.00', 'Parallel-cap total still includes every assigned currency.');

    $container = new Container();
    Container::setInstance($container);
    $container->instance('config', new Repository(['firefly' => ['accountTypesByIdentifier' => ['asset' => ['Asset account']]]]));
    $accounts = new class {
        public Collection $assets;
        public object $user;

        public function getAccountsByType(array $types): Collection
        {
            return $this->assets;
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

        public function __call($name, $arguments)
        {
            $this->calls[$name] = $arguments;
            return $this;
        }
    };
    $container->instance(\FireflyIII\Repositories\Account\AccountRepositoryInterface::class, $accounts);
    $container->instance(\FireflyIII\Helpers\Collector\GroupCollectorInterface::class, $collector);
    $user             = (object) ['id' => 123];
    $accounts->assets = new Collection();
    $collect          = new ReflectionMethod(BudgetProjection::class, 'collect');
    $from             = Carbon::parse($start, 'Europe/Kyiv');
    $until            = Carbon::parse($end, 'Europe/Kyiv');
    check($collect->invoke(null, $user, $from, $until) === [] && $collector->calls === [], 'Collector is never invoked with an empty owned asset list.');
    $accounts->assets = new Collection([(object) ['id' => 1], (object) ['id' => 2]]);
    check($collect->invoke(null, $user, $from, $until, new Collection([(object) [
        'id' => 999
    ]])) === [] && $collector->calls === [], 'Requested account filter intersects ownership before querying.');
    $collector->journals = [[
        'transaction_journal_id'   => 'native',
        'date'                     => Carbon::parse('2026-11-03', 'Europe/Kyiv'),
        'transaction_type_type'    => 'Withdrawal',
        'source_account_id'        => 1,
        'destination_account_id'   => 3,
        'budget_id'                => 10,
        'currency_code'            => 'EUR',
        'amount'                   => '-2',
        'source_account_name'      => 'Owned',
        'destination_account_name' => 'Merchant',
        'category_name'            => 'Food',
        'budget_name'              => 'Food budget'
    ]];
    $native = $collect->invoke(null, $user, $from, $until, new Collection([(object) ['id' => 1], (object) ['id' => 999]]), ['10']);
    check($native[0]['amount'] === '-100.00' && $native[0]['source_name'] === 'Owned', 'Native collector fields normalize to one current UAH expense.');
    check($accounts->user === $user && $collector->calls['setUser'] === [$user], 'Asset repository and collector are explicitly scoped to the user.');
    check($collector->calls['setAccounts'][0]->pluck('id')->all() === [1], 'Collector receives only owned requested assets.');
    check(
        isset($collector->calls['withBudgetInformation'], $collector->calls['withCategoryInformation']),
        'Budget/category metadata is requested before extracting rows.'
    );
    check($from->format('H:i:s') === '00:00:00' && $until->format('H:i:s') === '00:00:00', 'Collector range normalization does not mutate callers.');
    check(
        $collector->calls['setRange'][0]->format('Y-m-d H:i:s') === '2026-10-31 00:00:00'
        && $collector->calls['setRange'][1]->format('Y-m-d H:i:s') === '2026-12-01 23:59:59',
        'SQL collection pads both local-day boundaries before exact Kyiv-day filtering.'
    );
    $collector->journals[0]['date'] = Carbon::parse('2026-10-31T22:30:00Z');
    $midnight                       = $collect->invoke(null, $user, $from, $until, null, ['10']);
    check($midnight[0]['day'] === '2026-11-01' && $midnight[0]['amount'] === '-100.00', 'A late-UTC October withdrawal belongs to November in Kyiv.');
    $collector->journals[0]['date'] = Carbon::parse('2026-11-30T22:30:00Z');
    check(
        $collect->invoke(null, $user, $from, $until, null, ['10']) === [],
        'Padded collection never includes a withdrawal whose Kyiv date is already December.'
    );
    $collector->journals[0]['date']          = Carbon::parse('2026-11-03', 'Europe/Kyiv');
    $collector->journals[0]['budget_id']     = null;
    $collector->journals[0]['currency_code'] = 'UNKNOWN';
    check($collect->invoke(null, $user, $from, $until, null, ['10']) === [], 'Unbudgeted native withdrawals never require a quote for a concrete budget.');

    $apiRow = new ReflectionMethod(BudgetProjection::class, 'apiRow');
    $api    = $apiRow->invoke(null, 'Food', '100.00', '-120.00', $from, $until, ReportingCurrency::currency('UAH'));
    check(
        $api['currency_code'] === 'UAH' && $api['primary_currency_code'] === 'UAH' && $api['entries'] === $api['pc_entries'],
        'Chart response display metadata agrees with UAH series.'
    );
    check(
        $api['entries'] === ['budgeted' => '100.00', 'spent' => '-120.00', 'left' => '0.00', 'overspent' => '20.00'],
        'Chart overspending retains positive overflow and signed spending.'
    );

    \Illuminate\Support\Facades\Facade::setFacadeApplication($container);
    $container->instance('amount', new class {
        public function formatAnything($currency, string $amount): string
        {
            return $currency->code . ' ' . $amount;
        }
    });
    $collector->journals[0]['budget_id']     = 10;
    $collector->journals[0]['currency_code'] = 'EUR';
    $collector->journals[]                   = array_replace($collector->journals[0], [
        'transaction_journal_id' => 'native-usd',
        'currency_code'          => 'USD',
        'amount'                 => '-3'
    ]);
    $savedLimit = (object) [
        'budget_id'           => 10,
        'start_date'          => $from,
        'end_date'            => $until,
        'amount'              => '10.00',
        'transactionCurrency' => (object) ['code' => 'EUR']
    ];
    $response = [
        'id'                      => 99,
        'amount'                  => '10.00',
        'transaction_currency_id' => 3,
        'spent'                   => '-9999',
        'left_per_day'            => '9.99',
        'days_left'               => '10',
        'left_formatted'          => 'EUR old',
        'left_per_day_formatted'  => 'EUR old/day'
    ];
    $formatted = BudgetProjection::limitResponse($user, $savedLimit, $response);
    check(
        $formatted['left_formatted'] === 'UAH 280.00' && $formatted['left_per_day_formatted'] === 'UAH 28.00',
        'Successful native save gets current UAH display strings including every original expense currency.'
    );
    $untouched = $formatted;
    foreach (['left_formatted', 'left_per_day_formatted'] as $key)
        unset($untouched[$key]);
    $originalFields = $response;
    foreach (['left_formatted', 'left_per_day_formatted'] as $key)
        unset($originalFields[$key]);
    check(
        $untouched === $originalFields && $savedLimit->amount === '10.00' && $savedLimit->transactionCurrency->code === 'EUR',
        'Response formatting cannot change native persisted cap, ID, currency, spent or per-day fields.'
    );
    $savedLimit->amount                    = '500.00';
    $savedLimit->transactionCurrency->code = 'UAH';
    check(
        BudgetProjection::limitResponse($user, $savedLimit, $response)['left_formatted'] === 'UAH 280.00',
        'Native UAH inline editing still includes actual EUR/USD-source expenses.'
    );
    $collector->journals[0]['currency_code'] = 'UNKNOWN';
    $unavailable                             = BudgetProjection::limitResponse($user, $savedLimit, $response);
    check(
        str_contains($unavailable['left_formatted'], '—') && $unavailable['amount'] === '10.00' && $unavailable['id'] === 99,
        'Conversion failure gives an explicit unavailable display without invalidating or changing the completed native save.'
    );

    $container->instance('translator', new class {
        public function get(string $key, array $replace = [], null|string $locale = null): string
        {
            return $key;
        }
    });
    $budgetRepository = new class {
        public function getActiveBudgets(): Collection
        {
            return new Collection([(object) ['id' => 10]]);
        }

        public function setUser($user): void {}
    };
    $limitRepository = new class {
        public array $limits = [];

        public function getBudgetLimits($budget, $start, $end): Collection
        {
            return new Collection(array_filter($this->limits, static fn($limit): bool => $limit->start_date <= $end && $limit->end_date >= $start));
        }

        public function setUser($user): void {}
    };
    $container->instance(\FireflyIII\Repositories\Budget\BudgetRepositoryInterface::class, $budgetRepository);
    $container->instance(\FireflyIII\Repositories\Budget\BudgetLimitRepositoryInterface::class, $limitRepository);
    $container->instance(\FireflyIII\Repositories\Budget\AvailableBudgetRepositoryInterface::class, new class {
        public function get($start, $end): Collection
        {
            return new Collection();
        }

        public function setUser($user): void {}
    });
    $quarter = (object) [
        'id'                  => 'quarter',
        'budget_id'           => 10,
        'amount'              => '24000.00',
        'start_date'          => Carbon::parse('2026-10-01', 'Europe/Kyiv'),
        'end_date'            => Carbon::parse('2026-12-31', 'Europe/Kyiv'),
        'transactionCurrency' => (object) ['code' => 'UAH']
    ];
    $limitRepository->limits = [$quarter];
    $collector->journals     = [[
        'transaction_journal_id' => 'nov-tax',
        'date'                   => Carbon::parse('2026-11-20', 'Europe/Kyiv'),
        'transaction_type_type'  => 'Withdrawal',
        'source_account_id'      => 1,
        'destination_account_id' => 3,
        'budget_id'              => 10,
        'currency_code'          => 'UAH',
        'amount'                 => '-18000.00',
        'category_name'          => 'Tax'
    ]];
    $december    = Carbon::parse('2026-12-01', 'Europe/Kyiv');
    $decemberEnd = Carbon::parse('2026-12-31', 'Europe/Kyiv');
    $widget      = BudgetProjection::leftToSpend($user, $december, $decemberEnd);
    check($widget[7]['monetary_value'] === '6000.00', 'December widget deducts November tax from the whole overlapping Q4 cap.');
    check(
        $collector->calls['setRange'][0]->format('Y-m-d') === '2026-09-30' && $collector->calls['setRange'][1]->format('Y-m-d') === '2027-01-01',
        'Widget collects the full limit coverage with UTC boundary padding instead of only the selected month.'
    );
    $monthly = (object) [
        'id'                  => 'december',
        'budget_id'           => 10,
        'amount'              => '60.00',
        'start_date'          => $december->copy(),
        'end_date'            => $decemberEnd->copy(),
        'transactionCurrency' => (object) ['code' => 'EUR']
    ];
    $limitRepository->limits[] = $monthly;
    $collector->journals[]     = array_replace($collector->journals[0], [
        'transaction_journal_id' => 'dec-tax',
        'date'                   => Carbon::parse('2026-12-10', 'Europe/Kyiv'),
        'currency_code'          => 'USD',
        'amount'                 => '-25.00'
    ]);
    $collector->journals[] = array_replace($collector->journals[0], [
        'transaction_journal_id' => 'unbudgeted-dec',
        'budget_id'              => null,
        'date'                   => Carbon::parse('2026-12-10', 'Europe/Kyiv'),
        'currency_code'          => 'UNKNOWN'
    ]);
    $widget = BudgetProjection::leftToSpend($user, $december, $decemberEnd);
    check($widget[7]['monetary_value'] === '8000.00', 'Q4 and December overlapping limits deduct a shared December expense once after current UAH conversion.');
    check(
        $quarter->amount === '24000.00' && $monthly->amount === '60.00' && $monthly->transactionCurrency->code === 'EUR',
        'Coverage accounting leaves both native cap amounts and currencies intact.'
    );
    check(
        $december->format('Y-m-d H:i:s') === '2026-12-01 00:00:00' && $decemberEnd->format('Y-m-d H:i:s') === '2026-12-31 00:00:00',
        'Coverage expansion does not change the selected monthly range.'
    );

    $summary     = new \ReflectionClass(\FireflyIII\Api\V1\Controllers\Summary\BasicController::class)->newInstanceWithoutConstructor();
    $leftToSpend = new \ReflectionMethod($summary, 'getLeftToSpendInfo');
    check(
        $leftToSpend->invoke($summary, $december, $decemberEnd) === $widget,
        'Basic summary guard obtains the authenticated user without reading its uninitialized inherited property.'
    );

    echo 'Passed ' . $checks . " budget currency checks.\n";
}
