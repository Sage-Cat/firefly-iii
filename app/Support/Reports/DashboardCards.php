<?php

declare(strict_types=1);

namespace FireflyIII\Support\Reports;

use Carbon\Carbon;
use FireflyIII\Repositories\Budget\BudgetLimitRepositoryInterface;
use FireflyIII\Repositories\Budget\BudgetRepositoryInterface;
use FireflyIII\Support\Facades\Amount;

final class DashboardCards
{
    public static function rows($billRepository, Carbon $start, Carbon $end, string $code = ''): array
    {
        $ledger   = DashboardLedger::data();
        $external = self::externalLegs($ledger);
        $first    = $start->format('Y-m-d');
        $last     = $end->format('Y-m-d');
        $flow     = [];
        foreach ($external as $leg) {
            if ($leg['day'] < $first || $leg['day'] > $last) {
                continue;
            }
            $currency               = $leg['currency'];
            $flow[$currency]        ??= ['earned' => '0', 'spent' => '0'];
            $kind                   = 'Deposit' === $leg['type'] && !RefundProjection::isRefund($leg) ? 'earned' : 'spent';
            $flow[$currency][$kind] = bcadd($flow[$currency][$kind], $leg['amount'], 32);
        }
        $rows = [];
        foreach ($flow as $currency => $sums) {
            $model    = ReportingCurrency::currency($currency);
            $subtitle = Amount::formatAnything($model, $sums['spent'], false) . ' + ' . Amount::formatAnything($model, $sums['earned'], false);
            $rows[]   = self::row('balance', $currency, bcadd($sums['earned'], $sums['spent'], 32), $subtitle);
            $rows[]   = self::row('spent', $currency, $sums['spent']);
            $rows[]   = self::row('earned', $currency, $sums['earned']);
        }
        $rows = array_merge(
            $rows,
            ReportingSubscriptions::nativeRows($billRepository, $start, $end, $ledger),
            self::budgetRows($external, $start, $end),
            self::netWorthRows($ledger, $last)
        );
        $result = [];
        foreach ($rows as $row) {
            if ('' === $code || $row['currency_code'] === $code) {
                $result[$row['key']] = $row;
            }
        }

        return $result;
    }

    private static function budgetRows(array $external, Carbon $start, Carbon $end): array
    {
        $budgets = app(BudgetRepositoryInterface::class);
        $limits  = app(BudgetLimitRepositoryInterface::class);
        $budgets->setUser(auth()->user());
        $limits->setUser(auth()->user());
        $caps       = [];
        $coverage   = [];
        $seenLimits = [];
        foreach ($budgets->getActiveBudgets() as $budget) {
            foreach ($limits->getBudgetLimits($budget, $start->copy(), $end->copy()) as $limit) {
                $id = (string) $limit->id;
                if (isset($seenLimits[$id])) {
                    continue;
                }
                $seenLimits[$id]                             = true;
                $currency                                    = $limit->transactionCurrency->code;
                $caps[$currency]                             = bcadd($caps[$currency] ?? '0', (string) $limit->amount, 32);
                $coverage[(string) $budget->id][$currency][] = [$limit->start_date->format('Y-m-d'), $limit->end_date->format('Y-m-d')];
            }
        }
        $spent = [];
        foreach ($external as $leg) {
            if ('Withdrawal' !== $leg['type'] && !RefundProjection::isRefund($leg) || !isset($caps[$leg['currency']])) {
                continue;
            }
            foreach (RefundProjection::isRefund($leg) ? $leg['refund_budget_ids'] ?? [] : $leg['budget_ids'] as $budgetId) {
                foreach ($coverage[(string) $budgetId][$leg['currency']] ?? [] as [$first, $last]) {
                    // Include the complete overlapping limit, including its earlier months.
                    if ($leg['day'] >= $first && $leg['day'] <= $last || $leg['day'] >= $start->format('Y-m-d') && $leg['day'] <= $end->format('Y-m-d')) {
                        $spent[$leg['currency']] = bcadd($spent[$leg['currency']] ?? '0', $leg['amount'], 32);
                        continue 3;
                    }
                }
            }
        }
        $days = max(1, (int) ReportingCurrency::today()->diffInDays($end, false) + 1);
        $rows = [];
        foreach ($caps as $currency => $cap) {
            $left                        = bcadd($cap, $spent[$currency] ?? '0', 32);
            $perDay                      = bccomp($left, '0', 32) >= 0 ? bcdiv($left, (string) $days, 32) : '0';
            $model                       = ReportingCurrency::currency($currency);
            $row                         = self::row('left-to-spend', $currency, $left, Amount::formatAnything($model, $perDay, false));
            $row['no_available_budgets'] = 0 === bccomp($cap, '0', 32);
            $rows[]                      = $row;
            $rows[]                      = self::row('left-per-day-to-spend', $currency, $perDay);
        }

        return $rows;
    }

    /** A transfer between two owned accounts never becomes income or spending. */
    private static function externalLegs(array $ledger): array
    {
        $journals = [];
        foreach ($ledger['legs'] as $leg) {
            if (isset($ledger['accounts'][(string) $leg['account_id']])) {
                $journals[(string) $leg['journal_id']][(string) $leg['id']] = $leg;
            }
        }
        $result = [];
        foreach ($journals as $legs) {
            if (1 !== count($legs)) {
                continue;
            }
            $leg  = reset($legs);
            $sign = bccomp($leg['amount'], '0', 32);
            if ('Withdrawal' === $leg['type'] && $sign < 0 || 'Deposit' === $leg['type'] && $sign > 0) {
                $result[] = $leg;
            }
        }

        return $result;
    }

    private static function netWorthRows(array $ledger, string $end): array
    {
        $sums     = [];
        $accounts = [];
        foreach ($ledger['accounts'] as $id => $account) {
            if (!$account['net_worth'] || null !== $account['known_from'] && $account['known_from'] > $end) {
                continue;
            }
            $accounts[(string) $id]     = true;
            $sums[$account['currency']] ??= '0';
        }
        $seen = [];
        foreach ($ledger['legs'] as $leg) {
            if ($leg['day'] > $end || !isset($accounts[(string) $leg['account_id']]) || isset($seen[(string) $leg['id']])) {
                continue;
            }
            $seen[(string) $leg['id']] = true;
            $sums[$leg['currency']]    = bcadd($sums[$leg['currency']] ?? '0', $leg['amount'], 32);
        }
        if ([] === $sums) {
            return [];
        }
        $quotes   = ReportingCurrency::quotes();
        $uahTotal = '0';
        foreach ($sums as $currency => $sum) {
            if (0 === bccomp($sum, '0', 32)) {
                continue;
            }
            $uahTotal = bcadd($uahTotal, bcmul($sum, CurrencyBridge::rate($currency, 'UAH', $quotes), 20), 20);
        }

        return [self::row('net-worth', 'EUR', CurrencyBridge::convert($uahTotal, 'UAH', 'EUR', $quotes))];
    }

    private static function row(string $kind, string $code, string $amount, string $subtitle = ''): array
    {
        $currency = ReportingCurrency::currency($code);
        $amount   = bcadd($amount, '0', $currency->decimal_places);

        return [
            'key'                     => $kind . '-in-' . $code,
            'title'                   => 'left-per-day-to-spend' === $kind
                ? trans('firefly.left_to_spend_per_day') . ' (' . $currency->symbol . ')'
                : trans('firefly.box_' . str_replace('-', '_', $kind) . '_in_currency', ['currency' => $currency->symbol]),
            'monetary_value'          => $amount,
            'currency_id'             => (string) $currency->id,
            'currency_code'           => $currency->code,
            'currency_symbol'         => $currency->symbol,
            'currency_decimal_places' => $currency->decimal_places,
            'value_parsed'            => Amount::formatAnything($currency, $amount, false),
            'sub_title'               => $subtitle
        ];
    }
}
