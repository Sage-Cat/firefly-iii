<?php

declare(strict_types=1);

namespace FireflyIII\Support\Reports;

final class CurrencyDisplay
{
    private const SYMBOLS = ['UAH' => '₴', 'EUR' => '€', 'USD' => '$', 'CHF' => '₣', 'USDT' => '₮'];

    private const ALIASES = ['₴' => '₴', 'грн' => '₴', 'грн.' => '₴', '€' => '€', '$' => '$', 'US$' => '$', '₣' => '₣', '₮' => '₮'];

    public static function normalize(string $symbol): string
    {
        $value = trim($symbol);

        return self::SYMBOLS[strtoupper($value)] ?? self::ALIASES[$value] ?? $symbol;
    }

    public static function symbol(string $code, null|string $fallback = null): string
    {
        return self::SYMBOLS[strtoupper(trim($code))] ?? self::normalize($fallback ?? $code);
    }
}
