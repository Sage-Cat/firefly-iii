<?php

declare(strict_types=1);

namespace FireflyIII\Support\Reports;

use Carbon\Carbon;

final class DashboardCapital
{
    /** Complete and explicitly incomplete EUR history share one chart. */
    public static function api(): array
    {
        $result = self::data();
        if (null !== $result['error']) {
            return [];
        }
        $currency = ReportingCurrency::currency('EUR');
        $row      = ['label' => 'Чисті активи', 'entries' => [], 'pc_entries' => []];
        foreach (['' => '', 'primary_' => ''] as $prefix => $unused) {
            foreach (['id', 'name', 'code', 'symbol', 'decimal_places'] as $field) {
                $row[$prefix . 'currency_' . $field] = 'id' === $field ? (string) $currency->{$field} : $currency->{$field};
            }
        }
        $partial          = $row;
        $partial['label'] = 'Відома частина капіталу · неповні дані';
        $hasPartial       = false;
        foreach ($result['points'] as $point) {
            $day                      = Carbon::parse($point['day'], 'Europe/Kyiv')->setTime(12, 0)->toIso8601String();
            $row['entries'][$day]     = $point['total'];
            $partial['entries'][$day] = null === $point['total'] ? $point['known_total'] : null;
            $hasPartial               = $hasPartial || null !== $partial['entries'][$day];
        }
        $row['pc_entries']     = $row['entries'];
        $partial['pc_entries'] = $partial['entries'];

        return $hasPartial ? [$row, $partial] : [$row];
    }

    public static function data(): array
    {
        $period = request()->query('capital_period', '30d');
        $period = in_array($period, ['30d', '3m', '1y'], true) ? $period : '30d';
        $key    = 'dashboard-capital:' . $period;
        if (request()->attributes->has($key)) {
            return request()->attributes->get($key);
        }
        $end   = ReportingCurrency::today();
        $start = match ($period) {
            '3m'    => $end->copy()->subMonthsNoOverflow(3)->addDay(),
            '1y'    => $end->copy()->subYearNoOverflow()->addDay(),
            default => $end->copy()->subDays(29)
        };
        try {
            $start  = Carbon::parse(AccountingPeriod::start($start->format('Y-m-d'), $end->format('Y-m-d')), 'Europe/Kyiv');
            $ledger = DashboardLedger::data();
            $result = NetWorthSeries::build($ledger['accounts'], $ledger['legs'], ReportingCurrency::quotes(), $start->format('Y-m-d'), $end->format('Y-m-d'));
            $result['error'] = null;
        } catch (\RuntimeException $exception) {
            $result = ['error' => $exception->getMessage(), 'points' => [], 'coverage_start' => null];
        }
        $result['period'] = $period;
        $result['start']  = $start->format('Y-m-d');
        $result['end']    = $end->format('Y-m-d');
        request()->attributes->set($key, $result);

        return $result;
    }
}
