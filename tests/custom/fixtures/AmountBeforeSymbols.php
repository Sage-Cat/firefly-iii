<?php

declare(strict_types=1);

namespace FireflyIII\Support;

use FireflyIII\Support\Facades\Steam;
use NumberFormatter;

class AmountBeforeSymbols extends Amount
{
    public function formatFlat(string $symbol, int $decimalPlaces, string $amount, null|bool $coloured = null): string
    {
        $amount   = Steam::anonymous() ? '0' : $amount;
        $locale   = Steam::getLocale();
        $rounded  = Steam::bcround($amount, $decimalPlaces);
        $coloured ??= true;

        $fmt = new NumberFormatter($locale, NumberFormatter::CURRENCY);
        $fmt->setSymbol(NumberFormatter::CURRENCY_SYMBOL, $symbol);
        $fmt->setAttribute(NumberFormatter::MIN_FRACTION_DIGITS, $decimalPlaces);
        $fmt->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, $decimalPlaces);
        $result = (string) $fmt->format((float) $rounded); // intentional float

        if ($coloured) {
            if (1 === bccomp($rounded, '0')) {
                return sprintf('<span class="text-success money-positive">%s</span>', $result);
            }
            if (-1 === bccomp($rounded, '0')) {
                return sprintf('<span class="text-danger money-negative">%s</span>', $result);
            }

            return sprintf('<span class="money-neutral">%s</span>', $result);
        }

        return $result;
    }
}
