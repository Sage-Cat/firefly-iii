<?php

declare(strict_types=1);

namespace FireflyIII\Support\Reports;

use DateTimeImmutable;
use RuntimeException;

final class BalanceHistory
{
    public static function read(string $notes): array
    {
        $count = preg_match_all('/<!-- firefly-balance-history:v1\s*\n(.*?)\n-->/s', $notes, $matches);
        if (0 === $count) {
            return ['snapshots' => [], 'ledger_from' => null];
        }
        if (1 !== $count) {
            throw new RuntimeException('Повтор блоку історії залишків рахунку.');
        }
        try {
            $data = json_decode($matches[1][0], true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new RuntimeException('Некоректний блок історії залишків.', 0, $exception);
        }
        if (!is_array($data) || 1 !== ($data['version'] ?? null) || !is_array($data['snapshots'] ?? null)) {
            throw new RuntimeException('Невідома структура історії залишків.');
        }
        $ledgerFrom = $data['ledger_from'] ?? null;
        if (null !== $ledgerFrom) {
            self::date($ledgerFrom);
        }
        $snapshots = [];
        foreach ($data['snapshots'] as $snapshot) {
            if (
                !is_array($snapshot)
                || !is_string($snapshot['currency'] ?? null)
                || !preg_match('/^[A-Z][A-Z0-9]{2,11}$/D', $snapshot['currency'])
                || !is_string($snapshot['amount'] ?? null)
                || !preg_match('/^-?\d+(?:\.\d+)?$/D', $snapshot['amount'])
            ) {
                throw new RuntimeException('Некоректний історичний залишок.');
            }
            self::date($snapshot['day'] ?? null);
            if (isset($snapshots[$snapshot['day']])) {
                throw new RuntimeException('Повтор дати історичного залишку.');
            }
            $snapshots[$snapshot['day']] = $snapshot;
        }
        ksort($snapshots);

        return ['snapshots' => array_values($snapshots), 'ledger_from' => $ledgerFrom];
    }

    private static function date(mixed $value): void
    {
        $date = is_string($value) ? DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;
        if (false === $date || $date->format('Y-m-d') !== $value) {
            throw new RuntimeException('Некоректна дата історичного залишку.');
        }
    }
}
