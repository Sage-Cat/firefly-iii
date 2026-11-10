<?php

declare(strict_types=1);

namespace FireflyIII\Support\Reports;

use DateTimeImmutable;
use RuntimeException;

final class AccountingPeriod
{
    public static function boundary(): null|string
    {
        $value = getenv('ACCOUNTING_START_DATE');
        if (false === $value || '' === $value) {
            return null;
        }
        self::validate($value);

        return $value;
    }

    public static function start(string $start, string $end): string
    {
        self::validate($start);
        self::validate($end);
        $boundary = self::boundary();
        if ($end < $start || null !== $boundary && $end < $boundary) {
            throw new RuntimeException('Обраний період закінчується до початку обліку.');
        }

        return null === $boundary ? $start : max($start, $boundary);
    }

    private static function validate(string $value): void
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) || false === $date || $date->format('Y-m-d') !== $value) {
            throw new RuntimeException('Некоректна дата початку обліку або періоду.');
        }
    }
}
