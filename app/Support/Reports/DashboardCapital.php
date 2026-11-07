<?php

declare(strict_types=1);

namespace FireflyIII\Support\Reports;

use Carbon\Carbon;

final class DashboardCapital
{
    /** Native dashboard chart API shape: one EUR series, including unknown-day gaps. */
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
        foreach ($result['points'] as $point) {
            $day                  = Carbon::parse($point['day'], 'Europe/Kyiv')->setTime(12, 0)->toIso8601String();
            $row['entries'][$day] = $point['total'];
        }
        $row['pc_entries'] = $row['entries'];

        return [$row];
    }

    public static function data(): array
    {
        $key = 'dashboard-capital';
        if (request()->attributes->has($key)) {
            return request()->attributes->get($key);
        }
        $end   = ReportingCurrency::today();
        $start = $end->copy()->subDays(29);
        try {
            $ledger = DashboardLedger::data();

            $result = NetWorthSeries::build($ledger['accounts'], $ledger['legs'], ReportingCurrency::quotes(), $start->format('Y-m-d'), $end->format('Y-m-d'));

            $result['error'] = null;
        } catch (\RuntimeException $exception) {
            $result = ['error' => $exception->getMessage(), 'points' => [], 'coverage_start' => null];
        }
        request()->attributes->set($key, $result);

        return $result;
    }
}
