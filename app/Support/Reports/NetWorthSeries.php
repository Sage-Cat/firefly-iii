<?php

declare(strict_types=1);

namespace FireflyIII\Support\Reports;

use DateTimeImmutable;
use RuntimeException;

final class NetWorthSeries
{
    public static function build(array $accounts, array $legs, array $quotes, string $start, string $end): array
    {
        $first = self::date($start);
        $last  = self::date($end);
        if ($first > $last || $first->diff($last)->days > 366) {
            throw new RuntimeException('Некоректний період історії капіталу.');
        }
        $owned    = array_filter($accounts, static fn($account) => $account['net_worth']);
        $coverage = null;
        $events   = [];
        $seen     = [];
        foreach ($legs as $leg) {
            if (!isset($owned[(string) $leg['account_id']])) {
                continue;
            }
            self::date($leg['day']);
            if ($leg['day'] > $end) {
                continue;
            }
            if (isset($seen[$leg['id']])) {
                throw new RuntimeException('Повтор операції в історії капіталу.');
            }
            if (!preg_match('/^-?\d+(?:\.\d+)?$/D', $leg['amount'])) {
                throw new RuntimeException('Некоректна сума історії капіталу.');
            }
            $seen[$leg['id']] = true;
            $events[]         = $leg;
        }
        usort($events, static fn($a, $b) => strcmp($a['day'], $b['day']));
        $balances = [];
        $position = 0;
        $points   = [];
        $manual   = [];
        for ($day = $first; $day <= $last; $day = $day->modify('+1 day')) {
            $key = $day->format('Y-m-d');
            while (isset($events[$position]) && $events[$position]['day'] <= $key) {
                $event                = $events[$position++];
                $id                   = (string) $event['account_id'];
                $code                 = $event['currency'];
                $balances[$id][$code] = bcadd($balances[$id][$code] ?? '0', $event['amount'], 16);
            }
            $byCurrency = [];
            $unknown    = 0;
            $known      = 0;
            foreach ($owned as $id => $account) {
                $nativeFrom = $account['known_from'];
                if (null !== $nativeFrom) {
                    self::date($nativeFrom);
                }
                if (null === $nativeFrom || $key >= $nativeFrom) {
                    $currencies = $balances[(string) $id] ?? [];
                } else {
                    $snapshot = null;
                    foreach ($account['snapshots'] ?? [] as $candidate) {
                        if ($candidate['day'] <= $key && (null === $snapshot || $candidate['day'] > $snapshot['day'])) {
                            $snapshot = $candidate;
                        }
                    }
                    if (null === $snapshot) {
                        if ($nativeFrom > $end) {
                            continue;
                        }
                        ++$unknown;
                        continue;
                    }
                    $currencies = [$snapshot['currency'] => $snapshot['amount']];
                    foreach ($events as $event) {
                        if (
                            (string) $event['account_id'] !== (string) $id
                            || $event['day'] <= $snapshot['day']
                            || $event['day'] > $key
                            || !in_array($event['type'] ?? '', ['Deposit', 'Withdrawal', 'Transfer'], true)
                        ) {
                            continue;
                        }
                        $code              = $event['currency'];
                        $currencies[$code] = bcadd($currencies[$code] ?? '0', $event['amount'], 20);
                    }
                }
                ++$known;
                foreach ($currencies as $code => $balance) {
                    $byCurrency[$code] = bcadd($byCurrency[$code] ?? '0', $balance, 20);
                }
            }
            $uahTotal = '0';
            foreach ($byCurrency as $code => $balance) {
                if (0 === bccomp($balance, '0', 20)) {
                    continue;
                }
                if (!in_array($code, ['EUR', 'UAH', 'USD', 'CHF', 'USDT'], true)) {
                    $manual[$code] = true;
                }
                $uahTotal = bcadd($uahTotal, bcmul($balance, CurrencyBridge::rate($code, 'UAH', $quotes), 20), 20);
            }
            // Historical valuations replace, rather than add to, the native ledger.
            $value    = CurrencyBridge::convert($uahTotal, 'UAH', 'EUR', $quotes);
            $complete = 0 === $unknown;
            if ($complete && null === $coverage) {
                $coverage = $key;
            }
            $points[] = [
                'day'              => $key,
                'total'            => $complete ? $value : null,
                'known_total'      => $complete || $known > 0 ? $value : null,
                'unknown_accounts' => $unknown
            ];
        }

        return [
            'start'             => $start,
            'end'               => $end,
            'coverage_start'    => $coverage,
            'points'            => $points,
            'account_count'     => count($owned),
            'manual_currencies' => array_keys($manual)
        ];
    }

    private static function date(string $value): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (false === $date || $date->format('Y-m-d') !== $value) {
            throw new RuntimeException('Некоректна дата історії капіталу.');
        }

        return $date;
    }
}
