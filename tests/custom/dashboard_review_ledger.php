<?php

declare(strict_types=1);

namespace FireflyIII\Models {
    final class Transaction
    {
        public static array $rows = [];

        public static function query(): object
        {
            return new class {
                public function get(): array
                {
                    return Transaction::$rows;
                }

                public function whereHas(...$args): self
                {
                    return $this;
                }

                public function whereIn(...$args): self
                {
                    return $this;
                }

                public function with(...$args): self
                {
                    return $this;
                }
            };
        }
    }
}

namespace {
    function auth(): object
    {
        return new class {
            public function user(): object
            {
                return (object) ['user_group_id' => 1];
            }
        };
    }

    $fixtureAppRoot = getenv('FIREFLY_TEST_APP_ROOT') ?: dirname(__DIR__, 2);
    require $fixtureAppRoot . '/vendor/autoload.php';
    require dirname(__DIR__, 2) . '/app/Support/Reports/BalanceHistory.php';
    require dirname(__DIR__, 2) . '/app/Support/Reports/DashboardLedger.php';
    require dirname(__DIR__, 2) . '/app/Support/Reports/CurrencyBridge.php';
    require dirname(__DIR__, 2) . '/app/Support/Reports/NetWorthSeries.php';

    use Carbon\Carbon;
    use FireflyIII\Models\Transaction;
    use FireflyIII\Repositories\Account\AccountRepositoryInterface;
    use FireflyIII\Support\Reports\DashboardLedger;
    use FireflyIII\Support\Reports\NetWorthSeries;
    use Illuminate\Container\Container;
    use Illuminate\Http\Request;
    use Illuminate\Support\Collection;

    $container = new Container();
    Container::setInstance($container);
    $container->instance('config', new \Illuminate\Config\Repository([
        'firefly' => ['accountTypesByIdentifier' => ['asset' => ['asset'], 'liabilities' => []]]
    ]));
    $repository = new class {
        public array $accounts = [];

        public function getAccountCurrency($account): object
        {
            return (object) ['code' => 'UAH'];
        }

        public function getAccountsByType($types): Collection
        {
            return new Collection($this->accounts);
        }

        public function getMetaValue($account, $key): string
        {
            return '1';
        }

        public function getNoteText($account): string
        {
            return $account->notes;
        }

        public function setUser($user): void {}
    };
    $container->instance(AccountRepositoryInterface::class, $repository);
    function movement(string $id, string $account, string $day, string $amount, string $type): object
    {
        return (object) [
            'id'                  => $id,
            'account_id'          => $account,
            'amount'              => $amount,
            'transactionCurrency' => (object) ['code' => 'UAH'],
            'transactionJournal'  => (object) [
                'id'              => $id,
                'date'            => Carbon::parse($day, 'Europe/Kyiv'),
                'transactionType' => (object) ['type' => $type],
                'budgets'         => new Collection()
            ]
        ];
    }

    $repository->accounts = [
        (object) ['id' => '1', 'notes' => 'Original account history'],
        (object) ['id' => '2', 'notes' => json_encode(['bank_review_suspense' => 1, 'currency' => 'UAH'])]
    ];
    Transaction::$rows = [
        movement('1', '1', '2026-10-01', '50000', 'Opening balance'),
        movement('2', '1', '2026-11-08', '-20000', 'Transfer'),
        movement('3', '2', '2026-11-08', '20000', 'Transfer')
    ];
    $container->instance('request', Request::create('/'));
    $ledger = DashboardLedger::data();
    if ('1970-01-01' !== $ledger['accounts']['2']['known_from']) {
        throw new RuntimeException('Suspense must have known zero prehistory.');
    }
    if ('2026-10-01' !== $ledger['accounts']['1']['known_from']) {
        throw new RuntimeException('Real account coverage changed.');
    }
    $series = NetWorthSeries::build($ledger['accounts'], $ledger['legs'], ['UAH' => '1', 'EUR' => '50'], '2026-10-10', '2026-11-08');
    if (array_unique(array_column($series['points'], 'total')) !== ['1000.00']) {
        throw new RuntimeException('A pending transfer must neither change net worth nor erase earlier coverage.');
    }
    $repository->accounts[1]->notes = json_encode(['bank_review_suspense' => 1, 'currency' => 'EUR']);
    $container->instance('request', Request::create('/'));
    if ('2026-11-08' !== DashboardLedger::data()['accounts']['2']['known_from']) {
        throw new RuntimeException('A mismatched marker must not grant zero prehistory.');
    }
    echo "Passed 4 pending-transfer capital history checks.\n";
}
