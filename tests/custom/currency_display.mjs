import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

const window = {__localeId__: 'uk_UA'};
vm.runInNewContext(fs.readFileSync(new URL('../../public/v1/js/ff/currency-display.js', import.meta.url), 'utf8'), {window, Intl, BigInt});
const display = window.fireflyCurrencyDisplay;
let checks = 0;
function equal(actual, expected, message) {
    assert.equal(actual, expected, message);
    checks++;
}
function includes(actual, expected, message) {
    assert.ok(actual.includes(expected), message);
    checks++;
}
const plain = value => value.replaceAll('\u00a0', ' ').replaceAll('\u202f', ' ');

for (const [code, mark] of Object.entries({UAH: '₴', USD: '$', EUR: '€', CHF: '₣', USDT: '₮'})) {
    equal(display.symbol(code, 'wrong'), mark, `${code} has its requested display symbol`);
    includes(display.format('123.45', code, 2), mark, `${code} formatting uses its display symbol`);
}
equal(display.symbol('₴', '₴'), '₴', 'Legacy hryvnia glyphs follow the same policy');
for (const [alias, mark] of Object.entries({'US$': '$', 'грн.': '₴', 'грн': '₴'})) {
    equal(display.symbol(alias), mark, `${alias} raw marks normalize to the canonical display symbol`);
    equal(display.symbol(alias, alias), mark, `${alias} legacy marks normalize with their own fallback`);
    equal(display.symbol('TOKEN', alias), mark, `${alias} fallback marks normalize to the canonical display symbol`);
}
equal(display.symbol('GBP', '£'), '£', 'Other supplied native symbols remain unchanged');
equal(display.symbol('GBP'), '£', 'Other ISO currencies use their native symbols instead of locale-specific ISO labels');
equal(display.symbol('BTC', '₿'), '₿', 'A custom symbol can be supplied for a non-ISO code');
equal(display.symbol('TOKEN', '★'), '★', 'An unknown token uses the supplied symbol');
equal(display.symbol('TOKEN'), 'TOKEN', 'An unknown token has an honest code fallback');
equal(display.symbol('TOKEN', 'constructor'), 'constructor', 'Arbitrary fallback labels stay strings without inherited map properties');
equal(display.symbol('XYZ'), 'XYZ', 'An unknown ISO code remains visible');
equal(display.symbol(null, '¤'), '¤', 'A supplied generic native symbol remains usable');
equal(plain(display.format('9007199254740993.01', 'USD', 2)), '9 007 199 254 740 993,01 $', 'Large decimal strings retain exact cents');
equal(plain(display.format('-9007199254740993.01', 'EUR', 2)), '-9 007 199 254 740 993,01 €', 'Large negative decimal strings retain exact cents');
equal(plain(display.format('1.00000001', 'USDT', 8)), '1,00000001 ₮', 'Explicit crypto precision preserves all eight places');
equal(plain(display.format('-0.00000001', 'USDT', 8)), '-0,00000001 ₮', 'Small negative crypto values remain visible');
equal(plain(display.format('1e-8', 'USDT', 8)), '0,00000001 ₮', 'Scientific input is expanded without binary amount arithmetic');
equal(plain(display.format('1.23', 'USDT')), '1,23000000 ₮', 'Native USDT defaults to eight places');
equal(plain(display.format('1234.6', 'JPY')), '1 235 ¥', 'Native JPY precision stays zero');
equal(plain(display.format('1234.6', 'JPY', 2)), '1 234,60 ¥', 'Explicit precision takes precedence over currency defaults');
equal(plain(display.format('1234.6', 'USD', 0)), '1 235 $', 'An explicit zero is a valid precision');
equal(plain(display.format('9.995', 'USD', 2)), '10,00 $', 'Exact decimal rounding carries into the integer part');
equal(plain(display.format('-9.995', 'USD', 2)), '-10,00 $', 'Negative ties round away from zero like the native formatter');
equal(plain(display.format('-0.001', 'USD', 2)), '-0,00 $', 'Rounding preserves the sign of small negative amounts');
equal(plain(display.format('.005', 'CHF', 2)), '0,01 ₣', 'A decimal without an integer part rounds exactly');
equal(plain(display.format('1.234567', 'TOKEN', 6, '★')), '1,234567 ★', 'Unknown non-ISO currencies accept native symbols and precision');
includes(display.format('1.23', 'XYZ'), 'XYZ', 'Unknown ISO values format without a RangeError');
equal(display.format(null, 'USD'), '', 'Null is not invented as a zero amount');
equal(display.format('', 'USD'), '', 'An empty amount stays empty');
equal(display.format('not-a-number', 'USD'), '', 'Invalid decimal input stays empty');
equal(display.format(Infinity, 'USD'), '', 'Infinite amounts stay empty');
equal(display.format('1e1001', 'USD'), '', 'Extreme exponents are rejected');
equal(display.format('1', ''), '', 'An absent currency does not imply another currency');

window.__localeId__ = 'en_US';
equal(display.format('1234.50', 'USD', 2), '$1,234.50', 'Locale is resolved at call time after the early helper load');
equal(display.format('1234.50', 'EUR', 2), '€1,234.50', 'Currency placement follows the current locale');
window.__localeId__ = 'invalid_locale_tag_with_many_parts';
equal(display.format('1', 'USD', 2), '$1.00', 'An unsupported locale has a safe locale fallback');
equal(Object.isFrozen(display), true, 'Consumers share an immutable public helper API');

// Execute the native accounting wrapper against a legacy spy.
const legacySource = fs.readFileSync(new URL('../../public/v1/js/lib/accounting.min.js', import.meta.url), 'utf8');
const wrapper = legacySource.slice(legacySource.lastIndexOf(';(function (root) {'));
assert.ok(wrapper.includes('fireflyCurrencyDisplay'), 'The native legacy wrapper must be testable');
const legacy = {settings: {currency: {symbol: 'UAH'}},
    formatMoney: (amount, mark, ...rest) => ({amount, mark, rest}),
    formatColumn: (amount, mark, ...rest) => ({amount, mark, rest})};
window.accounting = legacy;
vm.runInNewContext(wrapper, {window});
const result = legacy.formatMoney('9007199254740993.01', 'CHF', 8, ',', '.', '%v %s');
equal(result.amount, '9007199254740993.01', 'Legacy wrapper does not coerce the amount');
equal(result.mark, '₣', 'Legacy positional ISO symbols are normalized');
equal(result.rest[0], 8, 'Legacy precision passes through unchanged');
equal(result.rest[1], ',', 'Legacy thousands separator passes through unchanged');
equal(result.rest[3], '%v %s', 'Legacy format pattern passes through unchanged');
equal(legacy.formatMoney('1').mark, '₴', 'Legacy default currency symbols are normalized');
equal(legacy.formatMoney('1', 'US$').mark, '$', 'Legacy accounting normalizes the US dollar alias');
equal(legacy.formatMoney('1', 'грн.').mark, '₴', 'Legacy accounting normalizes the dotted hryvnia alias');
const options = {symbol: 'USDT', precision: 8, decimal: ',', thousand: ' '};
const objectResult = legacy.formatMoney('0.00000001', options);
equal(objectResult.mark.symbol, '₮', 'Legacy option objects get display symbols');
equal(objectResult.mark.precision, 8, 'Legacy option precision is preserved');
equal(options.symbol, 'USDT', 'Legacy caller option objects are never mutated');
equal(legacy.formatColumn(['1', '2'], 'USD', 0).mark, '$', 'Legacy aligned money columns follow the same symbol policy');
equal(legacy.settings.currency.symbol, 'UAH', 'The helper does not change global currency preferences or accounting defaults');
console.log(`Passed ${checks} currency display helper checks.`);
