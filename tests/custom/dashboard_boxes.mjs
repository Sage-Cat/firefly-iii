import assert from "node:assert/strict";
import fs from "node:fs";
import vm from "node:vm";

const formatter = fs
    .readFileSync(new URL("../../resources/assets/v3/js/util/format-money.js", import.meta.url), "utf8")
    .replace("export default function (", "function formatMoney(");
const source = fs
    .readFileSync(new URL("../../resources/assets/v3/js/pages/dashboard/boxes.js", import.meta.url), "utf8")
    .replace(/^import .*;$/gm, "")
    .replace("export default () => ({", "globalThis.component = () => ({");
const context = vm.createContext({ window: { __localeId__: "uk_UA" }, Intl, console });
vm.runInContext(fs.readFileSync(new URL("../../public/v1/js/ff/currency-display.js", import.meta.url), "utf8"), context);
vm.runInContext(formatter + source + "\nglobalThis.component = component();", context);
const component = context.component;
const row = (key, code, value, digits = 2) => ({
    key,
    currency_code: code,
    monetary_value: value,
    currency_decimal_places: digits,
});

component.generateOptions({
    usd: row("net-worth-in-USD", "USD", "100"),
    eur: row("net-worth-in-EUR", "EUR", "200"),
    token: row("net-worth-in-USDT", "USDT", "-0.000012", 6),
    positive: row("left-to-spend-in-UAH", "UAH", "500"),
    negative: row("left-to-spend-in-USD", "USD", "-600"),
    daily: row("left-per-day-to-spend-in-UAH", "UAH", "25"),
});
assert.equal(component.netBox.net.length, 3);
assert.ok(component.netBox.net.every((value) => value.length > 0));
assert.ok(component.netBox.net.some((value) => value.includes("0,000012") && value.includes("₮")));
assert.ok(component.netBox.net.some((value) => value.includes("$")));
assert.ok(component.netBox.net.some((value) => value.includes("€")));
assert.ok(component.leftBox.left.some((value) => value.includes("грн") && !value.includes("₴")));
assert.equal(component.leftBox.left.length, 2);
assert.equal(component.leftBox.perDay.length, 1);
assert.equal(component.noMoneyLeft, false, "Different currencies must not be added to decide status");
component.generateOptions({ negative: row("left-to-spend-in-USD", "USD", "-600") });
assert.equal(component.noMoneyLeft, true);
component.anonymous = true;
component.generateOptions({ token: row("net-worth-in-USDT", "USDT", "1234.567891", 6) });
assert.equal(component.noMoneyLeft, true);
assert.ok(component.netBox.net[0].includes("0,000000") && component.netBox.net[0].includes("₮"));
assert.ok(!component.netBox.net[0].includes("1234"));
component.anonymous = false;
component.generateOptions({ chf: row("net-worth-in-CHF", "CHF", "200") });
assert.ok(component.netBox.net[0].includes("₣") && !component.netBox.net[0].includes("CHF"));


const money = (amount, code, digits = 2) => context.window.fireflyCurrencyDisplay.format(amount, code, digits);
const flows = {
    balance: row('balance-in-EUR', 'EUR', '12.34'),
    eurSpent: row('spent-in-EUR', 'EUR', '0.00'),
    eurEarned: row('earned-in-EUR', 'EUR', '12.34'),
    uahSpent: row('spent-in-UAH', 'UAH', '-42.00'),
    uahEarned: row('earned-in-UAH', 'UAH', '0.00000000000000000000000000000000'),
    usdSpent: row('spent-in-USD', 'USD', '-5.00'),
    usdEarned: row('earned-in-USD', 'USD', '9.00'),
    chfSpent: row('spent-in-CHF', 'CHF', '-0.00'),
    chfEarned: row('earned-in-CHF', 'CHF', '0'),
    tokenSpent: row('spent-in-USDT', 'USDT', '0.00000000', 8),
    tokenEarned: row('earned-in-USDT', 'USDT', '0.00000001', 8),
    unpaid: row('bills-unpaid-in-USD', 'USD', '-35.00'),
    paid: row('bills-paid-in-UAH', 'UAH', '1750.00'),
};
Object.values(flows).forEach(Object.freeze);
Object.freeze(flows);
const originalFlows = JSON.stringify(flows);
const expectedSubtitles = [money('12.34', 'EUR'), money('-42.00', 'UAH'),
    money('9.00', 'USD') + ' + ' + money('-5.00', 'USD'), money('0.00000001', 'USDT', 8)].sort();
component.generateOptions(flows);
assert.deepEqual(Array.from(component.balanceBox.subtitles).sort(), expectedSubtitles,
    'Only nonzero cash-flow components appear, in earned/spent order within each currency.');
assert.equal(component.balanceBox.amounts[0], money('12.34', 'EUR'), 'Cash-flow totals remain the native amounts.');
assert.equal(JSON.stringify(flows), originalFlows, 'Presentation must not alter the API response.');
assert.deepEqual(Array.from(component.billBox.unpaid), [money('-35.00', 'USD')], 'Restored subscription tile retains unpaid amounts.');
assert.deepEqual(Array.from(component.billBox.paid), [money('1750.00', 'UAH')], 'Restored subscription tile retains actual paid currencies.');
component.generateOptions(Object.fromEntries(Object.entries(flows).reverse()));
assert.deepEqual(Array.from(component.balanceBox.subtitles).sort(), expectedSubtitles,
    'Income-only and spending-only subtitles do not depend on API entry order or end with a plus.');
component.generateOptions(Object.fromEntries([0, -0, '0', '-0', '+0.00000000', '0e-18', '-0E+20', '.000'].map(
    (value, index) => [String(index), row((index % 2 ? 'earned' : 'spent') + '-in-UAH', 'UAH', value)],
)));
assert.equal(component.balanceBox.subtitles.length, 0, 'Exact signed and exponent zeroes are all hidden.');
component.generateOptions({tiny: row('earned-in-USDT', 'USDT', '1e-8', 8)});
assert.equal(component.balanceBox.subtitles[0], money('0.00000001', 'USDT', 8), 'A tiny real income is never tested as a rounded float zero.');
component.anonymous = true;
component.generateOptions(flows);
assert.equal(component.balanceBox.subtitles.length, 0, 'Anonymous mode conceals every flow component regardless of its actual value.');
assert.ok(component.billBox.unpaid.every(value => !value.includes('35')) && component.billBox.paid.every(value => !value.includes('1750')),
    'The fourth tile keeps native anonymous masking.');
component.generateOptions({zero: row('earned-in-UAH', 'UAH', '0')});
assert.equal(component.balanceBox.subtitles.length, 0, 'Anonymous zero and nonzero flows have the same empty presentation.');
component.anonymous = false;
component.generateOptions({});
assert.equal(component.balanceBox.subtitles.length, 0, 'An empty refresh clears stale cash-flow subtitles.');
const view = fs.readFileSync(new URL("../../resources/views/components/dashboard/boxes.blade.php", import.meta.url), "utf8");
assert.equal((view.match(/col-xl-3 col-lg-6 col-md-6 col-sm-6 col-12/g) || []).length, 4);
assert.equal((view.match(/in billBox\.unpaid"/g) || []).length, 1);
assert.equal((view.match(/in billBox\.paid"/g) || []).length, 1);
assert.ok(view.includes("(balanceBox.subtitles.length == index+1) }\"> &amp;"), "Filtered subtitle separators use the visible subtitle count.");
console.log("Passed native dashboard currency, subscription, zero-flow and anonymous checks.");
