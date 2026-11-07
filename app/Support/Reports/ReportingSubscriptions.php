<?php

declare(strict_types=1);

namespace FireflyIII\Support\Reports;

use Carbon\Carbon;
use FireflyIII\Repositories\Account\AccountRepositoryInterface;
use FireflyIII\Support\Facades\Amount;

final class ReportingSubscriptions
{
    /** Original paid debit currencies and original bill forecast currencies stay separate. */
    public static function nativeRows($repository, Carbon $start, Carbon $end, null|array $ledger = null): array
    {
        $ledger   ??= DashboardLedger::data();
        $journals = [];
        foreach ($ledger['legs'] as $leg) {
            if (isset($ledger['accounts'][(string) $leg['account_id']])) {
                $journals[(string) $leg['journal_id']][(string) $leg['id']] = $leg;
            }
        }
        $withdrawals = [];
        foreach ($journals as $id => $legs) {
            if (1 !== count($legs)) {
                continue;
            }
            $leg = reset($legs);
            if (
                'Withdrawal' === $leg['type']
                && bccomp($leg['amount'], '0', 32) < 0
                && $leg['day'] >= $start->format('Y-m-d')
                && $leg['day'] <= $end->format('Y-m-d')
            ) {
                $withdrawals[(string) $id] = $leg;
            }
        }
        $sums = [];
        $paid = [];
        foreach ($repository->getBills() as $bill) {
            $code        = $bill->transactionCurrency->code;
            $sums[$code] ??= ['paid' => '0', 'unpaid' => '0'];
            $counted     = [];
            $matched     = $bill->transactionJournals()->whereHas('transactionGroup')->after($start->copy()->subDay())->before($end->copy()->addDay())->get();
            foreach ($matched as $journal) {
                $id = (string) $journal->id;
                if (!isset($withdrawals[$id]) || isset($counted[$id])) {
                    continue;
                }
                $counted[$id] = true;
                if (isset($paid[$id])) {
                    continue;
                }
                $paid[$id]                      = true;
                $leg                            = $withdrawals[$id];
                $sums[$leg['currency']]         ??= ['paid' => '0', 'unpaid' => '0'];
                $sums[$leg['currency']]['paid'] = bcsub($sums[$leg['currency']]['paid'], $leg['amount'], 32);
            }
            if ($bill->active) {
                $left                  = max(0, $repository->getPayDatesInRange($bill, $start->copy(), $end->copy())->count() - count($counted));
                $average               = bcdiv(bcadd((string) $bill->amount_min, (string) $bill->amount_max, 32), '2', 32);
                $sums[$code]['unpaid'] = bcsub($sums[$code]['unpaid'], bcmul($average, (string) $left, 32), 32);
            }
        }
        $rows = [];
        foreach ($sums as $code => $values) {
            $currency = ReportingCurrency::currency($code);
            foreach ($values as $kind => $amount) {
                $amount = bcadd($amount, '0', $currency->decimal_places);
                $rows[] = [
                    'key'                     => 'bills-' . $kind . '-in-' . $code,
                    'title'                   => trans('firefly.box_bill_' . $kind . '_in_currency', ['currency' => $currency->symbol]),
                    'monetary_value'          => $amount,
                    'currency_id'             => (string) $currency->id,
                    'currency_code'           => $currency->code,
                    'currency_symbol'         => $currency->symbol,
                    'currency_decimal_places' => $currency->decimal_places,
                    'value_parsed'            => Amount::formatAnything($currency, $amount, false),
                    'sub_title'               => ''
                ];
            }
        }

        return $rows;
    }

    public static function rows($repository, Carbon $start, Carbon $end): array
    {
        $currency = ReportingCurrency::currency('UAH');
        $accounts = app(AccountRepositoryInterface::class);
        $accounts->setUser(auth()->user());
        $owned = array_fill_keys(array_map('strval', $accounts->getAccountsByType(config('firefly.accountTypesByIdentifier.asset'))->pluck('id')->all()), true);

        $paid   = '0.00';
        $unpaid = '0.00';
        foreach ($repository->getBills() as $bill) {
            $count    = 0;
            $journals = $bill->transactionJournals()->whereHas('transactionGroup')->after($start->copy()->subDay())->before($end->copy()->addDay())->get();
            foreach ($journals as $journal) {
                $day = Carbon::parse($journal->date)->setTimezone('Europe/Kyiv')->format('Y-m-d');
                if ($day < $start->format('Y-m-d') || $day > $end->format('Y-m-d') || 'Withdrawal' !== $journal->transactionType->type) {
                    continue;
                }
                $leg = $journal->transactions()->where('amount', '<', 0)->first();
                if (null === $leg || !isset($owned[(string) $leg->account_id])) {
                    continue;
                }
                $paid = bcadd($paid, ReportingCurrency::convert(ltrim((string) $leg->amount, '-'), $leg->transactionCurrency->code, 'UAH'), 2);
                ++$count;
            }
            if ($bill->active) {
                $left = max(0, $repository->getPayDatesInRange($bill, $start->copy(), $end->copy())->count() - $count);
                if ($left > 0) {
                    $average = bcdiv(bcadd((string) $bill->amount_min, (string) $bill->amount_max, 16), '2', 16);
                    $unit    = ReportingCurrency::convert($average, $bill->transactionCurrency->code, 'UAH');
                    $unpaid  = bcadd($unpaid, bcmul($unit, (string) $left, 2), 2);
                }
            }
        }
        $result = [];
        foreach (['paid' => $paid, 'unpaid' => bcsub('0', $unpaid, 2)] as $kind => $sum) {
            $result[] = [
                'key'                     => 'bills-' . $kind . '-in-UAH',
                'title'                   => trans('firefly.box_bill_' . $kind . '_in_currency', ['currency' => $currency->symbol]),
                'monetary_value'          => $sum,
                'currency_id'             => (string) $currency->id,
                'currency_code'           => 'UAH',
                'currency_symbol'         => $currency->symbol,
                'currency_decimal_places' => 2,
                'value_parsed'            => Amount::formatAnything($currency, $sum, false),
                'sub_title'               => ''
            ];
        }

        return $result;
    }
}
