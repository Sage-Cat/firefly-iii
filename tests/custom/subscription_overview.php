<?php

declare(strict_types=1);

namespace FireflyIII\Models {
    final class Bill
    {
        public static array $rows = [];

        public static function query(): object
        {
            return new class {
                private null|int $group  = null;
                private bool     $active = false;

                public function get(): array
                {
                    if (null === $this->group || !$this->active) {
                        throw new \RuntimeException('Owner and active scope are required.');
                    }
                    return array_values(array_filter(Bill::$rows, fn($bill): bool => $bill->user_group_id === $this->group && $bill->active));
                }

                public function orderBy(string $field): self
                {
                    return $this;
                }

                public function where(string $field, $value): self
                {
                    if ('user_group_id' === $field) {
                        $this->group = (int) $value;
                    }
                    if ('active' === $field && true === $value) {
                        $this->active = true;
                    }
                    return $this;
                }

                public function with(string $relation): self
                {
                    return $this;
                }
            };
        }
    }
}

namespace FireflyIII\Support\Reports {
    final class ReportingCurrency
    {
        public static array $quotes = ['EUR' => '50', 'USD' => '45', 'CHF' => '52', 'USDT' => '44'];

        public static function quotes(): array
        {
            return self::$quotes;
        }

        public static function today(): \DateTimeImmutable
        {
            return new \DateTimeImmutable('2026-07-15');
        }
    }
}

namespace {
    require dirname(__DIR__, 2) . '/app/Support/Reports/CurrencyBridge.php';
    require dirname(__DIR__, 2) . '/app/Support/Reports/SubscriptionOverview.php';

    use FireflyIII\Models\Bill;
    use FireflyIII\Support\Reports\SubscriptionOverview;

    $checks = 0;
    function check(bool $value, string $message): void
    {
        global $checks;
        if (!$value) {
            throw new RuntimeException($message);
        }
        ++$checks;
    }

    function rejects(callable $fn): void
    {
        try {
            $fn();
        } catch (RuntimeException) {
            check(true, 'Rejected invalid input.');
            return;
        }
        throw new RuntimeException('Invalid subscription was accepted.');
    }

    function bill(
        string $id,
        string $minimum,
        string $currency = 'UAH',
        string $frequency = 'monthly',
        int $skip = 0,
        bool $active = true,
        null|string $maximum = null
    ): array {
        return [
            'id'        => $id,
            'name'      => 'Subscription ' . $id,
            'minimum'   => $minimum,
            'maximum'   => $maximum ?? $minimum,
            'currency'  => $currency,
            'frequency' => $frequency,
            'skip'      => $skip,
            'active'    => $active
        ];
    }

    $quotes = ['EUR' => '50', 'USD' => '45', 'CHF' => '52', 'USDT' => '44'];
    $rows   = [
        bill('1', '10', 'USD'),
        bill('2', '120', 'EUR', 'yearly'),
        bill('3', '60', 'UAH', 'monthly', 1),
        bill('4', '90', 'CHF', 'quarterly', 0, true, '110'),
        bill('5', '9999', 'USD', 'monthly', 0, false)
    ];
    $before = serialize([$rows, $quotes]);
    $result = SubscriptionOverview::build($rows, $quotes, '15.07.2026');
    check(
        $result['count'] === 4 && $result['uah'] === '2713.33' && $result['eur'] === '54.27',
        'Mixed currencies and frequencies must use the current UAH bridge.'
    );
    check(serialize([$rows, $quotes]) === $before, 'Original subscription amounts must never be mutated.');
    $byId = array_column($result['rows'], null, 'id');
    check($byId['1']['uah'] === '450.00' && $byId['2']['uah'] === '500.00' && $byId['3']['uah'] === '30.00', 'Monthly, annual and skipped periods differ.');
    check($result['rows'][0]['id'] === '4' && $result['rows'][0]['color'] === 0, 'Legend must have deterministic order and matching colors.');
    check(
        array_reduce($result['rows'], static fn($sum, $row) => bcadd($sum, $row['uah'], 2), '0.00') === $result['uah'],
        'Legend and chart amounts must sum to the displayed total.'
    );
    $fraction = SubscriptionOverview::build(
        [bill('1', '0.05', 'UAH', 'yearly'), bill('2', '0.05', 'UAH', 'yearly'), bill('3', '0.05', 'UAH', 'yearly')],
        $quotes,
        '15.07.2026'
    );
    check(
        $fraction['uah'] === '0.01' && array_reduce($fraction['rows'], static fn($sum, $row) => bcadd($sum, $row['uah'], 2), '0.00') === '0.01',
        'Sub-cent monthly equivalents must round once with exact legend allocation.'
    );
    $fraction = SubscriptionOverview::build([bill('1', '0.01', 'USD')], ['USD' => '0.25', 'EUR' => '0.05'], '15.07.2026');
    check($fraction['uah'] === '0.00' && $fraction['eur'] === '0.05', 'The UAH intermediate must not be rounded before EUR conversion.');
    $weekly = SubscriptionOverview::build([bill('1', '10', 'UAH', 'weekly')], $quotes, '15.07.2026');
    check($weekly['uah'] === '43.48', 'Weekly equivalents must follow the native 52.17 payments per year.');
    $daily = SubscriptionOverview::build([bill('1', '1', 'UAH', 'daily')], $quotes, '15.07.2026');
    check($daily['uah'] === '30.44', 'Daily equivalents must follow the native 365.24 days per year.');
    check(
        SubscriptionOverview::build([bill('1', '120', 'UAH', 'half-year')], $quotes, '15.07.2026')['uah'] === '20.00',
        'Half-year frequency must be normalized.'
    );
    check(SubscriptionOverview::build([bill('1', '2', 'USDT')], $quotes, '15.07.2026')['uah'] === '90.00', 'USDT must use USD, not a stale manual quote.');
    $empty = SubscriptionOverview::build([], [], '15.07.2026');
    check(
        $empty['count'] === 0 && $empty['uah'] === '0.00' && $empty['eur'] === '0.00' && $empty['error'] === null,
        'An empty list must be safe without requesting rates.'
    );
    check(SubscriptionOverview::build([bill('1', '0')], [], '15.07.2026')['uah'] === '0.00', 'Zero-cost subscriptions must have a valid empty-chart state.');
    check(
        SubscriptionOverview::build([bill('1', 'not-money', 'BAD', 'unknown', 0, false)], [], '15.07.2026')['count'] === 0,
        'Inactive legacy rows must not affect active forecasts.'
    );
    foreach ([
        ['minimum' => '-1'],
        ['maximum' => '-2'],
        ['minimum' => '2', 'maximum' => '1'],
        ['frequency' => 'irregular'],
        ['skip' => -1],
        ['skip' => '1'],
        ['currency' => 'BAD'],
        ['currency' => null]
    ] as $patch) {
        rejects(static fn() => SubscriptionOverview::build([array_replace(bill('1', '1'), $patch)], $quotes, '15.07.2026'));
    }
    rejects(static fn() => SubscriptionOverview::build([bill('1', '1'), bill('1', '2')], $quotes, '15.07.2026'));
    rejects(static fn() => SubscriptionOverview::build([bill('1', '1', 'EUR')], ['EUR' => '0'], '15.07.2026'));
    rejects(static fn() => SubscriptionOverview::build([bill('1', '1')], [], '15.07.2026'));
    Bill::$rows = [];
    foreach ([1, 2] as $group) {
        Bill::$rows[] = (object) [
            'id'                  => $group,
            'user_group_id'       => $group,
            'active'              => true,
            'name'                => 'Scoped ' . $group,
            'amount_min'          => '10',
            'amount_max'          => '10',
            'transactionCurrency' => (object) ['code' => 'USD'],
            'repeat_freq'         => 'monthly',
            'skip'                => 0
        ];
    }
    $owned = SubscriptionOverview::forUser((object) ['user_group_id' => 1]);
    check($owned['error'] === null && $owned['count'] === 1 && $owned['uah'] === '450.00', 'The native query must scope both owner group and active state.');
    $patched = file_get_contents(dirname(__DIR__, 2) . '/resources/views/components/lists/subscriptions.blade.php');
    check(
        !str_contains($patched, '@if(array_key_exists($objectGroupOrder, $sums))') && !str_contains($patched, '@if(count($totals) > 0)'),
        'Only the old summary blocks must disappear.'
    );
    foreach ([
        "@foreach(\$objectGroup['bills'] as \$entry)",
        'bill-connected-list',
        'bill-sortable',
        "route('subscriptions.edit'",
        "route('subscriptions.delete'",
        'paid_dates',
        'next_expected_match'
    ] as $marker) {
        check(str_contains($patched, $marker), 'Native subscription rows, statuses and actions must remain.');
    }
    check(
        str_contains($patched, '<div class="table-responsive"><table') && substr_count($patched, '</table></div>') === 1,
        'Only the native table must scroll on narrow layouts.'
    );
    $view = file_get_contents(dirname(__DIR__, 2) . '/resources/views/bills/index.blade.php');
    $card = file_get_contents(dirname(__DIR__, 2) . '/resources/views/bills/subscription-overview.blade.php');
    check(
        str_contains($view, 'col-xl-8') && str_contains($view, 'col-xl-4') && str_contains($view, '<x-lists.subscriptions'),
        'The original list must remain left of the new summary.'
    );
    check(
        str_contains($view, "type: 'doughnut'")
        && str_contains($view, 'maintainAspectRatio: false')
        && str_contains($card, 'height: 320px')
        && str_contains($card, 'height: 280px'),
        'The chart must use bounded desktop/mobile containers.'
    );
    check(
        str_contains($card, "['uah']") && str_contains($card, "['eur']") && str_contains($card, 'Середня місячна'),
        'The card must expose both currencies and forecast meaning.'
    );
    echo 'Passed ' . $checks . " subscription overview checks.\n";
}
