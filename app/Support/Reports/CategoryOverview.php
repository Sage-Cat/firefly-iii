<?php

declare(strict_types=1);

namespace FireflyIII\Support\Reports;

use Carbon\Carbon;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use FireflyIII\Helpers\Collector\GroupCollectorInterface;
use Illuminate\Support\Collection;
use RuntimeException;

final class CategoryOverview
{
    public static function build(array $journals, array $ownedIds, Carbon $start, Carbon $end, string $primaryCode, string $owner): array
    {
        [$start, $end] = $end < $start ? [$end, $start] : [$start, $end];
        $selected = self::select($journals, $ownedIds, $start->format('Y-m-d'), $end->format('Y-m-d'), $owner);
        $seen     = [];
        foreach ($selected as $row) {
            $id = (string) ($row['transaction_journal_id'] ?? $row['id'] ?? '');
            if ('' === $id || isset($seen[$id])) {
                throw new RuntimeException('Неоднозначна операція у графіку категорій.');
            }
            $seen[$id] = true;
        }
        $entries  = ExpenseProjection::categoryChartFrom($selected)[0]['entries'];
        $currency = ReportingCurrency::currency('UAH');
        $primary  = ReportingCurrency::currency($primaryCode);
        $result   = [];
        foreach ($entries as $category => $amount) {
            $result[] = [
                'label'                           => $category,
                'currency_id'                     => (string) $currency->id,
                'currency_name'                   => $currency->name,
                'currency_code'                   => $currency->code,
                'currency_symbol'                 => $currency->symbol,
                'currency_decimal_places'         => $currency->decimal_places,
                'primary_currency_id'             => (string) $primary->id,
                'primary_currency_name'           => $primary->name,
                'primary_currency_code'           => $primary->code,
                'primary_currency_symbol'         => $primary->symbol,
                'primary_currency_decimal_places' => $primary->decimal_places,
                'reporting_currency_code'         => 'UAH',
                'as_of'                           => ReportingCurrency::today()->format('Y-m-d'),
                'period'                          => null,
                'start_date'                      => $start->toAtomString(),
                'end_date'                        => $end->toAtomString(),
                'yAxisID'                         => 0,
                'type'                            => 'bar',
                'entries'                         => ['spent' => $amount, 'earned' => '0.00'],
                'pc_entries'                      => ['spent' => ReportingCurrency::convert($amount, 'UAH', $primaryCode), 'earned' => '0.00']
            ];
        }

        return $result;
    }

    public static function overview(Collection $accounts, Carbon $start, Carbon $end, object $user, object $group, string $primaryCode): array
    {
        if ($accounts->isEmpty()) {
            return [];
        }
        [$start, $end] = $end < $start ? [$end, $start] : [$start, $end];
        $collector = app(GroupCollectorInterface::class)
            ->setUser($user)
            ->setUserGroup($group)
            ->setRange($start->copy()->subDay()->startOfDay(), $end->copy()->addDay()->endOfDay())
            ->setXorAccounts($accounts)
            ->setTypes(['Withdrawal', 'Deposit'])
            ->withAccountInformation()
            ->withCategoryInformation();
        $rows = RefundProjection::annotate($collector->getExtractedJournals(), RefundProjection::metadata($user));

        return self::build($rows, $accounts->pluck('id')->all(), $start, $end, $primaryCode, (string) $group->id);
    }

    public static function select(array $journals, array $ownedIds, string $start, string $end, string $owner): array
    {
        $owned = array_fill_keys(array_map('strval', $ownedIds), true);

        return array_values(array_filter($journals, static function (array $row) use ($owned, $start, $end, $owner): bool {
            if (isset($row['user_group_id']) && (string) $row['user_group_id'] !== $owner) {
                return false;
            }
            $source      = (string) ($row['source_account_id'] ?? '');
            $destination = (string) ($row['destination_account_id'] ?? '');
            $withdrawal  = 'Withdrawal' === ($row['transaction_type_type'] ?? '') && isset($owned[$source]) && !isset($owned[$destination]);
            $refund      = RefundProjection::isRefund($row) && isset($owned[$destination]) && !isset($owned[$source]);

            return ($withdrawal || $refund) && self::day($row) >= $start && self::day($row) <= $end;
        }));
    }

    private static function day(array $row): string
    {
        $value = $row['date'] ?? $row['day'];
        $date  = $value instanceof DateTimeInterface
            ? DateTimeImmutable::createFromInterface($value)
            : new DateTimeImmutable((string) $value, new DateTimeZone('UTC'));

        return $date->setTimezone(new DateTimeZone('Europe/Kyiv'))->format('Y-m-d');
    }
}
