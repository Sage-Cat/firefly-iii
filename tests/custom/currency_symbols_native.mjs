import assert from 'node:assert/strict';
import crypto from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
import vm from 'node:vm';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const build = path.join(root, 'public/build');
const manifest = JSON.parse(fs.readFileSync(path.join(build, 'manifest.json'), 'utf8'));
const read = name => fs.readFileSync(path.join(root, name), 'utf8');
let assets = 0;
for (const entry of Object.values(manifest)) {
    for (const dependency of [...(entry.imports || []), ...(entry.dynamicImports || [])]) {
        assert.ok(manifest[dependency], `Manifest dependency ${dependency} exists.`);
    }
    if (!entry.file.endsWith('.js')) continue;
    const filename = path.join(build, entry.file);
    const body = fs.readFileSync(filename, 'utf8');
    assert.equal(entry.integrity, 'sha384-' + crypto.createHash('sha384').update(body).digest('base64'));
    for (const [, dependency] of body.matchAll(/["'](\.{1,2}\/[^"'?#]+\.js)["']/g)) {
        assert.ok(fs.existsSync(path.resolve(path.dirname(filename), dependency)), `Built import ${dependency} exists.`);
    }
    assets++;
}
assert.ok(assets > 10, 'The production Vite build contains the native entry points and shared modules.');

const helper = read('public/v1/js/ff/currency-display.js');
const layouts = fs.readdirSync(path.join(root, 'resources/views/layout/v3')).filter(name => name.endsWith('.blade.php'));
assert.equal(layouts.length, 6);
for (const layout of layouts) {
    const body = read('resources/views/layout/v3/' + layout);
    const start = body.indexOf('{{-- currency-display --}}');
    assert.equal(body.split('{{-- currency-display --}}').length - 1, 1);
    assert.ok(start >= 0 && start < body.indexOf('@vite') && start < body.indexOf('<script nonce='));
    const tag = body.slice(start, body.indexOf('</script>', start));
    assert.ok(tag.includes("asset('v1/js/ff/currency-display.js')"));
    assert.ok(tag.includes("nonce=\"{{ $JS_NONCE ?? '' }}\""));
    assert.ok(tag.includes("hash_file('sha256', public_path('v1/js/ff/currency-display.js'))"));
    assert.ok(tag.includes("hash_file('sha384', public_path('v1/js/ff/currency-display.js'), true)"));
    assert.ok(!tag.includes(' defer') && !tag.includes(' async'));
}
const session = read('resources/views/layout/v3/session.blade.php');
assert.ok(session.includes("hash_file('sha256', public_path('v1/js/lib/accounting.min.js'))"));

const context = vm.createContext({Intl, BigInt, console, __localeId__: 'uk_UA'});
context.window = context;
vm.runInContext(helper, context);
const moneyEntries = Object.values(manifest).filter(entry => entry.name === 'format-money');
assert.equal(moneyEntries.length, 1, 'Vite keeps one shared native money formatter.');
const money = fs.readFileSync(path.join(build, moneyEntries[0].file), 'utf8');
const exported = money.match(/export\s*\{([\w$]+)\s+as\s+[\w$]+\s*\}/);
assert.ok(exported, 'The built money formatter exports its native binding.');
vm.runInContext(money.replace(/export\s*\{[^}]+\};?/, '') + `\nglobalThis.nativeMoney=${exported[1]};`, context);
for (const [code, mark] of Object.entries({UAH: '₴', USD: '$', EUR: '€', CHF: '₣', USDT: '₮'})) {
    assert.ok(context.nativeMoney('123.45', code, 2).includes(mark));
}
assert.ok(context.nativeMoney('0.00000001', 'USDT', 8).includes('0,00000001'));
assert.ok(context.nativeMoney('9007199254740993.01', 'EUR', 2).includes('993,01'));
vm.runInContext(read('public/v1/js/lib/accounting.min.js'), context);
context.accounting.settings.currency.symbol = 'UAH';
assert.ok(context.accounting.formatMoney('123.45').includes('₴'));
assert.ok(context.accounting.formatMoney('123.45', 'CHF', 2).includes('₣'));
assert.ok(context.accounting.formatMoney('0.00000001', 'USDT', 8).includes('₮'));
assert.equal(context.accounting.settings.currency.symbol, 'UAH', 'Legacy defaults are not rewritten.');
console.log(`Passed native currency-symbol integration: ${layouts.length} CSP layouts, ${assets} Vite JavaScript assets with valid SRI/imports, built and legacy formatters.`);
