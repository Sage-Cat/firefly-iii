<?php

declare(strict_types=1);

namespace FireflyIII\Support\Reports;

use DateInterval;
use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;

final class ProfitSummary
{
    public static function build(
        array $journals,
        array $rates,
        array $assetIds,
        string $start,
        string $end,
        string $currency,
        string $granularity = 'auto'
    ): array {
        if (!in_array($currency, ['EUR', 'UAH'], true)) {
            throw new InvalidArgumentException('Оберіть EUR або UAH.');
        }
        if (!in_array($granularity, ['auto', 'monthly', 'weekly'], true)) {
            throw new InvalidArgumentException('Некоректне групування звіту.');
        }
        $first = self::day($start);
        $last  = self::day($end);
        if ($last < $first || $first->diff($last)->days > 3660) {
            throw new InvalidArgumentException('Некоректний діапазон звіту.');
        }
        $daily  = 'auto' === $granularity && $first->diff($last)->days <= 45;
        $weekly = 'weekly' === $granularity;
        $format = $daily ? 'Y-m-d' : ($weekly ? 'o-\WW' : 'Y-m');
        $step   = new DateInterval($daily ? 'P1D' : ($weekly ? 'P1W' : 'P1M'));
        $owned  = array_fill_keys(array_map('strval', $assetIds), true);
        $points = [];
        $cursor = $daily ? $first : $first->modify($weekly ? 'monday this week' : 'first day of this month');
        while ($cursor <= $last) {
            $key          = $cursor->format($format);
            $points[$key] = ['period' => $key, 'income' => '0.00', 'expenses' => '0.00', 'net' => '0.00'];
            if ('auto' !== $granularity) {
                $next                  = $cursor->add($step)->sub(new DateInterval('P1D'));
                $points[$key]['start'] = max($first, $cursor)->format('Y-m-d');
                $points[$key]['end']   = min($last, $next)->format('Y-m-d');
            }
            $cursor = $cursor->add($step);
        }
        $total  = ['income' => '0.00', 'expenses' => '0.00', 'net' => '0.00'];
        $count  = 0;
        $seen   = [];
        $manual = [];
        foreach ($journals as $journal) {
            $type = $journal['type'];
            if (!in_array($type, ['Deposit', 'Withdrawal'], true)) {
                continue;
            }
            $sourceOwned      = isset($owned[(string) $journal['source_id']]);
            $destinationOwned = isset($owned[(string) $journal['destination_id']]);
            if ($sourceOwned && $destinationOwned || 'Deposit' === $type && !$destinationOwned || 'Withdrawal' === $type && !$sourceOwned) {
                continue;
            }
            $date = self::day($journal['day']);
            $day  = $date->format('Y-m-d');
            if ($day < $start || $day > $end) {
                continue;
            }
            $identifier = (string) $journal['id'];
            if (isset($seen[$identifier])) {
                throw new RuntimeException('Повтор операції у вибірці звіту.');
            }
            $seen[$identifier] = true;
            $raw               = (string) $journal['amount'];
            if (!preg_match('/^-?\d+(?:\.\d+)?$/D', $raw)) {
                throw new RuntimeException('Некоректна сума операції.');
            }
            $amount = ltrim($raw, '-');
            $code   = $journal['currency'];
            if (!in_array($code, ['UAH', 'EUR', 'USD', 'CHF', 'USDT'], true)) {
                $manual[$code] = true;
            }
            // The same current UAH bridge is used for every selected period.
            $amount = CurrencyBridge::convert($amount, $code, $currency, $rates);
            $refund = RefundProjection::isRefund($journal);
            $bucket = 'Deposit' === $type && !$refund ? 'income' : 'expenses';
            if ($refund) {
                $amount = bcsub('0', $amount, 2);
            }
            $key                   = $date->format($format);
            $points[$key][$bucket] = bcadd($points[$key][$bucket], $amount, 2);
            $total[$bucket]        = bcadd($total[$bucket], $amount, 2);
            ++$count;
        }
        $cumulativeNet = '0.00';
        foreach ($points as &$point) {
            $point['net']            = bcsub($point['income'], $point['expenses'], 2);
            $cumulativeNet           = bcadd($cumulativeNet, $point['net'], 2);
            $point['cumulative_net'] = $cumulativeNet;
        }
        unset($point);
        $total['net'] = bcsub($total['income'], $total['expenses'], 2);

        return ['totals' => $total, 'points' => array_values($points), 'daily' => $daily, 'transactions' => $count, 'manual_currencies' => array_keys($manual)];
    }

    public static function money(string $value): string
    {
        [$whole, $fraction] = explode('.', bcadd($value, '0', 2));

        return preg_replace('/\B(?=(\d{3})+(?!\d))/', ' ', $whole) . ',' . $fraction;
    }

    public static function period(string $today, int $months = 1): array
    {
        if (!in_array($months, [1, 3, 12], true)) {
            throw new InvalidArgumentException('Некоректний готовий період звіту.');
        }
        $last  = self::day($today);
        $first = 1 === $months
            ? $last->sub(new DateInterval('P29D'))
            : $last->modify('first day of this month')->sub(new DateInterval('P' . ($months - 1) . 'M'));

        return ['start' => $first->format('Y-m-d'), 'end' => $last->format('Y-m-d')];
    }

    private static function day(string $value): DateTimeImmutable
    {
        $day = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (false === $day || $day->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException('Некоректна дата звіту.');
        }

        return $day;
    }
}
