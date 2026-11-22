<?php

declare(strict_types=1);

namespace FireflyIII\Support\Reports;

use DateTimeImmutable;
use RuntimeException;
use JsonException;

/** Investment positions are owned asset accounts whose notes carry a firefly-investment block. */
final class InvestmentOverview
{
    public const KINDS      = ['bond' => 'Облігації', 'broker' => 'Брокерський рахунок', 'crypto' => 'Криптоактиви', 'other' => 'Інше'];
    private const FIELDS    = ['version', 'kind', 'target_share', 'maturity', 'yield_to_maturity', 'yield_basis',
        'income_category', 'purchases', 'source'];
    private const NUMBER    = '/^\d+(?:\.\d+)?$/D';
    private const MOVEMENTS = ['Deposit', 'Withdrawal', 'Transfer'];

    public static function read(string $notes): ?array
    {
        $count = preg_match_all('/<!-- firefly-investment:v1\s*\n(.*?)\n-->/s', $notes, $matches);
        if (0 === $count) {
            return null;
        }
        if (1 !== $count) {
            throw new RuntimeException('Повтор блоку інвестиційних даних рахунку.');
        }

        try {
            $data = json_decode($matches[1][0], true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Некоректний блок інвестиційних даних.', 0, $exception);
        }
        if (!is_array($data) || 1 !== ($data['version'] ?? null) || !is_string($data['kind'] ?? null)
            || !isset(self::KINDS[$data['kind']]) || [] !== array_diff(array_keys($data), self::FIELDS)) {
            throw new RuntimeException('Невідома структура інвестиційних даних.');
        }
        foreach (['target_share', 'yield_to_maturity'] as $field) {
            if (isset($data[$field]) && (!is_string($data[$field]) || !preg_match(self::NUMBER, $data[$field])
                    || bccomp($data[$field], '100', 16) > 0)) {
                throw new RuntimeException('Некоректний відсоток інвестиції.');
            }
        }
        if (array_key_exists('maturity', $data)) {
            self::date($data['maturity']);
        }
        foreach (['yield_basis', 'income_category', 'source'] as $field) {
            if (array_key_exists($field, $data) && (!is_string($data[$field]) || '' === trim($data[$field]))) {
                throw new RuntimeException('Некоректний текст інвестиційних даних.');
            }
        }
        if (array_key_exists('purchases', $data) && (!is_array($data['purchases']) || !array_is_list($data['purchases']))) {
            throw new RuntimeException('Некоректний список купівель.');
        }
        foreach ($data['purchases'] ?? [] as $purchase) {
            if (!is_array($purchase) || [] !== array_diff(array_keys($purchase), ['day', 'amount', 'currency'])
                || !is_string($purchase['amount'] ?? null) || !preg_match(self::NUMBER, $purchase['amount'])
                || !is_string($purchase['currency'] ?? null) || !preg_match('/^[A-Z][A-Z0-9]{2,11}$/D', $purchase['currency'])) {
                throw new RuntimeException('Некоректна купівля інвестиції.');
            }
            self::date($purchase['day'] ?? null);
        }

        return $data;
    }

    /**
     * Movements and income are limited to [start, today], the same window as the value history.
     *
     * @param array $accounts accounts as in DashboardLedger plus 'name' and 'profile' (read() result or null)
     */
    public static function build(array $accounts, array $legs, array $quotes, string $start, string $today): array
    {
        $first      = self::date($start);
        $last       = self::date($today);
        $positions  = [];
        $excluded   = [];
        foreach ($accounts as $id => $account) {
            if (null === ($account['profile'] ?? null)) {
                continue;
            }
            if ($account['net_worth'] ?? false) {
                $positions[(string) $id] = $account;
            } else {
                $excluded[] = $account['name'];
            }
        }
        sort($excluded, SORT_NATURAL | SORT_FLAG_CASE);
        uasort($positions, static fn (array $a, array $b): int => strnatcasecmp($a['name'], $b['name']) ?: strcmp((string) $a['id'], (string) $b['id']));
        $categories = array_filter(array_map(static fn (array $account): ?string => $account['profile']['income_category'] ?? null, $positions));
        if (count($categories) !== count(array_unique($categories))) {
            throw new RuntimeException('Одна категорія доходу призначена кільком інвестиціям.');
        }
        // A transfer between two positions moves money inside the group, so the group flow ignores it.
        $journals   = [];
        foreach ($legs as $leg) {
            if (isset($positions[(string) $leg['account_id']]) && 'Transfer' === ($leg['type'] ?? '')) {
                $journals[(string) ($leg['journal_id'] ?? $leg['id'])][(string) $leg['account_id']] = true;
            }
        }
        $capital    = NetWorthSeries::build($accounts, $legs, $quotes, $start, $today);
        $capitalEur = end($capital['points'])['total'] ?? null;
        $rows       = [];
        $days       = [];
        $totalEur   = '0';
        $totalUah   = '0';
        $flows      = [];
        foreach ($positions as $id => $account) {
            $series                = NetWorthSeries::build([$id => $account], $legs, $quotes, $start, $today);
            $eur                   = end($series['points'])['total'];
            if (null === $eur) {
                throw new RuntimeException('Немає оцінки інвестиції «'.$account['name'].'» на сьогодні.');
            }
            foreach ($series['points'] as $point) {
                $days[$point['day']][$id] = $point['total'];
            }
            [$balances, $valuedOn] = self::state($account, $legs, $today);
            $native                = '0';
            $uah                   = '0';
            foreach ($balances as $code => $balance) {
                $native = bcadd($native, bcmul($balance, CurrencyBridge::rate($code, $account['currency'], $quotes), 20), 20);
                $uah    = bcadd($uah, bcmul($balance, CurrencyBridge::rate($code, 'UAH', $quotes), 20), 20);
            }
            $foreign               = array_values(array_diff(array_keys(array_filter($balances, static fn (string $value): bool => 0 !== bccomp($value, '0', 20))), [$account['currency']]));
            $profile               = $account['profile'];
            $category              = $profile['income_category'] ?? null;
            $row                   = ['id'     => (string) $id, 'name' => $account['name'], 'kind' => $profile['kind'],
                'kind_label' => self::KINDS[$profile['kind']], 'currency' => $account['currency'],
                'native'     => self::round($native), 'uah' => self::round($uah), 'eur' => $eur, 'foreign_currencies' => $foreign,
                'valued_on'  => $valuedOn, 'target_share' => $profile['target_share'] ?? null, 'bond' => null,
                'income'     => [], 'contributed' => '0', 'withdrawn' => '0', 'source' => $profile['source'] ?? null];
            foreach ($legs as $leg) {
                if ($leg['day'] < $start || $leg['day'] > $today) {
                    continue;
                }
                if (null !== $category && 'Deposit' === ($leg['type'] ?? '') && $category === ($leg['category_name'] ?? null)
                    && isset($accounts[(string) $leg['account_id']]) && bccomp($leg['amount'], '0', 16) > 0) {
                    $row['income'][$leg['currency']] = bcadd($row['income'][$leg['currency']] ?? '0', $leg['amount'], 16);
                    self::flow($flows, $leg, 'income', $quotes);
                }
                if ((string) $leg['account_id'] !== (string) $id || 'Transfer' !== ($leg['type'] ?? '')) {
                    continue;
                }
                $field       = bccomp($leg['amount'], '0', 16) > 0 ? 'contributed' : 'withdrawn';
                $row[$field] = bcadd($row[$field], ltrim($leg['amount'], '-'), 16);
                if (1 === count($journals[(string) ($leg['journal_id'] ?? $leg['id'])] ?? [])) {
                    self::flow($flows, $leg, $field, $quotes);
                }
            }
            foreach ($row['income'] as $code => $value) {
                $row['income'][$code] = self::round($value);
            }
            $row['contributed']    = self::round($row['contributed']);
            $row['withdrawn']      = self::round($row['withdrawn']);
            $row['bond']           = self::bond($profile, $account['currency'], $today);
            $totalEur              = bcadd($totalEur, $eur, 2);
            $totalUah              = bcadd($totalUah, $row['uah'], 2);
            $rows[]                = $row;
        }
        foreach ($rows as &$row) {
            $row['allocation']    = bccomp($totalEur, '0', 2) <= 0 ? null : self::percent($row['eur'], $totalEur);
            $row['capital_share'] = null === $capitalEur || bccomp($capitalEur, '0', 2) <= 0 ? null
                : self::percent($row['eur'], $capitalEur);
        }
        unset($row);
        $history    = [];
        foreach ($days as $day => $values) {
            $complete  = !in_array(null, $values, true);
            $history[] = ['day' => $day, 'values' => $values,
                'total'         => $complete ? array_reduce($values, static fn ($sum, $value) => bcadd($sum, $value, 2), '0.00') : null];
        }
        ksort($flows);

        return ['rows'      => $rows, 'excluded' => $excluded, 'total_eur' => $totalEur, 'total_uah' => $totalUah, 'capital_eur' => $capitalEur,
            'capital_share' => null === $capitalEur || bccomp($capitalEur, '0', 2) <= 0 ? null : self::percent($totalEur, $capitalEur),
            'history'       => $history, 'incomplete_days' => count(array_filter($history, static fn (array $point): bool => null === $point['total'])),
            'flows'         => array_values($flows), 'start' => $first->format('Y-m-d'), 'today' => $last->format('Y-m-d')];
    }

    /**
     * Balances per currency and the date they were last established, by the same rules as NetWorthSeries:
     * before known_from the latest snapshot plus later movements, from known_from the full ledger.
     */
    private static function state(array $account, array $legs, string $day): array
    {
        $id        = (string) $account['id'];
        $known     = $account['known_from'] ?? null;
        $snapshots = array_values(array_filter($account['snapshots'] ?? [], static fn (array $snapshot): bool => $snapshot['day'] <= $day));
        usort($snapshots, static fn (array $a, array $b): int => strcmp($a['day'], $b['day']));
        $own       = array_values(array_filter($legs, static fn (array $leg): bool => (string) $leg['account_id'] === $id && $leg['day'] <= $day));
        $balances  = [];
        $valuedOn  = null;
        if (null !== $known && $day < $known) {
            $snapshot                        = end($snapshots);
            if (false === $snapshot) {
                // NetWorthSeries counts an account with a later start and no valuation as not yet held.
                return [[], null];
            }
            $balances[$snapshot['currency']] = $snapshot['amount'];
            $valuedOn                        = $snapshot['day'];
            foreach ($own as $leg) {
                if ($leg['day'] > $snapshot['day'] && in_array($leg['type'] ?? '', self::MOVEMENTS, true)) {
                    $balances[$leg['currency']] = bcadd($balances[$leg['currency']] ?? '0', $leg['amount'], 20);
                    $valuedOn                   = max($valuedOn, $leg['day']);
                }
            }

            return [$balances, $valuedOn];
        }
        foreach ($own as $leg) {
            $balances[$leg['currency']] = bcadd($balances[$leg['currency']] ?? '0', $leg['amount'], 20);
            $set                        = $leg['day'];
            if ('Opening balance' === ($leg['type'] ?? '')) {
                // An opening balance copied from an earlier valuation keeps that valuation's date.
                foreach (array_reverse($snapshots) as $snapshot) {
                    if ($snapshot['day'] <= $leg['day']) {
                        if ($snapshot['currency'] === $leg['currency'] && 0 === bccomp($snapshot['amount'], $leg['amount'], 20)) {
                            $set = $snapshot['day'];
                        }

                        break;
                    }
                }
            }
            $valuedOn                   = null === $valuedOn ? $set : max($valuedOn, $set);
        }
        // A later valuation confirms the ledger only when it equals the ledger balance on its day.
        foreach ($snapshots as $snapshot) {
            if ((null !== $known && $snapshot['day'] < $known) || (null !== $valuedOn && $snapshot['day'] <= $valuedOn)) {
                continue;
            }
            $ledger = [$snapshot['currency'] => '0'];
            foreach ($own as $leg) {
                if ($leg['day'] <= $snapshot['day']) {
                    $ledger[$leg['currency']] = bcadd($ledger[$leg['currency']] ?? '0', $leg['amount'], 20);
                }
            }
            $other  = array_filter($ledger, static fn (string $value, string $code): bool => $code !== $snapshot['currency'] && 0 !== bccomp($value, '0', 20), ARRAY_FILTER_USE_BOTH);
            if ([] === $other && 0 === bccomp($ledger[$snapshot['currency']], $snapshot['amount'], 20)) {
                $valuedOn = $snapshot['day'];
            }
        }

        return [$balances, $valuedOn];
    }

    private static function bond(array $profile, string $currency, string $today): ?array
    {
        if ('bond' !== $profile['kind'] || !isset($profile['maturity'])) {
            return null;
        }
        $maturity  = self::date($profile['maturity']);
        $now       = self::date($today);
        $invested  = '0';
        $projected = null;
        $terms     = [];
        foreach ($profile['purchases'] ?? [] as $purchase) {
            if ($purchase['currency'] !== $currency) {
                throw new RuntimeException('Валюта купівлі не збігається з валютою рахунку облігацій.');
            }
            $invested = bcadd($invested, $purchase['amount'], 16);
            $days     = self::date($purchase['day'])->diff($maturity);
            if (isset($profile['yield_to_maturity']) && !$days->invert) {
                // Simple annual yield over the holding period: an estimate, not a coupon schedule.
                $part      = bcdiv(bcmul(bcmul($purchase['amount'], $profile['yield_to_maturity'], 16), (string) $days->days, 16), '36500', 16);
                $projected = bcadd($projected ?? '0', $part, 16);
                $terms[]   = ['amount' => $purchase['amount'], 'days' => $days->days];
            }
        }
        $left      = $now->diff($maturity);

        return ['maturity' => $profile['maturity'], 'days_left' => $left->invert ? 0 : $left->days,
            'yield'        => $profile['yield_to_maturity'] ?? null, 'yield_basis' => $profile['yield_basis'] ?? null,
            'invested'     => self::round($invested), 'projected' => null === $projected ? null : self::round($projected),
            'terms'        => $terms, 'currency' => $currency];
    }

    private static function flow(array &$flows, array $leg, string $field, array $quotes): void
    {
        $month                 = substr($leg['day'], 0, 7);
        $flows[$month] ??= ['month' => $month, 'income' => '0.00', 'contributed' => '0.00', 'withdrawn' => '0.00'];
        $eur                   = CurrencyBridge::convert(ltrim($leg['amount'], '-'), $leg['currency'], 'EUR', $quotes);
        $flows[$month][$field] = bcadd($flows[$month][$field], $eur, 2);
    }

    /** Half away from zero at cents; bcmath alone truncates. */
    private static function round(string $value): string
    {
        $half = bccomp($value, '0', 20) < 0 ? '-0.005' : '0.005';

        return bcadd(bcadd($value, $half, 20), '0', 2);
    }

    private static function percent(string $part, string $whole): string
    {
        return self::round(bcdiv(bcmul($part, '100', 16), $whole, 16));
    }

    private static function date(mixed $value): DateTimeImmutable
    {
        $date = is_string($value) ? DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;
        if (false === $date || $date->format('Y-m-d') !== $value) {
            throw new RuntimeException('Некоректна дата інвестиційних даних.');
        }

        return $date;
    }
}
