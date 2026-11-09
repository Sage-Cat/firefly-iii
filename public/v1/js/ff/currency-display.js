(function (root) {
    'use strict';

    const symbols = Object.freeze({UAH: '₴', EUR: '€', USD: '$', CHF: '₣', USDT: '₮', '₴': '₴', 'US$': '$', 'грн.': '₴', 'грн': '₴'});

    function locale() {
        const value = root.__localeId__ || root.document?.documentElement?.lang || root.navigator?.language || 'en-US';
        try {
            return new Intl.NumberFormat(String(value).replaceAll('_', '-')).resolvedOptions().locale;
        } catch (_) {
            return 'en-US';
        }
    }

    function symbol(code, fallback) {
        const raw = typeof code === 'string' ? code.trim() : '';
        const canonical = raw.toUpperCase();
        if (Object.hasOwn(symbols, raw)) {
            return symbols[raw];
        }
        if (Object.hasOwn(symbols, canonical)) {
            return symbols[canonical];
        }
        if (typeof fallback === 'string' && fallback !== '' && fallback !== canonical) {
            return Object.hasOwn(symbols, fallback) ? symbols[fallback] : fallback;
        }
        if (/^[A-Z]{3}$/.test(canonical)) {
            try {
                return new Intl.NumberFormat(locale(), {style: 'currency', currency: canonical, currencyDisplay: 'narrowSymbol'})
                    .formatToParts(1).find(part => part.type === 'currency').value;
            } catch (_) {
                // An unknown code remains visible instead of implying another currency.
            }
        }
        return typeof fallback === 'string' && fallback !== '' ? fallback : raw;
    }

    function decimal(amount) {
        if ((typeof amount !== 'string' && typeof amount !== 'number')
            || (typeof amount === 'number' && !Number.isFinite(amount))) {
            return null;
        }
        const value = String(amount).trim();
        const match = /^([+-]?)(\d*)(?:\.(\d*))?(?:[eE]([+-]?\d+))?$/.exec(value);
        if (!match || !(match[2] || match[3]) || value.length > 10000) {
            return null;
        }
        const exponent = Number(match[4] || 0);
        if (!Number.isInteger(exponent) || Math.abs(exponent) > 1000) {
            return null;
        }
        const digits = match[2] + (match[3] || '');
        const point = match[2].length + exponent;
        const whole = point <= 0 ? '0' : (digits.slice(0, point) + '0'.repeat(Math.max(0, point - digits.length)));
        const fraction = point < 0 ? '0'.repeat(-point) + digits : digits.slice(point);
        return {negative: match[1] === '-', whole: whole.replace(/^0+(?=\d)/, '') || '0', fraction};
    }

    function format(amount, code, decimalPlaces, fallbackSymbol) {
        const value = decimal(amount);
        const mark = symbol(code, fallbackSymbol);
        if (!value || !mark) {
            return '';
        }
        const canonical = typeof code === 'string' ? code.trim().toUpperCase() : '';
        const iso = /^[A-Z]{3}$/.test(canonical);
        const language = locale();
        let places = decimalPlaces;
        if (!Number.isInteger(places) || places < 0 || places > 20) {
            places = canonical === 'USDT' ? 8 : 2;
            if (iso) {
                try {
                    places = new Intl.NumberFormat(language, {style: 'currency', currency: canonical}).resolvedOptions().maximumFractionDigits;
                } catch (_) {
                    // Keep the amount visible with a conservative decimal fallback.
                }
            } else if (canonical !== 'USDT') {
                places = Math.max(2, Math.min(20, value.fraction.length));
            }
        }

        // Decimal strings must not pass through a binary floating-point amount.
        let units = BigInt(value.whole + value.fraction.padEnd(places, '0').slice(0, places));
        if ((value.fraction[places] || '0') >= '5') {
            units += 1n;
        }
        const rounded = units.toString().padStart(places + 1, '0');
        const whole = places === 0 ? rounded : rounded.slice(0, -places);
        const fraction = places === 0 ? '' : rounded.slice(-places);
        const integerFormatter = new Intl.NumberFormat(language, {maximumFractionDigits: 0});
        const separator = new Intl.NumberFormat(language).formatToParts(1.1).find(part => part.type === 'decimal')?.value || '.';
        const digitFormatter = new Intl.NumberFormat(language, {useGrouping: false, maximumFractionDigits: 0});
        const localizedFraction = fraction.replace(/\d/g, digit => digitFormatter.format(Number(digit)));
        const number = integerFormatter.format(BigInt(whole)) + (places > 0 ? separator + localizedFraction : '');
        const options = {minimumFractionDigits: places, maximumFractionDigits: places};
        if (iso) {
            options.style = 'currency';
            options.currency = canonical;
        }
        const parts = new Intl.NumberFormat(language, options).formatToParts(value.negative ? -1 : 1);
        let inserted = false;
        const formatted = parts.map(part => {
            if (part.type === 'currency') {
                return mark;
            }
            if (['integer', 'group', 'decimal', 'fraction'].includes(part.type)) {
                if (inserted) {
                    return '';
                }
                inserted = true;
                return number;
            }
            return part.value;
        }).join('');
        return iso ? formatted : formatted + '\u00a0' + mark;
    }

    root.fireflyCurrencyDisplay = Object.freeze({symbol, format});
})(window);
