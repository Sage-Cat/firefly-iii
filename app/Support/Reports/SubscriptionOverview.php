<?php

declare(strict_types=1);

namespace FireflyIII\Support\Reports;

use FireflyIII\Models\Bill;
use RuntimeException;

final class SubscriptionOverview
{
    private const PERIODS = ['yearly' => '1', 'half-year' => '2', 'quarterly' => '4', 'monthly' => '12', 'weekly' => '52.17', 'daily' => '365.24'];

    /** Monthly equivalents use original amounts, preserving the unrounded UAH bridge. */
    public static function build(array $bills, array $quotes, string $day): array
    {
        $rows  = [];
        $seen  = [];
        $total = '0';
        foreach ($bills as $bill) {
            if (!($bill['active'] ?? false)) {
                continue;
            }
            $id = (string) ($bill['id'] ?? '');
            if ('' === $id || isset($seen[$id])) {
                throw new RuntimeException('Повтор або відсутній ідентифікатор підписки.');
            }
            $seen[$id] = true;
            $minimum   = $bill['minimum'] ?? null;
            $maximum   = $bill['maximum'] ?? null;
            foreach ([$minimum, $maximum] as $value) {
                if (!is_string($value) || !preg_match('/^\d+(?:\.\d+)?$/D', $value)) {
                    throw new RuntimeException('Некоректна сума підписки.');
                }
            }
            if (bccomp($minimum, $maximum, 16) > 0) {
                throw new RuntimeException('Мінімальна сума підписки перевищує максимальну.');
            }
            $frequency = $bill['frequency'] ?? '';
            $skip      = $bill['skip'] ?? 0;
            $currency  = $bill['currency'] ?? null;
            if (!isset(self::PERIODS[$frequency]) || !is_int($skip) || $skip < 0) {
                throw new RuntimeException('Невідома періодичність підписки.');
            }
            if (!is_string($currency) || !preg_match('/^[A-Z][A-Z0-9]{2,11}$/D', $currency)) {
                throw new RuntimeException('Не визначено валюту підписки.');
            }
            $average = bcdiv(bcadd($minimum, $maximum, 32), '2', 32);
            $perYear = bcdiv(bcmul($average, self::PERIODS[$frequency], 32), bcadd((string) $skip, '1', 0), 32);
            $monthly = bcdiv($perYear, '12', 32);
            $uah     = bcmul($monthly, CurrencyBridge::rate($currency, 'UAH', $quotes), 32);
            $rows[]  = ['id' => $id, 'name' => (string) ($bill['name'] ?? ''), 'raw_uah' => $uah, 'source_currency' => $currency, 'source_monthly' => $monthly];
            $total   = bcadd($total, $uah, 32);
        }
        $uah = CurrencyBridge::convert($total, 'UAH', 'UAH', [], 2);
        $eur = '0.00';
        if ([] !== $rows) {
            $eur = CurrencyBridge::convert($total, 'UAH', 'EUR', $quotes, 2);
        }
        // Allocate displayed cents so the legend and chart add up to the rounded total.
        $rows = self::allocateCents($rows, $uah);
        usort(
            $rows,
            static fn(array $left, array $right): int => (
                -bccomp($left['raw_uah'], $right['raw_uah'], 32) ?: strnatcasecmp($left['name'], $right['name']) ?: strcmp($left['id'], $right['id'])
            )
        );
        foreach ($rows as $index => &$row) {
            $row['color'] = $index % 8;
        }
        unset($row);

        return ['rows' => $rows, 'uah' => $uah, 'eur' => $eur, 'count' => count($rows), 'day' => $day, 'error' => null];
    }

    public static function forUser($user): array
    {
        $day = ReportingCurrency::today()->format('d.m.Y');
        try {
            $bills = [];
            foreach (Bill::query()
                ->where('user_group_id', $user->user_group_id)
                ->where('active', true)
                ->with('transactionCurrency')
                ->orderBy('name')
                ->orderBy('id')
                ->get() as $bill) {
                $bills[] = [
                    'id'        => (string) $bill->id,
                    'name'      => $bill->name,
                    'active'    => (bool) $bill->active,
                    'minimum'   => (string) $bill->amount_min,
                    'maximum'   => (string) $bill->amount_max,
                    'currency'  => $bill->transactionCurrency?->code,
                    'frequency' => $bill->repeat_freq,
                    'skip'      => $bill->skip
                ];
            }

            return self::build($bills, [] === $bills ? [] : ReportingCurrency::quotes(), $day);
        } catch (RuntimeException $exception) {
            return ['rows' => [], 'uah' => null, 'eur' => null, 'count' => 0, 'day' => $day, 'error' => $exception->getMessage()];
        } catch (\Throwable) {
            return ['rows' => [], 'uah' => null, 'eur' => null, 'count' => 0, 'day' => $day, 'error' => 'Не вдалося обчислити вартість підписок.'];
        }
    }

    private static function allocateCents(array $rows, string $total): array
    {
        $floor = '0';
        foreach ($rows as $index => &$row) {
            $row['uah']       = bcadd($row['raw_uah'], '0', 2);
            $row['remainder'] = bcsub($row['raw_uah'], $row['uah'], 32);
            $row['position']  = $index;
            $floor            = bcadd($floor, $row['uah'], 2);
        }
        unset($row);
        $cents = (int) bcmul(bcsub($total, $floor, 2), '100', 0);
        usort(
            $rows,
            static fn(array $left, array $right): int => -bccomp($left['remainder'], $right['remainder'], 32) ?: $left['position'] <=> $right['position']
        );
        foreach ($rows as $index => &$row) {
            if ($index < $cents) {
                $row['uah'] = bcadd($row['uah'], '0.01', 2);
            }
            unset($row['remainder'], $row['position']);
        }
        unset($row);

        return $rows;
    }
}
