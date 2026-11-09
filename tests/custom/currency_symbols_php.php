<?php

declare(strict_types=1);

namespace FireflyIII\Models {
    class TransactionCurrency
    {
        public function __construct(
            public int $id,
            public string $code,
            public string $symbol,
            public int $decimal_places,
            public string $name = 'Original name',
            public string $rate = '1.2345'
        ) {}
    }
}

namespace FireflyIII\Support\Facades {
    final class Steam
    {
        public static bool   $anonymous = false;
        public static string $locale    = 'uk_UA';

        public static function anonymous(): bool
        {
            return self::$anonymous;
        }

        public static function bcround(null|string $value, int $places): string
        {
            return new \FireflyIII\Support\Steam()->bcround($value, $places);
        }

        public static function getLocale(): string
        {
            return self::$locale;
        }
    }
}

namespace {
    use FireflyIII\Models\TransactionCurrency;
    use FireflyIII\Support\Amount;
    use FireflyIII\Support\AmountBeforeSymbols;
    use FireflyIII\Support\Facades\Steam;
    use FireflyIII\Support\Reports\CurrencyDisplay;

    require_once dirname(__DIR__, 2) . '/app/Support/Reports/CurrencyDisplay.php';
    require dirname(__DIR__, 2) . '/app/Support/Steam.php';
    require dirname(__DIR__, 2) . '/app/Support/Amount.php';
    require __DIR__ . '/fixtures/AmountBeforeSymbols.php';

    $checks = 0;
    function check(bool $value, string $message): void
    {
        if (!$value) {
            throw new RuntimeException($message);
        }
        ++$GLOBALS['checks'];
    }

    $policy = ['UAH' => '₴', 'EUR' => '€', 'USD' => '$', 'CHF' => '₣', 'USDT' => '₮'];
    foreach ($policy as $code => $symbol) {
        check(CurrencyDisplay::symbol($code) === $symbol, 'Known ISO codes use the agreed display symbol.');
        check(CurrencyDisplay::symbol(' ' . strtolower($code) . ' ', 'old') === $symbol, 'Code-aware formatting overrides stale model symbols.');
        check(
            CurrencyDisplay::normalize($code) === $symbol && CurrencyDisplay::normalize($symbol) === $symbol,
            'Direct codes and normalized symbols share one policy.'
        );
    }
    foreach (['грн' => '₴', 'грн.' => '₴', 'US$' => '$'] as $old => $expected) {
        check(CurrencyDisplay::normalize($old) === $expected, 'Exact legacy aliases normalize without changing values.');
    }
    foreach (['USD savings', 'CHF/EUR', 'USDT (staking)', '  Points  ', '<em>&"coin"</em>', ''] as $unknown) {
        check(CurrencyDisplay::normalize($unknown) === $unknown, 'Names, technical text and unknown symbols remain untouched.');
    }
    check(CurrencyDisplay::symbol('BTC', '₿') === '₿', 'Unknown currency codes preserve their configured symbol.');
    check(CurrencyDisplay::symbol('POINTS') === 'POINTS', 'An unknown code without a symbol remains an explicit fallback.');
    check(CurrencyDisplay::symbol('UAH', '₣') === '₴', 'The ISO code has priority over contradictory symbol metadata.');

    $before  = new AmountBeforeSymbols();
    $after   = new Amount();
    $formats = [
        ['UAH',    'грн',              2, '₴'],
        ['EUR',    'EUR',              2, '€'],
        ['USD',    'US$',              2, '$'],
        ['CHF',    'CHF',              2, '₣'],
        ['USDT',   'USDT',             8, '₮'],
        ['BTC',    '₿',                8, '₿'],
        ['CUSTOM', '<em>&"coin"</em>', 4, '<em>&"coin"</em>']
    ];
    foreach (['uk_UA', 'en_US', 'de_DE'] as $locale) {
        Steam::$locale = $locale;
        foreach ($formats as [$code, $oldSymbol, $places, $expectedSymbol]) {
            foreach (['1234.56789123', '-12.34567891', '0'] as $amount) {
                foreach ([false, true] as $coloured) {
                    $actual   = $after->formatFlat($oldSymbol, $places, $amount, $coloured);
                    $expected = $before->formatFlat($expectedSymbol, $places, $amount, $coloured);
                    check($actual === $expected, 'Native flat formatting retains rounding, locale, sign, colour and HTML semantics.');
                    $model    = new TransactionCurrency(42, $code, $oldSymbol, $places);
                    $original = serialize($model);
                    check(
                        $after->formatAnything($model, $amount, $coloured) === $expected,
                        'Native model formatting applies the code-aware policy at the same precision.'
                    );
                    check(serialize($model) === $original, 'Formatting must preserve model codes, IDs, names, precision, rates and configured symbols.');
                }
            }
        }
    }
    Steam::$locale = 'uk_UA';
    check(str_contains($before->formatFlat('грн', 2, '10', false), 'грн'), 'The baseline native formatter still exposes the unmodified hryvnia abbreviation.');
    check(str_contains($after->formatFlat('грн', 2, '10', false), '₴'), 'The patched native formatter displays the required hryvnia symbol.');
    check(str_contains($after->formatFlat('USD', 2, '-10', true), 'money-negative'), 'Negative amounts keep the native negative colour.');
    check(str_contains($after->formatFlat('EUR', 2, '10', true), 'money-positive'), 'Positive amounts keep the native positive colour.');
    check(str_contains($after->formatFlat('CHF', 2, '0', true), 'money-neutral'), 'Zero amounts keep the native neutral colour.');
    check(!str_contains($after->formatFlat('EUR', 2, '10', false), '<span'), 'Plain text output keeps its original non-HTML contract.');
    $models = [
        1 => new TransactionCurrency(1, 'UAH', 'old-U', 2),
        2 => new TransactionCurrency(2, 'CHF', 'old-C', 2),
        3 => new TransactionCurrency(3, 'USDT', 'T', 8)
    ];
    $lookup = new class($models) extends Amount {
        public function __construct(
            private array $models
        ) {}

        public function getTransactionCurrencyById(int $currencyId): TransactionCurrency
        {
            return $this->models[$currencyId];
        }
    };
    $original = serialize($models);
    foreach ($models as $id => $model) {
        $expected = $before->formatFlat($policy[$model->code], $model->decimal_places, '12.34567891', false);
        check(
            $lookup->formatByCurrencyId($id, '12.34567891', false) === $expected,
            'Native formatting by currency ID normalizes stale fiat and crypto symbols.'
        );
    }
    check(serialize($models) === $original, 'Lookup formatting does not rewrite stored currency metadata.');
    Steam::$anonymous = true;
    foreach ($formats as [$code, $oldSymbol, $places, $expectedSymbol]) {
        foreach ([false, true] as $coloured) {
            check(
                $after->formatFlat($oldSymbol, $places, '-9876.54321', $coloured) === $before->formatFlat($expectedSymbol, $places, '0', $coloured),
                'Anonymous mode keeps zero masking, precision and the correct symbol.'
            );
        }
    }
    Steam::$anonymous = false;
    echo 'Passed ' . $checks . " native PHP currency symbol checks.\n";
}
