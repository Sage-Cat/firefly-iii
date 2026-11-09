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
console.log("Passed 14 native dashboard module checks.");
