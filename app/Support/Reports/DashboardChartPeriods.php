<?php

declare(strict_types=1);

namespace FireflyIII\Support\Reports;

use Carbon\Carbon;
use InvalidArgumentException;

final class DashboardChartPeriods
{
    private const CHARTS  = ['capital', 'budget', 'category', 'expense', 'revenue'];
    private const PERIODS = ['30d', '3m', '1y'];

    public static function all(): array
    {
        $ranges = [];
        foreach (self::CHARTS as $chart) {
            $ranges[$chart] = self::forChart($chart);
        }

        return $ranges;
    }

    public static function forChart(string $chart): array
    {
        self::validateChart($chart);

        return self::range(request()->query($chart . '_period', '30d'));
    }

    public static function isDashboardRequest(): bool
    {
        return request()->isMethod('GET') && request()->query->has('dashboard_period');
    }

    public static function link(string $chart, string $period): string
    {
        self::validateChart($chart);
        $query                     = request()->query();
        $query[$chart . '_period'] = self::normalize($period);

        return url('/') . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986) . '#dashboard-' . $chart . '-period';
    }

    public static function range(mixed $period): array
    {
        [$period, $start, $end] = self::bounds($period);

        return ['period' => $period, 'start' => $start->format('Y-m-d'), 'end' => $end->format('Y-m-d')];
    }

    /** Only an explicit dashboard GET overrides the caller's existing date range. */
    public static function requestRange(Carbon $start, Carbon $end): array
    {
        if (!self::isDashboardRequest()) {
            return [$start->copy(), $end->copy()];
        }
        [, $first, $last] = self::bounds(request()->query('dashboard_period'));

        return [$first->startOfDay(), $last->endOfDay()];
    }

    private static function bounds(mixed $period): array
    {
        $period = self::normalize($period);
        $end    = ReportingCurrency::today()->copy()->setTimezone('Europe/Kyiv')->startOfDay();
        $start  = match ($period) {
            '3m'    => $end->copy()->subMonthsNoOverflow(3)->addDay(),
            '1y'    => $end->copy()->subYearNoOverflow()->addDay(),
            default => $end->copy()->subDays(29)
        };

        $start = Carbon::parse(AccountingPeriod::start($start->format('Y-m-d'), $end->format('Y-m-d')), 'Europe/Kyiv');

        return [$period, $start, $end];
    }

    private static function normalize(mixed $period): string
    {
        return is_string($period) && in_array($period, self::PERIODS, true) ? $period : '30d';
    }

    private static function validateChart(string $chart): void
    {
        if (!in_array($chart, self::CHARTS, true)) {
            throw new InvalidArgumentException('Unknown dashboard chart.');
        }
    }
}
