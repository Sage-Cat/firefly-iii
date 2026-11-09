<?php

declare(strict_types=1);

namespace FireflyIII\Support\Reports;

use Carbon\Carbon;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use FireflyIII\Enums\TransactionTypeEnum;
use FireflyIII\Helpers\Collector\GroupCollectorInterface;
use FireflyIII\Models\Account;
use FireflyIII\Models\Category;
use FireflyIII\Models\Tag;
use FireflyIII\Models\TransactionCurrency;
use FireflyIII\Repositories\Account\AccountRepositoryInterface;
use FireflyIII\Repositories\Category\CategoryRepositoryInterface;
use FireflyIII\Repositories\Tag\TagRepositoryInterface;
use FireflyIII\Support\Facades\Navigation;
use FireflyIII\Support\Facades\Steam;
use Illuminate\Support\Collection;
use RuntimeException;

final class ExpenseProjection
{
    public static function accountChart(Carbon $start, Carbon $end, Collection $accounts): array
    {
        if ($accounts->isEmpty()) {
            return self::accountChartFromBalances([], [], []);
        }
        [$first, $last] = Steam::accountsBalancesInRange($accounts, $start, $end, ReportingCurrency::currency('EUR'), false);

        return self::accountChartFromBalances(
            $accounts->pluck('name', 'id')->all(),
            $first,
            $last,
            RefundProjection::merchantCredits(RefundProjection::metadata(auth()->user()), $start->format('Y-m-d'), $end->format('Y-m-d'))
        );
    }

    public static function accountChartFromBalances(array $names, array $startBalances, array $endBalances, array $refundCredits = []): array
    {
        $entries = [];
        foreach ($names as $id => $name) {
            $total = bcsub(self::nativeBalanceUAH($endBalances[$id] ?? [], $startBalances[$id] ?? []), $refundCredits[$id] ?? '0.00', 2);
            if (0 !== bccomp($total, '0', 2)) {
                $entries[$name] = bcadd($entries[$name] ?? '0.00', $total, 2);
            }
        }
        uasort($entries, static fn(string $left, string $right): int => bccomp($right, $left, 2));
        $currency = ReportingCurrency::currency('UAH');

        return [['label' => trans('firefly.spent'), 'type' => 'bar', 'currency_symbol' => $currency->symbol, 'currency_code' => 'UAH', 'entries' => $entries]];
    }

    public static function amount(array $row, string $target = 'UAH', bool $positive = false): array
    {
        $code     = self::sourceCode($row);
        $currency = ReportingCurrency::currency($target);
        $original = ReportingCurrency::currency($code);
        $value    = ReportingCurrency::convert((string) $row['amount'], $code, $target);
        if ($positive && bccomp($value, '0', 2) < 0) {
            $value = bcsub('0', $value, 2);
        }

        return [
            'amount'                           => $value,
            'currency_id'                      => $currency->id,
            'currency_name'                    => $currency->name,
            'currency_symbol'                  => $currency->symbol,
            'currency_code'                    => $currency->code,
            'currency_decimal_places'          => $currency->decimal_places,
            'original_amount'                  => (string) $row['amount'],
            'original_currency_code'           => $code,
            'original_currency_symbol'         => $original->symbol,
            'original_currency_decimal_places' => $original->decimal_places
        ];
    }

    public static function assetCategoryPie(Account $account, Carbon $start, Carbon $end): array
    {
        $data   = self::categoryChartFrom(self::withdrawals($start, $end, $account))[0];
        $result = [];
        foreach ($data['entries'] as $name => $amount) {
            $result[$name] = ['amount' => $amount, 'currency_symbol' => $data['currency_symbol'], 'currency_code' => 'UAH'];
        }

        return $result;
    }

    public static function categoryChart(Carbon $start, Carbon $end): array
    {
        return self::categoryChartFrom(self::withdrawals($start, $end));
    }

    public static function categoryChartFrom(array $journals): array
    {
        $entries = [];
        foreach ($journals as $journal) {
            if ('Withdrawal' !== $journal['transaction_type_type'] && !RefundProjection::isRefund($journal)) {
                continue;
            }
            $name = RefundProjection::isRefund($journal) && null !== ($journal['refund_original_id'] ?? null)
                ? $journal['refund_category_name'] ?? ''
                : $journal['category_name'] ?? '';
            $name           = trim((string) $name);
            $name           = in_array($name, ['', 'Потребує уточнення'], true) ? 'Інше' : $name;
            $entries[$name] = bcadd($entries[$name] ?? '0.00', self::spending($journal), 2);
        }
        uasort($entries, static fn(string $left, string $right): int => bccomp($right, $left, 2));
        $currency = ReportingCurrency::currency('UAH');

        return [['label' => trans('firefly.spent'), 'type' => 'bar', 'currency_symbol' => $currency->symbol, 'currency_code' => 'UAH', 'entries' => $entries]];
    }

    public static function categoryHistory(Category $category, Carbon $start, Carbon $end, string $step): array
    {
        [$start, $end] = $end < $start ? [$end, $start] : [$start, $end];
        $repository = app(CategoryRepositoryInterface::class);
        $repository->setUser(auth()->user());
        $journals = $repository->periodCollection($category, $start->copy()->subDay()->startOfDay(), $end->copy()->addDay()->endOfDay());

        $journals = RefundProjection::annotate($journals, RefundProjection::metadata(auth()->user()));
        $seen     = array_fill_keys(array_map(static fn(array $row): string => (string) ($row['transaction_journal_id'] ?? $row['id'] ?? ''), $journals), true);
        foreach (self::allJournals($start->copy()->subDay()->startOfDay(), $end->copy()->addDay()->endOfDay(), ['Deposit']) as $refund) {
            if (
                RefundProjection::isRefund($refund)
                && (string) ($refund['refund_category_id'] ?? '') === (string) $category->id
                && !isset($seen[(string) ($refund['transaction_journal_id'] ?? $refund['id'] ?? '')])
            ) {
                $journals[] = $refund;
            }
        }

        return self::categoryHistoryFrom($journals, self::historyBlocks($start, $end, $step));
    }

    public static function categoryHistoryFrom(array $journals, array $blocks): array
    {
        $currency = ReportingCurrency::currency('UAH');
        $entries  = array_fill_keys(array_column($blocks, 'label'), '0.00');
        $series   = [];
        foreach (['Withdrawal' => ['spent', 'rgba(219, 68, 55, 0.5)'], 'Deposit' => ['earned', 'rgba(0, 141, 76, 0.5)']] as $type => [$label, $colour]) {
            $series[$type] = [
                'label'           => trans('firefly.' . $label) . ' (UAH)',
                'entries'         => $entries,
                'type'            => 'bar',
                'backgroundColor' => $colour,
                'currency_code'   => 'UAH',
                'currency_symbol' => $currency->symbol
            ];
        }
        foreach ($journals as $journal) {
            $type = RefundProjection::isRefund($journal) ? 'Withdrawal' : $journal['transaction_type_type'] ?? $journal['type'];
            if (!isset($series[$type])) {
                continue;
            }
            $day = self::day($journal);
            foreach ($blocks as $block) {
                if ($day >= $block['start'] && $day <= $block['end']) {
                    $label                            = $block['label'];
                    $value                            = 'Withdrawal' === $type ? self::spending($journal) : self::amount($journal, 'UAH', true)['amount'];
                    $series[$type]['entries'][$label] = bcadd($series[$type]['entries'][$label], $value, 2);
                    break;
                }
            }
        }

        return array_values($series);
    }

    public static function categoryReport(Collection $accounts, Carbon $start, Carbon $end, null|Category $category): array
    {
        [$start, $end] = $end < $start ? [$end, $start] : [$start, $end];
        $blocks = self::historyBlocks($start, $end, Navigation::preferredRangeFormat($start, $end));
        if ($accounts->isEmpty()) {
            return self::categoryHistoryFrom([], $blocks);
        }
        $collector = app(GroupCollectorInterface::class)
            ->setUser(auth()->user())
            ->setAccounts($accounts)
            ->setRange($start->copy()->subDay()->startOfDay(), $end->copy()->addDay()->endOfDay())
            ->setTypes(['Withdrawal', 'Deposit'])
            ->withAccountInformation();
        if (null === $category) {
            $collector->withoutCategory();
        } else {
            $collector->setCategories(new Collection()->push($category));
        }

        $rows = RefundProjection::annotate($collector->getExtractedJournals(), RefundProjection::metadata(auth()->user()));
        $seen = array_fill_keys(array_map(static fn(array $row): string => (string) ($row['transaction_journal_id'] ?? $row['id'] ?? ''), $rows), true);
        foreach (self::allJournals($start->copy()->subDay()->startOfDay(), $end->copy()->addDay()->endOfDay(), ['Deposit'], null, $accounts) as $refund) {
            $matches = null === $category ? empty($refund['refund_category_id']) : (string) ($refund['refund_category_id'] ?? '') === (string) $category->id;
            if (RefundProjection::isRefund($refund) && $matches && !isset($seen[(string) $refund['transaction_journal_id']])) {
                $rows[] = $refund;
            }
        }

        return self::categoryHistoryFrom($rows, $blocks);
    }

    public static function detailAmounts(array $group): array
    {
        $total = self::groupAmount($group);

        return [[
            'amount'         => $total['amount'],
            'symbol'         => $total['currency_symbol'],
            'decimal_places' => $total['currency_decimal_places'],
            'approximate'    => false
        ]];
    }

    public static function expenseRequest(): bool
    {
        $type = (string) request()->route('objectType', '');
        $path = request()->path();

        return (
            in_array($type, ['withdrawal', 'withdrawals', 'expenses'], true)
            || 1 === preg_match('~(?:^|/)(?:expense(?:s)?(?:-|/|$)|withdrawals?(?:/|$))~', $path)
            || in_array($path, ['chart/category/frontpage', 'chart/transactions/budgets'], true)
            || str_starts_with($path, 'chart/transactions/budgets/')
        );
    }

    public static function genericPeriods(string $type, Carbon $start, Carbon $end): array
    {
        $fields = [
            'withdrawal' => 'spent',
            'expenses'   => 'spent',
            'deposit'    => 'earned',
            'revenue'    => 'earned',
            'transfer'   => 'transferred',
            'transfers'  => 'transferred',
            'all'        => 'all'
        ];
        $types = config('firefly.transactionTypesByType.' . $type);
        if (!isset($fields[$type]) || !is_array($types) || [] === $types) {
            throw new RuntimeException('Невідомий тип періоду операцій.');
        }
        $blocks = self::blocks($start, $end);
        [$first, $last] = self::bounds($blocks, $start, $end);
        $journals = self::allJournals($first, $last, $types);
        $target   = 'spent' === $fields[$type] ? 'UAH' : 'EUR';
        $entries  = [];
        foreach ($blocks as $block) {
            $set       = self::periodGroupFrom($journals, $block['start']->format('Y-m-d'), $block['end']->format('Y-m-d'), $target, $types);
            $entries[] = [
                'title'              => Navigation::periodShow($block['start'], $block['period']),
                'route'              => route('transactions.index', [$type, $block['start']->format('Y-m-d'), $block['end']->format('Y-m-d')]),
                'total_transactions' => $set['count'],
                $fields[$type]       => $set
            ];
        }

        return $entries;
    }

    public static function groupAmount(array $group): array
    {
        $total = '0.00';
        foreach ($group['transactions'] as $journal) {
            if ('Withdrawal' !== $journal['transaction_type_type']) {
                continue;
            }
            $total = bcadd($total, self::amount($journal)['amount'], 2);
        }
        $currency = ReportingCurrency::currency('UAH');

        return ['amount' => $total, 'currency_symbol' => $currency->symbol, 'currency_decimal_places' => $currency->decimal_places, 'currency_code' => 'UAH'];
    }

    public static function merchantDisplay(Carbon $start, Carbon $end): array
    {
        $repository = app(AccountRepositoryInterface::class);
        $repository->setUser(auth()->user());
        $accounts = $repository->getAccountsByType(config('firefly.accountTypesByIdentifier.expense'));
        if ($accounts->isEmpty()) {
            return [];
        }
        $currency = ReportingCurrency::currency('EUR');
        $current  = Steam::accountsBalancesOptimized($accounts, now('Europe/Kyiv'), $currency, false);
        [$first, $last] = Steam::accountsBalancesInRange($accounts, $start->copy()->startOfDay(), $end->copy()->endOfDay(), $currency, false);

        $refunds = RefundProjection::metadata(auth()->user());

        return self::merchantDisplayFromBalances(
            $current,
            $first,
            $last,
            RefundProjection::merchantCredits($refunds, null, ReportingCurrency::today()->format('Y-m-d')),
            RefundProjection::merchantCredits($refunds, $start->format('Y-m-d'), $end->format('Y-m-d'))
        );
    }

    public static function merchantDisplayFromBalances(array $current, array $start, array $end, array $currentRefunds = [], array $periodRefunds = []): array
    {
        $currency = ReportingCurrency::currency('UAH');
        $result   = [];
        foreach (array_unique(array_merge(array_keys($current), array_keys($start), array_keys($end))) as $id) {
            $balance    = bcsub(self::nativeBalanceUAH($current[$id] ?? []), $currentRefunds[$id] ?? '0.00', 2);
            $difference = bcsub(self::nativeBalanceUAH($end[$id] ?? [], $start[$id] ?? []), $periodRefunds[$id] ?? '0.00', 2);
            if (Steam::anonymous()) {
                $balance = $difference = '0.00';
            }
            $result[$id] = [
                'current_balance'              => $balance,
                'current_balance_sign'         => bccomp($balance, '0', 2),
                'current_balance_formatted'    => format_amount_by_symbol($balance, $currency->symbol, $currency->decimal_places, false),
                'balance_difference'           => $difference,
                'balance_difference_sign'      => bccomp($difference, '0', 2),
                'balance_difference_formatted' => format_amount_by_symbol($difference, $currency->symbol, $currency->decimal_places, false),
                'currency_code'                => $currency->code,
                'as_of'                        => ReportingCurrency::today()->format('Y-m-d')
            ];
        }

        return $result;
    }

    public static function modelPeriods(object $model, Carbon $start, Carbon $end): array
    {
        $repositoryClass = match ($model::class) {
            Account::class  => AccountRepositoryInterface::class,
            Category::class => CategoryRepositoryInterface::class,
            Tag::class      => TagRepositoryInterface::class,
            default         => throw new RuntimeException('Невідомий тип облікового періоду.')
        };
        $repository = app($repositoryClass);
        $repository->setUser(auth()->user());
        $blocks = self::blocks($start, $end);
        [$first, $last] = self::bounds($blocks, $start, $end);
        $journals = RefundProjection::annotate($repository->periodCollection($model, $first, $last), RefundProjection::metadata(auth()->user()));
        if ($model instanceof Category || $model instanceof Account) {
            $seen = array_fill_keys(array_map(static fn(array $row): string => (string) ($row['transaction_journal_id'] ?? $row['id'] ?? ''), $journals), true);
            foreach (self::allJournals($first, $last, ['Deposit']) as $refund) {
                $matches = $model instanceof Category
                    ? (string) ($refund['refund_category_id'] ?? '') === (string) $model->id
                    : (string) ($refund['refund_merchant_id'] ?? '') === (string) $model->id;
                if (RefundProjection::isRefund($refund) && $matches && !isset($seen[(string) $refund['transaction_journal_id']])) {
                    $journals[] = $refund;
                }
            }
        }
        $route = match ($model::class) {
            Account::class  => 'accounts.show',
            Category::class => 'categories.show',
            Tag::class      => 'tags.show'
        };
        $entries = [];
        foreach ($blocks as $block) {
            $entry = [
                'title'              => Navigation::periodShow($block['start'], $block['period']),
                'route'              => route($route, [$model->id, $block['start']->format('Y-m-d'), $block['end']->format('Y-m-d')]),
                'total_transactions' => 0
            ];
            foreach ([
                'spent'            => ['Withdrawal', 'UAH', null],
                'earned'           => ['Deposit', 'EUR', null],
                'transferred_in'   => ['Transfer', 'EUR', 'in'],
                'transferred_away' => ['Transfer', 'EUR', 'away']
            ] as $field => [$type, $target, $direction]) {
                $set = self::periodGroupFrom($journals, $block['start']->format('Y-m-d'), $block['end']->format('Y-m-d'), $target, [$type], $direction);

                $entry['total_transactions'] += $set['count'];
                unset($set['count']);
                $entry[$field] = $set;
            }
            $entries[] = $entry;
        }

        return $entries;
    }

    public static function nativeBalanceUAH(array $balances, array $before = []): string
    {
        $total = '0.00';
        foreach (array_unique(array_merge(array_keys($balances), array_keys($before))) as $code) {
            if (in_array($code, ['balance', 'pc_balance'], true)) {
                continue;
            }
            if (!preg_match('/^[A-Z]{3,5}$/D', (string) $code)) {
                throw new RuntimeException('Невідома валюта підсумку витрат.');
            }
            $difference = bcsub((string) ($balances[$code] ?? '0'), (string) ($before[$code] ?? '0'), 12);
            $total      = bcadd($total, ReportingCurrency::convert($difference, $code, 'UAH'), 2);
        }

        return $total;
    }

    public static function noModelPeriods(string $model, Carbon $start, Carbon $end): array
    {
        if (!in_array($model, ['budget', 'category'], true)) {
            throw new RuntimeException('Невідомий тип періоду без категорії чи бюджету.');
        }
        $types  = 'budget' === $model ? ['Withdrawal'] : ['Withdrawal', 'Deposit', 'Transfer'];
        $blocks = self::blocks($start, $end);
        [$first, $last] = self::bounds($blocks, $start, $end);
        $journals = self::allJournals($first, $last, $types, $model);
        $entries  = [];
        foreach ($blocks as $block) {
            $entry = [
                'title'              => Navigation::periodShow($block['end'], $block['period']),
                'route'              => route($model . 's.no-' . $model, [$block['start']->format('Y-m-d'), $block['end']->format('Y-m-d')]),
                'total_transactions' => 0
            ];
            foreach (['spent' => ['Withdrawal', 'UAH'], 'earned' => ['Deposit', 'EUR'], 'transferred' => ['Transfer', 'EUR']] as $field => [$type, $target]) {
                $set = self::periodGroupFrom($journals, $block['start']->format('Y-m-d'), $block['end']->format('Y-m-d'), $target, [$type]);

                $entry['total_transactions'] += $set['count'];
                $entry[$field]               = $set;
            }
            $entries[] = $entry;
        }

        return $entries;
    }

    public static function periodGroupFrom(array $journals, string $start, string $end, string $target, array $types, null|string $direction = null): array
    {
        $sum   = '0.00';
        $count = 0;
        foreach ($journals as $journal) {
            $type   = $journal['transaction_type_type'] ?? $journal['type'];
            $refund = RefundProjection::isRefund($journal);
            if (
                !in_array($type, $types, true) && !($refund && in_array('Withdrawal', $types, true))
                || $refund && !in_array('Withdrawal', $types, true)
                || self::day($journal) < $start
                || self::day($journal) > $end
            ) {
                continue;
            }
            if ('in' === $direction && bccomp((string) $journal['amount'], '0', 12) <= 0) {
                continue;
            }
            if ('away' === $direction && bccomp((string) $journal['amount'], '0', 12) >= 0) {
                continue;
            }
            $value = self::amount($journal, $target)['amount'];
            if ($refund || 'Withdrawal' === $type) {
                $value = bcsub('0', self::spending($journal, $target), 2);
            }
            $sum = bcadd($sum, $value, 2);
            ++$count;
        }
        if (0 === $count) {
            return ['count' => 0];
        }
        $currency = ReportingCurrency::currency($target);

        return [
            'count'       => $count,
            $currency->id => [
                'amount'                  => $sum,
                'count'                   => $count,
                'currency_id'             => $currency->id,
                'currency_name'           => $currency->name,
                'currency_code'           => $target,
                'currency_symbol'         => $currency->symbol,
                'currency_decimal_places' => $currency->decimal_places
            ]
        ];
    }

    public static function periodTotals(Carbon $start, Carbon $end): array
    {
        return self::periodTotalsFrom(self::withdrawals($start, $end), $start->format('Y-m-d'), $end->format('Y-m-d'));
    }

    public static function periodTotalsFrom(array $journals, string $start, string $end): array
    {
        return self::periodGroupFrom($journals, $start, $end, 'UAH', ['Withdrawal']);
    }

    public static function resolve(array $journal, null|array $currency, string $fallback): array
    {
        return self::amount($journal + ($currency ?? []), self::expenseRequest() ? 'UAH' : $fallback, true);
    }

    public static function spending(array $row, string $target = 'UAH'): string
    {
        $amount = self::amount($row, $target, true)['amount'];

        return RefundProjection::isRefund($row) ? bcsub('0', $amount, 2) : $amount;
    }

    public static function withdrawals(Carbon $start, Carbon $end, null|Account $account = null): array
    {
        $repository = app(AccountRepositoryInterface::class);
        $repository->setUser(auth()->user());
        $assets = $repository->getAccountsByType(config('firefly.accountTypesByIdentifier.asset'));
        if ($assets->isEmpty()) {
            return [];
        }
        $owned     = array_fill_keys(array_map('strval', $assets->pluck('id')->all()), true);
        $collector = app(GroupCollectorInterface::class);
        $journals  = $collector
            ->setUser(auth()->user())
            ->setAccounts($assets)
            ->setRange($start->copy()->subDay()->startOfDay(), $end->copy()->addDay()->endOfDay())
            ->setTypes([TransactionTypeEnum::WITHDRAWAL->value, 'Deposit'])
            ->withAccountInformation()
            ->withCategoryInformation()
            ->withBudgetInformation()
            ->getExtractedJournals();
        $journals = RefundProjection::annotate($journals, RefundProjection::metadata(auth()->user()));
        $first    = $start->format('Y-m-d');
        $last     = $end->format('Y-m-d');

        return array_values(array_filter($journals, static function (array $row) use ($owned, $account, $first, $last): bool {
            $source      = (string) $row['source_account_id'];
            $destination = (string) $row['destination_account_id'];

            $withdrawal = 'Withdrawal' === $row['transaction_type_type'] && isset($owned[$source]) && !isset($owned[$destination]);
            $refund     = RefundProjection::isRefund($row) && isset($owned[$destination]) && !isset($owned[$source]);

            return (
                ($withdrawal || $refund)
                && self::day($row) >= $first
                && self::day($row) <= $last
                && (null === $account || (string) $account->id === $source || (string) $account->id === $destination)
            );
        }));
    }

    private static function allJournals(Carbon $start, Carbon $end, array $types, null|string $without = null, null|Collection $requestedAccounts = null): array
    {
        $repository = app(AccountRepositoryInterface::class);
        $repository->setUser(auth()->user());
        $assets = $repository->getAccountsByType(config('firefly.accountTypesByIdentifier.asset'));
        if ($assets->isEmpty()) {
            return [];
        }
        if (null !== $requestedAccounts) {
            $selected = array_fill_keys(array_map('strval', $requestedAccounts->pluck('id')->all()), true);
            $assets   = $assets->filter(static fn($account): bool => isset($selected[(string) $account->id]));
            if ($assets->isEmpty()) {
                return [];
            }
        }
        if (in_array('Withdrawal', $types, true) && !in_array('Deposit', $types, true)) {
            $types[] = 'Deposit';
        }
        $collector = app(GroupCollectorInterface::class)
            ->setUser(auth()->user())
            ->setAccounts($assets)
            ->setRange($start, $end)
            ->setTypes($types)
            ->withAccountInformation()
            ->withCategoryInformation()
            ->withBudgetInformation();
        $rows = RefundProjection::annotate($collector->getExtractedJournals(), RefundProjection::metadata(auth()->user()));
        if (null !== $without) {
            $rows = array_values(array_filter($rows, static function (array $row) use ($without): bool {
                if ('category' === $without) {
                    return empty(RefundProjection::isRefund($row) && null !== ($row['refund_original_id'] ?? null)
                        ? $row['refund_category_id'] ?? null
                        : $row['category_id'] ?? null);
                }

                return empty(RefundProjection::isRefund($row) ? $row['refund_budget_ids'] ?? [] : $row['budget_id'] ?? null);
            }));
        }

        return $rows;
    }

    private static function blocks(Carbon $start, Carbon $end): array
    {
        [$start, $end] = $end < $start ? [$end, $start] : [$start, $end];

        return Navigation::blockPeriods($start, $end, Navigation::getViewRange(true));
    }

    private static function bounds(array $blocks, Carbon $start, Carbon $end): array
    {
        foreach ($blocks as $block) {
            if ($block['start'] < $start) {
                $start = $block['start'];
            }
            if ($block['end'] > $end) {
                $end = $block['end'];
            }
        }

        return [$start->copy()->subDay()->startOfDay(), $end->copy()->addDay()->endOfDay()];
    }

    private static function day(array $row): string
    {
        $value = $row['date'] ?? $row['day'];
        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value)->setTimezone(new DateTimeZone('Europe/Kyiv'))->format('Y-m-d');
        }

        return new DateTimeImmutable((string) $value, new DateTimeZone('UTC'))
            ->setTimezone(new DateTimeZone('Europe/Kyiv'))
            ->format('Y-m-d');
    }

    private static function historyBlocks(Carbon $start, Carbon $end, string $step): array
    {
        $blocks  = [];
        $current = $start->copy();
        while ($current <= $end) {
            $last     = Navigation::endOfPeriod($current, $step);
            $last     = $last > $end ? $end : $last;
            $blocks[] = ['start' => $current->format('Y-m-d'), 'end' => $last->format('Y-m-d'), 'label' => Navigation::periodShow($current, $step)];
            $current  = Navigation::addPeriod($current, $step);
        }

        return $blocks;
    }

    private static function sourceCode(array $row): string
    {
        if (isset($row['currency_code']) && '' !== $row['currency_code']) {
            return (string) $row['currency_code'];
        }
        $currency = TransactionCurrency::query()->find($row['currency_id'] ?? null);
        if (null === $currency) {
            throw new RuntimeException('Не знайдено валюту вихідної операції.');
        }

        return $currency->code;
    }
}
