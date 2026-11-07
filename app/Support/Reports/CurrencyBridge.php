<?php

declare(strict_types=1);

namespace FireflyIII\Support\Reports;

use RuntimeException;

final class CurrencyBridge
{
    public static function convert(string $amount, string $from, string $to, array $uahQuotes, int $places = 2): string
    {
        self::number($amount);
        if ($places < 0 || $places > 8) {
            throw new RuntimeException('Некоректна точність суми.');
        }
        $negative = bccomp($amount, '0', 16) < 0;
        $value    = ltrim($amount, '-');
        if ($from !== $to && 0 !== bccomp($value, '0', 16)) {
            // Keep the UAH intermediate precise; round only the final currency.
            if ('UAH' !== $from) {
                $value = bcmul($value, self::quote($from, $uahQuotes), 20);
            }
            if ('UAH' !== $to) {
                $value = bcdiv($value, self::quote($to, $uahQuotes), 20);
            }
        }
        $half  = '0.' . str_repeat('0', $places) . '5';
        $value = bcadd($value, $half, $places);

        return $negative ? bcsub('0', $value, $places) : $value;
    }

    public static function nbuQuotes(array $rows, string $day): array
    {
        $quotes = ['UAH' => '1'];
        foreach ($rows as $row) {
            if (!is_array($row) || !in_array($row['cc'] ?? '', ['EUR', 'USD', 'CHF'], true)) {
                continue;
            }
            if ('Y' === ($row['special'] ?? null)) {
                continue;
            }
            if (
                !in_array($row['special'] ?? null, [null, 'N'], true)
                || ($row['exchangedate'] ?? '') !== $day
                || !isset($row['rate'], $row['units'], $row['rate_per_unit'])
            ) {
                throw new RuntimeException('НБУ не підтвердив курс на день побудови.');
            }
            $code  = $row['cc'];
            $value = (string) $row['rate_per_unit'];
            self::number((string) $row['units']);
            self::number((string) $row['rate']);
            self::number($value);
            if (
                bccomp((string) $row['units'], '0', 16) <= 0
                || bccomp((string) $row['rate'], '0', 16) <= 0
                || bccomp($value, '0', 16) <= 0
                || bccomp(bcdiv((string) $row['rate'], (string) $row['units'], 20), $value, 12) !== 0
                || isset($quotes[$code]) && bccomp($quotes[$code], $value, 12) !== 0
            ) {
                throw new RuntimeException('Некоректні одиниці або суперечливий курс НБУ.');
            }
            $quotes[$code] = $value;
        }
        foreach (['EUR', 'USD', 'CHF'] as $code) {
            self::quote($code, $quotes);
        }

        return $quotes;
    }

    public static function rate(string $from, string $to, array $uahQuotes): string
    {
        if ($from === $to) {
            return '1';
        }

        return bcdiv(self::quote($from, $uahQuotes), self::quote($to, $uahQuotes), 16);
    }

    private static function number(string $value): void
    {
        if (!preg_match('/^-?\d+(?:\.\d+)?$/D', $value)) {
            throw new RuntimeException('Некоректна сума або курс валюти.');
        }
    }

    private static function quote(string $code, array $uahQuotes): string
    {
        $value = 'UAH' === $code ? '1' : $uahQuotes[$code] ?? null;
        if (!is_string($value)) {
            throw new RuntimeException('Немає поточного курсу ' . $code . ' до гривні.');
        }
        self::number($value);
        if (bccomp($value, '0', 16) <= 0) {
            throw new RuntimeException('Курс валюти має бути додатним.');
        }

        return $value;
    }
}
