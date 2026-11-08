<?php

declare(strict_types=1);

namespace FireflyIII\Support\Reports;

use Carbon\Carbon;
use FireflyIII\Helpers\Collector\GroupCollectorInterface;
use FireflyIII\Models\CurrencyExchangeRate;
use FireflyIII\Models\TransactionCurrency;
use FireflyIII\Repositories\Account\AccountRepositoryInterface;
use GuzzleHttp\Client;
use RuntimeException;

final class ReportingCurrency
{
    public static function cacheSuffix(): string
    {
        if (!self::isDisplayRequest()) {
            return '';
        }
        $request = request();
        $target  = preg_match('~budget|withdrawal|expense|categories|chart/category/~i', $request->path()) ? 'UAH' : 'EUR';
        if ('reports/profit' === $request->path()) {
            $target = 'UAH' === $request->query('currency', 'UAH') ? 'UAH' : 'EUR';
        }

        return 'uah-bridge-refunds-v3:' . self::today()->format('Y-m-d') . ':' . $target;
    }

    public static function convert(string $amount, string $from, string $to): string
    {
        return CurrencyBridge::convert($amount, $from, $to, $from === $to ? [] : self::quotes(), self::currency($to)->decimal_places);
    }

    public static function currency(string $code): TransactionCurrency
    {
        $key = 'reporting-currency-model:' . $code;
        if (request()->attributes->has($key)) {
            return request()->attributes->get($key);
        }
        $currency = TransactionCurrency::query()->where('code', $code)->first();
        if (null === $currency) {
            throw new RuntimeException('Валюта ' . $code . ' недоступна.');
        }

        request()->attributes->set($key, $currency);

        return $currency;
    }

    public static function isDisplayRequest(): bool
    {
        if (!app()->bound('request')) {
            return false;
        }
        $request = request();

        return null !== $request->route() && in_array($request->method(), ['GET', 'HEAD'], true) && auth()->check();
    }

    public static function projectJournal(array $journal): array
    {
        if (!self::isDisplayRequest() || 'reports/profit' === request()->path()) {
            return $journal;
        }
        $key     = 'reporting-actual-primary:' . auth()->user()->user_group_id;
        $primary = request()->attributes->get($key);
        if (null === $primary) {
            $primary = auth()->user()->userGroup->currencies()->where('group_default', true)->first();
            request()->attributes->set($key, $primary);
        }
        if (null === $primary) {
            throw new RuntimeException('Основна валюта адміністрації недоступна.');
        }
        if (isset($journal['amount'], $journal['currency_id'])) {
            $code                 = $journal['currency_code'] ?? self::code((int) $journal['currency_id']);
            $journal['pc_amount'] = self::convert((string) $journal['amount'], $code, $primary->code);
        }
        if (isset($journal['foreign_amount'], $journal['foreign_currency_id'])) {
            $code                         = $journal['foreign_currency_code'] ?? self::code((int) $journal['foreign_currency_id']);
            $journal['pc_foreign_amount'] = self::convert((string) $journal['foreign_amount'], $code, $primary->code);
        }

        return $journal;
    }

    /** Quotes are UAH per unit, scoped to the authenticated administration. */
    public static function quotes(): array
    {
        $day = self::today()->format('Y-m-d');
        $key = 'reporting-quotes:' . auth()->user()->user_group_id . ':' . $day;
        if (request()->attributes->has($key)) {
            return request()->attributes->get($key);
        }
        $currencies = TransactionCurrency::query()->whereIn('code', ['UAH', 'EUR', 'USD', 'CHF', 'USDT'])->get()->keyBy('id');
        $uah        = $currencies->firstWhere('code', 'UAH');
        if (null === $uah) {
            throw new RuntimeException('Гривня недоступна для перерахунку.');
        }
        $quotes = ['UAH' => '1'];
        $rows   = CurrencyExchangeRate::query()
            ->where('user_group_id', auth()->user()->user_group_id)
            ->where('to_currency_id', $uah->id)
            ->whereIn('from_currency_id', $currencies->keys()->all())
            ->whereDate('date', $day)
            ->get();
        foreach ($rows as $row) {
            $code = $currencies[$row->from_currency_id]->code;
            if ('USDT' === $code) {
                continue;
            }
            $value = (string) $row->rate;
            if (isset($quotes[$code]) && bccomp($quotes[$code], $value, 12) !== 0) {
                throw new RuntimeException('Суперечливі курси ' . $code . ' на ' . $day . '.');
            }
            $quotes[$code] = $value;
        }
        // After waking before the updater, read the exact current NBU day.
        // This request-only fallback never writes financial or statistical data.
        if (count(array_intersect(['EUR', 'USD', 'CHF'], array_keys($quotes))) !== 3) {
            try {
                $client   = new Client(['timeout' => 15, 'allow_redirects' => false, 'proxy' => '']);
                $date     = self::today()->format('Ymd');
                $response = $client->get('https://bank.gov.ua/NBU_Exchange/exchange_site', ['query' => [
                    'start' => $date,
                    'end'   => $date,
                    'sort'  => 'exchangedate',
                    'order' => 'asc',
                    'json'  => ''
                ]]);
                $data   = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
                $quotes = array_replace($quotes, CurrencyBridge::nbuQuotes($data, self::today()->format('d.m.Y')));
            } catch (\Throwable $exception) {
                throw new RuntimeException('Не вдалося отримати підтверджені курси НБУ на сьогодні.', 0, $exception);
            }
        }
        foreach (['EUR', 'USD', 'CHF'] as $code) {
            CurrencyBridge::rate($code, 'UAH', $quotes);
        }
        // Owner-selected valuation convention: one USDT uses the NBU USD quote.
        $quotes['USDT'] = $quotes['USD'];
        request()->attributes->set($key, $quotes);

        return $quotes;
    }

    public static function rate(string $from, string $to): string
    {
        return CurrencyBridge::rate($from, $to, $from === $to ? [] : self::quotes());
    }

    public static function summaryRows(Carbon $start, Carbon $end): array
    {
        $repository = app(AccountRepositoryInterface::class);
        $repository->setUser(auth()->user());
        $assets = $repository->getAccountsByType(config('firefly.accountTypesByIdentifier.asset'));
        $rows   = [];
        if (!$assets->isEmpty()) {
            $set = app(GroupCollectorInterface::class)
                ->setUser(auth()->user())
                ->setAccounts($assets)
                ->setTypes(['Deposit', 'Withdrawal'])
                ->withAccountInformation()
                ->withCategoryInformation()
                ->getExtractedJournals();
            foreach ($set as $journal) {
                $rows[] = [
                    'id'             => $journal['transaction_journal_id'],
                    'type'           => $journal['transaction_type_type'],
                    'day'            => $journal['date']->copy()->setTimezone('Europe/Kyiv')->format('Y-m-d'),
                    'source_id'      => $journal['source_account_id'],
                    'destination_id' => $journal['destination_account_id'],
                    'currency'       => $journal['currency_code'],
                    'amount'         => (string) $journal['amount'],
                    'category_name'  => $journal['category_name'] ?? null
                ];
            }
        }
        $rows        = RefundProjection::annotate($rows, RefundProjection::metadata(auth()->user()));
        $parameters  = [$rows, self::quotes(), $assets->pluck('id')->all(), $start->format('Y-m-d'), $end->format('Y-m-d')];
        $eur         = ProfitSummary::build(...[...$parameters, 'EUR']);
        $uah         = ProfitSummary::build(...[...$parameters, 'UAH']);
        $eurCurrency = self::currency('EUR');
        $uahCurrency = self::currency('UAH');
        $format      = static fn($currency, $value) => \FireflyIII\Support\Facades\Amount::formatAnything($currency, $value, false);
        $row         = static fn($key, $currency, $value, $subtitle = '') => [
            'key'                     => $key . '-in-' . $currency->code,
            'title'                   => trans('firefly.box_' . $key . '_in_currency', ['currency' => $currency->symbol]),
            'monetary_value'          => $value,
            'currency_id'             => (string) $currency->id,
            'currency_code'           => $currency->code,
            'currency_symbol'         => $currency->symbol,
            'currency_decimal_places' => $currency->decimal_places,
            'value_parsed'            => $format($currency, $value),
            'sub_title'               => $subtitle
        ];

        return [
            $row(
                'balance',
                $eurCurrency,
                $eur['totals']['net'],
                'Витрати: ' . $format($uahCurrency, $uah['totals']['expenses']) . '; доходи: ' . $format($eurCurrency, $eur['totals']['income'])
            ),
            $row('spent', $uahCurrency, bcsub('0', $uah['totals']['expenses'], 2)),
            $row('earned', $eurCurrency, $eur['totals']['income'])
        ];
    }

    public static function today(): Carbon
    {
        $key = 'reporting-valuation-day';
        if (app()->bound('request')) {
            $day = request()->attributes->get($key);
            if (null === $day) {
                $day = Carbon::today('Europe/Kyiv')->format('Y-m-d');
                request()->attributes->set($key, $day);
            }

            return Carbon::createFromFormat('!Y-m-d', $day, 'Europe/Kyiv');
        }

        return Carbon::today('Europe/Kyiv');
    }

    private static function code(int $id): string
    {
        $currency = TransactionCurrency::query()->find($id);
        if (null === $currency) {
            throw new RuntimeException('Валюта операції недоступна.');
        }

        return $currency->code;
    }
}
