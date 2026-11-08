<?php

declare(strict_types=1);

namespace FireflyIII\Models {
    final class TransactionCurrency
    {
        public int    $id             = 1;
        public string $code           = 'EUR';
        public int    $decimal_places = 2;
    }
}

namespace {
    $fixtureAppRoot = getenv('FIREFLY_TEST_APP_ROOT') ?: dirname(__DIR__, 2);
    require $fixtureAppRoot . '/vendor/autoload.php';
    require dirname(__DIR__, 2) . '/app/Support/Reports/CurrencyBridge.php';
    require dirname(__DIR__, 2) . '/app/Support/Reports/ReportingCurrency.php';

    use Carbon\Carbon;
    use FireflyIII\Support\Reports\ReportingCurrency;
    use Illuminate\Container\Container;
    use Illuminate\Http\Request;

    $app = new Container();
    Container::setInstance($app);
    $factory = new class implements \Illuminate\Contracts\Auth\Factory {
        public function check()
        {
            return true;
        }

        public function guard($name = null)
        {
            return $this;
        }

        public function shouldUse($name) {}

        public function user()
        {
            return (object) ['user_group_id' => 42];
        }
    };
    $app->instance(\Illuminate\Contracts\Auth\Factory::class, $factory);
    $request = Request::create('http://localhost/transactions/withdrawal', 'GET');
    $request->setRouteResolver(static fn() => new \stdClass());
    $app->instance('request', $request);
    Carbon::setTestNow(Carbon::parse('2026-11-07 23:59:59', 'Europe/Kyiv'));
    $frozen = ReportingCurrency::today();
    $frozen->addDays(5);
    Carbon::setTestNow(Carbon::parse('2026-11-08 00:00:01', 'Europe/Kyiv'));
    if (ReportingCurrency::today()->format('Y-m-d') !== '2026-11-07') {
        throw new \RuntimeException('One request must not cross valuation days or share a mutable Carbon.');
    }
    if (!str_contains(ReportingCurrency::cacheSuffix(), '2026-11-07:UAH')) {
        throw new \RuntimeException('The display cache must use the frozen date and expense context.');
    }
    $currency = new \FireflyIII\Models\TransactionCurrency();
    $request->attributes->set('reporting-currency-model:EUR', $currency);
    $request->attributes->set('reporting-actual-primary:42', $currency);
    $request->attributes->set('reporting-quotes:42:2026-11-07', ['UAH' => '1', 'EUR' => '50', 'USD' => '45', 'CHF' => '52']);
    $original = [
        'currency_id'           => 4,
        'currency_code'         => 'UAH',
        'amount'                => '-500',
        'pc_amount'             => '-999',
        'foreign_currency_id'   => 9,
        'foreign_currency_code' => 'USD',
        'foreign_amount'        => '-10',
        'pc_foreign_amount'     => '-888'
    ];
    $projected = ReportingCurrency::projectJournal($original);
    if ($projected['pc_amount'] !== '-10.00' || $projected['pc_foreign_amount'] !== '-9.00') {
        throw new \RuntimeException('The display projection must use source amounts and the current bridge.');
    }
    if ($original['pc_amount'] !== '-999' || $currency->code !== 'EUR') {
        throw new \RuntimeException('Projection must not mutate the input or actual primary currency.');
    }
    foreach (['pc_amount', 'pc_foreign_amount'] as $key) {
        unset($projected[$key]);
    }
    $raw = $original;
    unset($raw['pc_amount'], $raw['pc_foreign_amount']);
    if ($projected !== $raw) {
        throw new \RuntimeException('Original journal fields changed.');
    }
    foreach ([
        ['POST', 'http://localhost/transactions/update', true],
        ['GET',  'http://localhost/console',             false],
        ['GET',  'http://localhost/reports/profit',      true]
    ] as [$method, $url, $route]) {
        $next = Request::create($url, $method);
        if ($route) {
            $next->setRouteResolver(static fn() => new \stdClass());
        }
        $app->instance('request', $next);
        if (ReportingCurrency::projectJournal($original) !== $original) {
            throw new \RuntimeException('Writes, console calls and the raw profit collector must not be projected.');
        }
    }
    if (ReportingCurrency::today()->format('Y-m-d') !== '2026-11-08') {
        throw new \RuntimeException('A new request must use its own current valuation day.');
    }
    Carbon::setTestNow();
    echo "Passed 9 request-scope and immutable projection checks.\n";
}
