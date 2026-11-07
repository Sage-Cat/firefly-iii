<?php

declare(strict_types=1);

namespace FireflyIII\Support\Reports;

use Carbon\Carbon;
use FireflyIII\Enums\TransactionTypeEnum;
use FireflyIII\Helpers\Collector\GroupCollectorInterface;
use FireflyIII\Repositories\Account\AccountRepositoryInterface;
use FireflyIII\Repositories\Budget\AvailableBudgetRepositoryInterface;
use FireflyIII\Repositories\Budget\BudgetLimitRepositoryInterface;
use FireflyIII\Repositories\Budget\BudgetRepositoryInterface;
use FireflyIII\Support\Facades\Amount;
use FireflyIII\Support\Facades\Navigation;
use FireflyIII\Support\Facades\Steam;
use Illuminate\Support\Collection;
use RuntimeException;

final class BudgetProjection
{
    public static function apiOverview($user, Carbon $start, Carbon $end, bool $byLimits): array
    {
        [$repository, $limitRepository] = self::repositories($user);
        $currency = ReportingCurrency::currency('UAH');
        $return   = [];
        foreach ($repository->getActiveBudgets() as $budget) {
            $limits     = $limitRepository->getBudgetLimits($budget, $start, $end);
            $projection = self::projectBudget($user, $budget, $limits, $start, $end);
            if (!$byLimits) {
                $return[] = self::apiRow($budget->name, $projection['display_amount'], $projection['display_spent'], $start, $end, $currency);
                continue;
            }
            foreach ($projection['budgeted'] as $limit) {
                $first    = Carbon::parse($limit['projection_start'], 'Europe/Kyiv');
                $last     = Carbon::parse($limit['projection_end'], 'Europe/Kyiv')->endOfDay();
                $label    = $budget->name . ' (' . $limit['projection_start'] . ' — ' . $limit['projection_end'] . ')';
                $return[] = self::apiRow($label, $limit['display_amount'], $limit['display_spent'], $first, $last, $currency);
            }
            if (!$projection['budgeted'] || 0 !== bccomp($projection['display_outside'], '0', 2)) {
                $return[] = self::apiRow(
                    $budget->name . ' — ' . trans('firefly.spent_outside_chart'),
                    '0.00',
                    $projection['display_outside'],
                    $start,
                    $end,
                    $currency
                );
            }
        }

        return $return;
    }

    public static function budgetPie($user, $budget, $limit, string $dimension, $generator): array
    {
        self::assertLimit($budget, $limit);
        $start = $limit?->start_date ?? session('first', ReportingCurrency::today()->startOfYear());
        $end   = $limit?->end_date ?? ReportingCurrency::today();

        return self::pie($user, $dimension, [(string) $budget->id], $start, $end, $generator);
    }

    public static function history(
        $user,
        $budget,
        Carbon $start,
        Carbon $end,
        string $step,
        $generator,
        null|Collection $accounts = null,
        bool $caps = false
    ): array {
        [, $limitRepository] = self::repositories($user);
        $rows     = self::collect($user, $start, $end, $accounts, [(string) $budget->id]);
        $spent    = [];
        $budgeted = [];
        $current  = Navigation::startOfPeriod($start->copy(), $step);
        while ($current <= $end) {
            $last          = Navigation::endOfPeriod($current->copy(), $step);
            $label         = trim(Navigation::periodShow($current, $step));
            $spent[$label] = bcmul(self::sum($rows, $current->format('Y-m-d'), $last->format('Y-m-d'), [(string) $budget->id]), '-1', 2);
            if ($caps) {
                $budgeted[$label] = self::limitsAmount($limitRepository->getBudgetLimits($budget, $current, $last));
            }
            $current = $last->copy()->addSecond();
        }
        $data = [['label' => $budget->name, 'type' => 'bar', 'entries' => $spent, 'currency_code' => 'UAH', 'currency_symbol' => '₴']];
        if ($caps) {
            $data[] = ['label' => trans('firefly.budgeted'), 'type' => 'bar', 'entries' => $budgeted, 'currency_code' => 'UAH', 'currency_symbol' => '₴'];
        }

        return $generator->multiSet($data);
    }

    public static function index($user, array $budgets, array $available, Carbon $start, Carbon $end): array
    {
        $ids      = array_map(static fn(array $budget): string => (string) $budget['id'], $budgets);
        $allStart = $start->copy();
        $allEnd   = $end->copy();
        foreach ($budgets as $budget) {
            foreach ($budget['budgeted'] as $limit) {
                $first    = Carbon::parse($limit['projection_start'], 'Europe/Kyiv');
                $last     = Carbon::parse($limit['projection_end'], 'Europe/Kyiv');
                $allStart = $first < $allStart ? $first : $allStart;
                $allEnd   = $last > $allEnd ? $last : $allEnd;
            }
        }
        $rows     = self::collect($user, $allStart, $allEnd, null, $ids);
        $budgets  = self::indexRows($budgets, $rows, $start->format('Y-m-d'), $end->format('Y-m-d'));
        $currency = ReportingCurrency::currency('UAH');
        $spent    = self::sum($rows, $start->format('Y-m-d'), $end->format('Y-m-d'), $ids);
        $budgeted = '0.00';
        foreach ($budgets as $budget) {
            $budgeted = bcadd($budgeted, $budget['display_amount'], 2);
        }
        $availableAmount = '0.00';
        foreach ($available as $entry) {
            $availableAmount = bcadd($availableAmount, ReportingCurrency::convert((string) $entry['amount'], $entry['transaction_currency']['code'], 'UAH'), 2);
        }
        if (!$available) {
            $availableAmount = $budgeted;
        }
        $available = [[
            'id'                   => 0,
            'amount'               => $availableAmount,
            'start_date'           => $start,
            'end_date'             => $end,
            'transaction_currency' => $currency->toArray(),
            'spent'                => $spent,
            'budgeted'             => $budgeted,
            'pc_spent'             => null,
            'pc_budgeted'          => null,
            'pc_amount'            => null
        ]];

        return ['budgets' => $budgets, 'available' => $available, 'spent' => $spent, 'budgeted' => $budgeted];
    }

    /** Pure projection: original editable limit values and currency IDs remain unchanged. */
    public static function indexRows(array $budgets, array $rows, string $start, string $end): array
    {
        foreach ($budgets as &$budget) {
            $amount  = '0.00';
            $covered = [];
            foreach ($budget['budgeted'] as &$limit) {
                $limit['display_amount'] = ReportingCurrency::convert((string) $limit['amount'], $limit['projection_currency_code'], 'UAH');
                $amount                  = bcadd($amount, $limit['display_amount'], 2);
                $first                   = $limit['projection_start'] ?? $start;
                $last                    = $limit['projection_end'] ?? $end;
                $parallel                = false;
                foreach ($budget['budgeted'] as $other) {
                    if (
                        $other['projection_currency_code'] !== $limit['projection_currency_code']
                        && ($other['projection_start'] ?? $start) <= $last
                        && ($other['projection_end'] ?? $end) >= $first
                    ) {
                        $parallel = true;
                    }
                }
                $eligible = $parallel
                    ? array_values(array_filter($rows, static fn(array $row): bool => $row['source_currency'] === $limit['projection_currency_code']))
                    : $rows;
                $limit['display_spent'] = self::sum($eligible, $first, $last, [(string) $budget['id']]);
                $limit['display_left']  = bcadd($limit['display_amount'], $limit['display_spent'], 2);
                foreach ($eligible as $row) {
                    if ((string) ($row['budget_id'] ?? '') === (string) $budget['id'] && $row['day'] >= $first && $row['day'] <= $last) {
                        $covered[(string) $row['id']] = true;
                    }
                }
            }
            unset($limit);
            $spent                     = self::sum($rows, $start, $end, [(string) $budget['id']]);
            $budget['display_amount']  = $amount;
            $budget['display_spent']   = $spent;
            $budget['display_left']    = bcadd($amount, $spent, 2);
            $outside                   = array_values(array_filter($rows, static fn(array $row): bool => !isset($covered[(string) $row['id']])));
            $budget['display_outside'] = self::sum($outside, $start, $end, [(string) $budget['id']]);
        }
        unset($budget);

        return $budgets;
    }

    public static function jsonInformation($user, Carbon $start, Carbon $end): array
    {
        [$repository, $limits] = self::repositories($user);
        $budgeted = '0.00';
        foreach ($repository->getActiveBudgets() as $budget) {
            $budgeted = bcadd($budgeted, self::limitsAmount($limits->getBudgetLimits($budget, $start, $end)), 2);
        }
        $currency  = ReportingCurrency::currency('UAH');
        $available = self::availableAmount($user, $start, $end, $budgeted);

        return [
            'budgeted'                => $budgeted,
            'budgeted_formatted'      => Amount::formatAnything($currency, $budgeted, true),
            'available'               => $available,
            'available_formatted'     => Amount::formatAnything($currency, $available, true),
            'percentage'              => bccomp($available, '0', 2) > 0 ? bcmul(bcdiv($budgeted, $available, 12), '100', 2) : '0',
            'currency_id'             => $currency->id,
            'currency_code'           => 'UAH',
            'currency_symbol'         => $currency->symbol,
            'currency_name'           => $currency->name,
            'currency_decimal_places' => 2
        ];
    }

    public static function leftToSpend($user, Carbon $start, Carbon $end): array
    {
        [$repository, $limitRepository] = self::repositories($user);
        $budgets  = $repository->getActiveBudgets();
        $budgeted = '0.00';
        $spent    = '0.00';
        foreach ($budgets as $budget) {
            $limits   = $limitRepository->getBudgetLimits($budget, $start, $end);
            $budgeted = bcadd($budgeted, self::limitsAmount($limits), 2);
            $first    = $start->copy();
            $last     = $end->copy();
            $coverage = [[$start->format('Y-m-d'), $end->format('Y-m-d')]];
            foreach ($limits as $limit) {
                $first      = $limit->start_date < $first ? $limit->start_date->copy() : $first;
                $last       = $limit->end_date > $last ? $limit->end_date->copy() : $last;
                $coverage[] = [$limit->start_date->format('Y-m-d'), $limit->end_date->format('Y-m-d')];
            }
            $ids  = [(string) $budget->id];
            $rows = self::collect($user, $first, $last, null, $ids);
            // A withdrawal covered by several limits is still one expense.
            $rows = array_filter($rows, static function (array $row) use ($coverage): bool {
                foreach ($coverage as [$from, $until]) {
                    if ($row['day'] >= $from && $row['day'] <= $until) {
                        return true;
                    }
                }

                return false;
            });
            $spent = bcadd($spent, self::sum($rows, $first->format('Y-m-d'), $last->format('Y-m-d'), $ids), 2);
        }
        $budgeted = self::availableAmount($user, $start, $end, $budgeted);
        $left     = bcadd($budgeted, $spent, 2);
        $days     = max(1, (int) ReportingCurrency::today()->diffInDays($end, false) + 1);
        $perDay   = bccomp($left, '0', 2) >= 0 ? bcdiv($left, (string) $days, 2) : '0.00';
        $currency = ReportingCurrency::currency('UAH');

        return [
            $currency->id => [
                'key'                     => 'left-to-spend-in-UAH',
                'title'                   => trans('firefly.box_left_to_spend_in_currency', ['currency' => $currency->symbol]),
                'no_available_budgets'    => 0 === bccomp($budgeted, '0', 2),
                'monetary_value'          => $left,
                'currency_id'             => (string) $currency->id,
                'currency_code'           => $currency->code,
                'currency_symbol'         => $currency->symbol,
                'currency_decimal_places' => $currency->decimal_places,
                'value_parsed'            => Amount::formatAnything($currency, $left, false),
                'sub_title'               => Amount::formatAnything($currency, $perDay, false)
            ]
        ];
    }

    public static function limitChart($user, $budget, $limit, $generator): array
    {
        self::assertLimit($budget, $limit);
        $start   = $limit->start_date->copy();
        $end     = $limit->end_date->copy();
        $rows    = self::collect($user, $start, $end, null, [(string) $budget->id]);
        $amount  = ReportingCurrency::convert((string) $limit->amount, $limit->transactionCurrency->code, 'UAH');
        $entries = [];
        $locale  = Steam::getLocale();
        while ($start <= $end) {
            $amount = bcadd($amount, self::sum($rows, $start->format('Y-m-d'), $start->format('Y-m-d'), [(string) $budget->id]), 2);

            $entries[$start->isoFormat((string) trans('config.month_and_day_js', [], $locale))] = $amount;
            $start->addDay();
        }
        $data                                   = $generator->singleSet((string) trans('firefly.left'), $entries);
        $data['datasets'][0]['currency_symbol'] = '₴';
        $data['datasets'][0]['currency_code']   = 'UAH';

        return $data;
    }

    /** Only optimistic display strings change after the native save has completed. */
    public static function limitResponse($user, $limit, array $response): array
    {
        try {
            $rows  = self::collect($user, $limit->start_date, $limit->end_date, null, [(string) $limit->budget_id]);
            $spent = self::sum($rows, $limit->start_date->format('Y-m-d'), $limit->end_date->format('Y-m-d'), [
                (string) $limit->budget_id
            ]);
            $cap                                = ReportingCurrency::convert((string) $limit->amount, $limit->transactionCurrency->code, 'UAH');
            $left                               = bcadd($cap, $spent, 2);
            $days                               = (string) ($response['days_left'] ?? '0');
            $perDay                             = bccomp($left, '0', 2) > 0 ? (bccomp($days, '0') > 0 ? bcdiv($left, $days, 2) : $left) : '0.00';
            $currency                           = ReportingCurrency::currency('UAH');
            $response['left_formatted']         = Amount::formatAnything($currency, $left);
            $response['left_per_day_formatted'] = Amount::formatAnything($currency, $perDay);
        } catch (\Throwable) {
            // A failed display conversion must not obscure a successful native save.
            $response['left_formatted']         = '<span title="Не вдалося обчислити залишок у гривнях">—</span>';
            $response['left_per_day_formatted'] = $response['left_formatted'];
        }

        return $response;
    }

    public static function limitsForBudget($user, $budget, Carbon $start, Carbon $end): Collection
    {
        [, $repository] = self::repositories($user);

        return self::showLimits($user, $repository->getBudgetLimits($budget, $start, $end->copy()->endOfMonth()));
    }

    public static function noBudgetHistory($user, Collection $accounts, Carbon $start, Carbon $end, $generator): array
    {
        $rows    = self::collect($user, $start, $end, $accounts, null, true);
        $current = $start->copy();
        $step    = Navigation::preferredRangeFormat($start, $end);
        $entries = [];
        while ($current <= $end) {
            $last            = Navigation::endOfPeriod($current->copy(), $step);
            $label           = $current->isoFormat(Navigation::preferredCarbonLocalizedFormat($start, $end));
            $entries[$label] = bcmul(self::sum($rows, $current->format('Y-m-d'), $last->format('Y-m-d'), null), '-1', 2);
            $current         = $last->copy()->addDay()->startOfDay();
        }

        return $generator->multiSet([['label' => trans('firefly.spent'), 'entries' => $entries, 'currency_code' => 'UAH', 'currency_symbol' => '₴']]);
    }

    public static function overview($user, Carbon $start, Carbon $end, $generator): array
    {
        [$repository, $limitRepository] = self::repositories($user);
        $budgets = $repository->getActiveBudgets();
        $data    = [
            ['label' => trans('firefly.spent_in_budget'), 'entries' => [], 'type' => 'bar', 'currency_code' => 'UAH', 'currency_symbol' => '₴'],
            ['label' => trans('firefly.left_to_spend'), 'entries' => [], 'type' => 'bar', 'currency_code' => 'UAH', 'currency_symbol' => '₴'],
            ['label' => trans('firefly.overspent'), 'entries' => [], 'type' => 'bar', 'currency_code' => 'UAH', 'currency_symbol' => '₴']
        ];
        foreach ($budgets as $budget) {
            $limits     = $limitRepository->getBudgetLimits($budget, $start, $end);
            $projection = self::projectBudget($user, $budget, $limits, $start, $end);
            foreach ($projection['budgeted'] as $limit) {
                $label = $budget->name;
                if ($limit['projection_start'] !== $start->format('Y-m-d') || $limit['projection_end'] !== $end->format('Y-m-d')) {
                    $label .= ' (' . $limit['projection_start'] . ' — ' . $limit['projection_end'] . ')';
                }
                if (count($projection['budgeted']) > 1) {
                    $label .= ' [' . $limit['projection_currency_code'] . ']';
                }
                $data = self::overviewEntry($data, $label, $limit['display_amount'], $limit['display_spent']);
            }
            if (!$projection['budgeted'] || 0 !== bccomp($projection['display_outside'], '0', 2)) {
                $data = self::overviewEntry($data, $budget->name . ' — ' . trans('firefly.spent_outside_chart'), '0.00', $projection['display_outside']);
            }
        }

        return $generator->multiSet($data);
    }

    public static function pie($user, string $dimension, array $budgetIds, Carbon $start, Carbon $end, $generator, null|Collection $accounts = null): array
    {
        $rows     = self::collect($user, $start, $end, $accounts, $budgetIds);
        $result   = [];
        $selected = array_fill_keys(array_map('strval', $budgetIds), true);
        foreach ($rows as $row) {
            if (!isset($selected[(string) ($row['budget_id'] ?? '')])) {
                continue;
            }
            $label                    = (string) ($row[$dimension] ?? trans('firefly.no_category'));
            $result[$label]           ??= ['amount' => '0.00', 'currency_code' => 'UAH', 'currency_symbol' => '₴'];
            $result[$label]['amount'] = bcadd($result[$label]['amount'], $row['amount'], 2);
        }

        return $generator->multiCurrencyPieChart($result);
    }

    /** Convert each owned withdrawal once, without consulting saved native/pc fields. */
    public static function project(array $journals, array $assetIds, string $start, string $end, null|array $budgetIds): array
    {
        $owned    = array_fill_keys(array_map('strval', $assetIds), true);
        $selected = null === $budgetIds ? null : array_fill_keys(array_map('strval', $budgetIds), true);
        $result   = [];
        $seen     = [];
        foreach ($journals as $journal) {
            if ('Withdrawal' !== $journal['type'] || !isset($owned[(string) $journal['source_id']]) || isset($owned[(string) $journal['destination_id']])) {
                continue;
            }
            if ($journal['day'] < $start || $journal['day'] > $end) {
                continue;
            }
            $budget = (string) ($journal['budget_id'] ?? '');
            if (null !== $selected && !isset($selected[$budget])) {
                continue;
            }
            $id = (string) $journal['id'];
            if (isset($seen[$id])) {
                throw new RuntimeException('Повтор операції у вибірці бюджету.');
            }
            $seen[$id] = true;
            $raw       = (string) $journal['amount'];
            if (!preg_match('/^-?\d+(?:\.\d+)?$/D', $raw)) {
                throw new RuntimeException('Некоректна сума витрати бюджету.');
            }
            $journal['source_amount']   = $raw;
            $journal['source_currency'] = $journal['currency'];
            $journal['amount']          = ReportingCurrency::convert('-' . ltrim($raw, '-'), $journal['currency'], 'UAH');
            $journal['currency']        = 'UAH';
            $result[]                   = $journal;
        }

        return $result;
    }

    public static function showLimits($user, iterable $limits): Collection
    {
        $result = new Collection();
        foreach ($limits as $limit) {
            $copy                 = clone $limit;
            $rows                 = self::collect($user, $limit->start_date, $limit->end_date, null, [(string) $limit->budget_id]);
            $copy->display_amount = ReportingCurrency::convert((string) $limit->amount, $limit->transactionCurrency->code, 'UAH');
            $copy->display_spent  = self::sum($rows, $limit->start_date->format('Y-m-d'), $limit->end_date->format('Y-m-d'), [(string) $limit->budget_id]);
            $result->push($copy);
        }

        return $result;
    }

    public static function sum(array $rows, string $start, string $end, null|array $budgetIds): string
    {
        $selected = null === $budgetIds ? null : array_fill_keys(array_map('strval', $budgetIds), true);
        $sum      = '0.00';
        foreach ($rows as $row) {
            if ($row['day'] >= $start && $row['day'] <= $end && (null === $selected || isset($selected[(string) ($row['budget_id'] ?? '')]))) {
                $sum = bcadd($sum, $row['amount'], 2);
            }
        }

        return $sum;
    }

    private static function apiRow(string $label, string $cap, string $spent, Carbon $first, Carbon $last, $currency): array
    {
        $left     = bcadd($cap, $spent, 2);
        $hasLimit = bccomp($cap, '0', 2) > 0;
        $entries  = [
            'budgeted'  => $cap,
            'spent'     => $spent,
            'left'      => $hasLimit && bccomp($left, '0', 2) > 0 ? $left : '0.00',
            'overspent' => $hasLimit && bccomp($left, '0', 2) < 0 ? bcmul($left, '-1', 2) : '0.00'
        ];

        return [
            'label'                           => $label,
            'currency_id'                     => (string) $currency->id,
            'currency_name'                   => $currency->name,
            'currency_code'                   => 'UAH',
            'currency_symbol'                 => '₴',
            'currency_decimal_places'         => 2,
            'primary_currency_id'             => (string) $currency->id,
            'primary_currency_name'           => $currency->name,
            'primary_currency_code'           => 'UAH',
            'primary_currency_symbol'         => '₴',
            'primary_currency_decimal_places' => 2,
            'period'                          => null,
            'date'                            => $first->toAtomString(),
            'start_date'                      => $first->toAtomString(),
            'end_date'                        => $last->toAtomString(),
            'yAxisID'                         => 0,
            'type'                            => 'bar',
            'entries'                         => $entries,
            'pc_entries'                      => $entries
        ];
    }

    private static function assertLimit($budget, $limit): void
    {
        if (null !== $limit && (string) $limit->budget_id !== (string) $budget->id) {
            throw new RuntimeException('Ліміт не належить обраному бюджету.');
        }
    }

    private static function availableAmount($user, Carbon $start, Carbon $end, string $fallback): string
    {
        $repository = app(AvailableBudgetRepositoryInterface::class);
        $repository->setUser($user);
        $entries = $repository->get($start, $end);
        if ($entries->isEmpty()) {
            return $fallback;
        }
        $amount = '0.00';
        foreach ($entries as $entry) {
            $amount = bcadd($amount, ReportingCurrency::convert((string) $entry->amount, $entry->transactionCurrency->code, 'UAH'), 2);
        }

        return $amount;
    }

    private static function collect(
        $user,
        Carbon $start,
        Carbon $end,
        null|Collection $requestedAccounts = null,
        null|array $budgetIds = null,
        bool $withoutBudget = false
    ): array {
        $repository = app(AccountRepositoryInterface::class);
        $repository->setUser($user);
        $assets = $repository->getAccountsByType(config('firefly.accountTypesByIdentifier.asset'));
        if (null !== $requestedAccounts) {
            $allowed = array_fill_keys(
                $requestedAccounts
                    ->pluck('id')
                    ->map(static fn($id): string => (string) $id)
                    ->all(),
                true
            );
            $assets = $assets->filter(static fn($account): bool => isset($allowed[(string) $account->id]));
        }
        if ($assets->isEmpty()) {
            return [];
        }
        $ids = $assets
            ->pluck('id')
            ->map(static fn($id): string => (string) $id)
            ->all();
        // SQL can compare UTC dates; project() applies the exact Kyiv-day range.
        $raw = app(GroupCollectorInterface::class)
            ->setUser($user)
            ->setAccounts($assets)
            ->setRange($start->copy()->subDay()->startOfDay(), $end->copy()->addDay()->endOfDay())
            ->setTypes([TransactionTypeEnum::WITHDRAWAL->value])
            ->withAccountInformation()
            ->withBudgetInformation()
            ->withCategoryInformation()
            ->getExtractedJournals();
        $rows = [];
        foreach ($raw as $journal) {
            if ($withoutBudget && !empty($journal['budget_id'])) {
                continue;
            }
            $rows[] = [
                'id'               => $journal['transaction_journal_id'],
                'day'              => $journal['date']->copy()->setTimezone('Europe/Kyiv')->format('Y-m-d'),
                'type'             => $journal['transaction_type_type'],
                'source_id'        => $journal['source_account_id'],
                'destination_id'   => $journal['destination_account_id'],
                'budget_id'        => $journal['budget_id'] ?? null,
                'budget_name'      => $journal['budget_name'] ?? '',
                'currency'         => $journal['currency_code'],
                'amount'           => (string) $journal['amount'],
                'source_name'      => $journal['source_account_name'] ?? '',
                'destination_name' => $journal['destination_account_name'] ?? '',
                'category_name'    => $journal['category_name'] ?? trans('firefly.no_category')
            ];
        }

        return self::project($rows, $ids, $start->format('Y-m-d'), $end->format('Y-m-d'), $budgetIds);
    }

    private static function limitsAmount(iterable $limits): string
    {
        $amount = '0.00';
        foreach ($limits as $limit) {
            $amount = bcadd($amount, ReportingCurrency::convert((string) $limit->amount, $limit->transactionCurrency->code, 'UAH'), 2);
        }

        return $amount;
    }

    private static function overviewEntry(array $data, string $label, string $cap, string $spent): array
    {
        $left                       = bcadd($cap, $spent, 2);
        $hasLimit                   = bccomp($cap, '0', 2) > 0;
        $positive                   = bcmul($spent, '-1', 2);
        $data[0]['entries'][$label] = $hasLimit && bccomp($positive, $cap, 2) > 0 ? $cap : $positive;
        $data[1]['entries'][$label] = $hasLimit && bccomp($left, '0', 2) > 0 ? $left : '0.00';
        $data[2]['entries'][$label] = $hasLimit && bccomp($left, '0', 2) < 0 ? bcmul($left, '-1', 2) : '0.00';

        return $data;
    }

    private static function projectBudget($user, $budget, iterable $limits, Carbon $start, Carbon $end): array
    {
        $allStart = $start->copy();
        $allEnd   = $end->copy();
        $prepared = [];
        foreach ($limits as $limit) {
            $allStart   = $limit->start_date < $allStart ? $limit->start_date->copy() : $allStart;
            $allEnd     = $limit->end_date > $allEnd ? $limit->end_date->copy() : $allEnd;
            $prepared[] = [
                'id'                       => $limit->id,
                'amount'                   => (string) $limit->amount,
                'projection_currency_code' => $limit->transactionCurrency->code,
                'projection_start'         => $limit->start_date->format('Y-m-d'),
                'projection_end'           => $limit->end_date->format('Y-m-d')
            ];
        }
        $rows = self::collect($user, $allStart, $allEnd, null, [(string) $budget->id]);

        return self::indexRows([['id' => $budget->id, 'budgeted' => $prepared]], $rows, $start->format('Y-m-d'), $end->format('Y-m-d'))[0];
    }

    private static function repositories($user): array
    {
        $budgets = app(BudgetRepositoryInterface::class);
        $limits  = app(BudgetLimitRepositoryInterface::class);
        $budgets->setUser($user);
        $limits->setUser($user);

        return [$budgets, $limits];
    }
}
