<?php

declare(strict_types=1);

namespace Carbon {
    class Carbon extends \DateTimeImmutable
    {
        public function toAtomString(): string
        {
            return $this->format(DATE_ATOM);
        }
    }
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
        public static array  $quotes = ['UAH' => '1', 'EUR' => '50', 'USD' => '40', 'CHF' => '55'];
        public static string $day    = '2026-11-09';
        public static array  $calls  = [];

        public static function convert(string $amount, string $from, string $to): string
        {
            self::$calls[] = [$amount, $from, $to, self::$day];

            return CurrencyBridge::convert($amount, $from, $to, self::$quotes);
        }

        public static function currency(string $code): object
        {
            $values = ['EUR' => [1, '€'], 'USD' => [2, '$'], 'CHF' => [3, '₣'], 'UAH' => [4, '₴']];

            return (object) ['id' => $values[$code][0], 'code' => $code, 'name' => $code, 'symbol' => $values[$code][1], 'decimal_places' => 2];
        }

        public static function today(): \Carbon\Carbon
        {
            return new \Carbon\Carbon(self::$day, new \DateTimeZone('Europe/Kyiv'));
        }
    }
}

namespace {
    use Carbon\Carbon;
    use FireflyIII\Support\Reports\CategoryOverview;
    use FireflyIII\Support\Reports\ExpenseProjection;
    use FireflyIII\Support\Reports\ProfitSummary;
    use FireflyIII\Support\Reports\ReportingCurrency;

    require dirname(__DIR__, 2) . '/app/Support/Reports/CurrencyBridge.php';
    require dirname(__DIR__, 2) . '/app/Support/Reports/RefundProjection.php';
    require dirname(__DIR__, 2) . '/app/Support/Reports/ProfitSummary.php';
    require dirname(__DIR__, 2) . '/app/Support/Reports/ExpenseProjection.php';
    require dirname(__DIR__, 2) . '/app/Support/Reports/CategoryOverview.php';

    function trans(string $key): string
    {
        return 'Витрати';
    }

    $checks = 0;
    function check(bool $value, string $message): void
    {
        if (!$value) {
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

    function row(string $id, string $amount, string $currency, null|string $category, array $extra = []): array
    {
        return array_replace([
            'transaction_journal_id' => $id,
            'amount'                 => $amount,
            'currency_code'          => $currency,
            'category_name'          => $category,
            'date'                   => '2026-11-04',
            'transaction_type_type'  => 'Withdrawal',
            'source_account_id'      => 'personal',
            'destination_account_id' => 'merchant',
            'user_group_id'          => 'owner',
            'pc_amount'              => '-999999.99',
            'foreign_amount'         => '-888888.88'
        ], $extra);
    }

    function entries(array $data): array
    {
        $result = [];
        foreach ($data as $row) {
            $result[$row['label']] = $row['entries']['spent'];
        }

        return $result;
    }

    $start = new Carbon('2026-11-01', new DateTimeZone('Europe/Kyiv'));
    $end   = new Carbon('2026-11-09', new DateTimeZone('Europe/Kyiv'));
    $owned = ['personal', 'fop', 'eurbank', 'cash'];
    $rows  = [
        row('tax', '-18000', 'UAH', 'Податки ФОП', ['source_account_id' => 'fop']),
        row('eur-food', '-10', 'EUR', 'Продукти', ['source_account_id' => 'eurbank']),
        row('local-food', '-100', 'UAH', 'Продукти'),
        row('food-refund', '-2', 'EUR', 'Повернення коштів', [
            'transaction_type_type'  => 'Deposit',
            'source_account_id'      => 'merchant',
            'destination_account_id' => 'eurbank',
            'is_refund'              => true,
            'refund_original_id'     => 'eur-food',
            'refund_category_name'   => 'Продукти'
        ]),
        row('usd-shop', '-3', 'USD', 'Покупки'),
        row('unknown', '-20', 'UAH', null),
        row('legacy-unknown', '-30', 'UAH', 'Потребує уточнення'),
        row('blank', '-5', 'UAH', '  '),
        row('refund-only', '-4', 'UAH', 'Повернення коштів', [
            'transaction_type_type'  => 'Deposit',
            'source_account_id'      => 'merchant',
            'destination_account_id' => 'personal',
            'is_refund'              => true,
            'refund_original_id'     => 'old-education',
            'refund_category_name'   => 'Освіта'
        ]),
        row('own-transfer', '-5000', 'UAH', 'Перекази', ['transaction_type_type' => 'Transfer', 'destination_account_id' => 'fop']),
        row('own-withdrawal', '-1000', 'UAH', 'Перекази', ['destination_account_id' => 'fop']),
        row('other-owner', '-777', 'UAH', 'Інший власник', ['user_group_id' => 'other']),
        row('income', '-1000', 'USD', 'Дохід ФОП', ['transaction_type_type' => 'Deposit', 'source_account_id' => 'client', 'destination_account_id' => 'fop']),
        row('foreign-source', '-999', 'UAH', 'Помилковий рахунок', ['source_account_id' => 'outsider']),
        row('foreign-refund', '-999', 'UAH', 'Повернення коштів', [
            'transaction_type_type'  => 'Deposit',
            'is_refund'              => true,
            'destination_account_id' => 'outsider'
        ]),
        row('old-tax', '-1000', 'UAH', 'Податки ФОП', ['date' => '2026-10-31']),
        row('kyiv-edge', '-1', 'UAH', null, ['date' => '2026-10-31T23:30:00Z']),
        row('after-period', '-1000', 'UAH', 'Податки ФОП', ['date' => '2026-11-10'])
    ];
    $before  = serialize($rows);
    $data    = CategoryOverview::build($rows, $owned, $start, $end, 'EUR', 'owner');
    $amounts = entries($data);
    check(
        $amounts === ['Податки ФОП' => '18000.00', 'Продукти' => '500.00', 'Покупки' => '120.00', 'Інше' => '56.00', 'Освіта' => '-4.00'],
        'Each category must reconcile with independently expected UAH spending.'
    );
    check(count($data) === 5, 'Deposits, own transfers, external owners and out-of-period rows must create no category.');
    foreach ($data as $dataset) {
        check(
            $dataset['currency_code'] === 'UAH' && $dataset['currency_symbol'] === '₴' && $dataset['currency_id'] === '4',
            'Every category axis and dataset has actual UAH metadata.'
        );
        check($dataset['primary_currency_code'] === 'EUR' && $dataset['primary_currency_symbol'] === '€', 'The administration primary currency remains EUR.');
        check($dataset['entries']['earned'] === '0.00' && $dataset['pc_entries']['earned'] === '0.00', 'The expense chart does not introduce ordinary income.');
        check($dataset['as_of'] === '2026-11-09' && $dataset['reporting_currency_code'] === 'UAH', 'The chart records the current valuation date.');
    }
    check(
        $data[0]['entries']['spent'] === '18000.00' && $data[0]['pc_entries']['spent'] === '360.00',
        'A native UAH tax amount must never be labelled EUR; its separate primary equivalent is converted.'
    );
    $nativeCleaner = dirname(__DIR__, 2) . '/app/Support/Http/Api/CleansChartData.php';
    if (is_file($nativeCleaner)) {
        require $nativeCleaner;
        $cleaner = new class {
            use \FireflyIII\Support\Http\Api\CleansChartData;

            public function validate(array $rows): array
            {
                return $this->clean($rows);
            }
        };
        check($cleaner->validate($data) === $data, 'The real native API cleaner accepts every projected dataset without altering units.');
    }
    check($amounts['Освіта'] === '-4.00', 'A refund without a current-period charge remains a negative category credit.');
    check(!isset($amounts['Повернення коштів']), 'Linked refunds offset their original category instead of making a new expense.');
    check(serialize($rows) === $before, 'Chart projection preserves the source amounts and historical primary fields.');
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
            'source_id'      => $row['source_account_id'],
            'destination_id' => $row['destination_account_id'],
            'day'            => $day,
            'currency'       => $row['currency_code']
        ];
    }
    $ledger = ProfitSummary::build($ledgerRows, ReportingCurrency::$quotes, $owned, '2026-11-01', '2026-11-09', 'UAH');
    $total  = array_reduce($amounts, static fn(string $sum, string $value): string => bcadd($sum, $value, 2), '0.00');
    check($total === '18672.00' && $total === $ledger['totals']['expenses'], 'Sum of all categories must equal the UAH ledger including refund credits.');
    $selected = CategoryOverview::select($rows, $owned, '2026-11-01', '2026-11-09', 'owner');
    $legacy   = ExpenseProjection::categoryChartFrom($selected)[0];
    check(
        $legacy['currency_code'] === 'UAH' && $legacy['currency_symbol'] === '₴' && $legacy['entries'] === $amounts,
        'Legacy frontpage and native category overview use exactly the same UAH ledger values.'
    );
    check(CategoryOverview::build([], $owned, $start, $end, 'EUR', 'owner') === [], 'An empty period returns no invented dataset.');
    check(CategoryOverview::build($rows, [], $start, $end, 'EUR', 'owner') === [], 'An empty owned-account set can never broaden the query.');
    check(
        CategoryOverview::overview(new \Illuminate\Support\Collection(), $start, $end, new stdClass(), new stdClass(), 'EUR') === [],
        'Empty accounts skip all collector, refund and database dependencies.'
    );
    check(entries(CategoryOverview::build($rows, $owned, $end, $start, 'EUR', 'owner')) === $amounts, 'Reverse date boundaries retain the same categories.');
    rejects(fn() => CategoryOverview::build(
        [$rows[0], $rows[0]],
        $owned,
        $start,
        $end,
        'EUR',
        'owner'
    ), 'Duplicate journals must fail closed rather than double the chart.');
    ReportingCurrency::$quotes['EUR'] = '60';
    ReportingCurrency::$day           = '2026-11-10';
    $fresh                            = CategoryOverview::build([$rows[1]], $owned, $start, $end, 'EUR', 'owner');
    check(
        $fresh[0]['entries']['spent'] === '600.00' && $fresh[0]['as_of'] === '2026-11-10',
        'Regeneration uses today\'s bridge instead of transaction-day or saved primary amounts.'
    );
    ReportingCurrency::$quotes = ['UAH' => '1', 'EUR' => '50'];
    rejects(fn() => CategoryOverview::build(
        [row('missing', '-1', 'CHF', 'Продукти')],
        $owned,
        $start,
        $end,
        'EUR',
        'owner'
    ), 'Missing NBU quotes cannot become guessed category amounts.');
    echo 'Passed ' . $checks . " category chart checks.\n";
}
