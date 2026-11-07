import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

const entry = process.argv[2] || 'public/build/' + JSON.parse(fs.readFileSync('public/build/manifest.json', 'utf8'))['js/pages/dashboard/dashboard.js'].file;
const source = fs.readFileSync(entry, 'utf8');
const argument = source.match(/(`api\/v1\/chart\/account\/overview\?[\s\S]*?),`accounts-chart`/)?.[1];
assert.ok(argument, 'Generated chart URL argument missing');
// Stub only the two date formatter calls; execute the complete generated URL argument.
let dates = 0;
const expression = argument.replace(/\b[A-Za-z_$]\w*\([A-Za-z_$]\w*,`yyyy-LL-dd`\)/g, () => { ++dates; return '"2026-11-01"'; });
assert.equal(dates, 2, 'Native date formatter call shape changed');
for (const [search, expected] of [['', '30d'], ['?capital_period=30d', '30d'], ['?capital_period=3m', '3m'], ['?capital_period=1y', '1y']]) {
    const context = vm.createContext({window: {location: {search}}, URLSearchParams, encodeURIComponent});
    const chartUrl = vm.runInContext(expression, context);
    const url = new URL(chartUrl, 'http://127.0.0.1:8080/');
    assert.equal(url.searchParams.get('capital_period'), expected);
    assert.equal(url.searchParams.get('net_worth_eur'), '1');
    assert.equal(url.searchParams.get('period'), '1D');
    assert.equal(url.searchParams.get('start'), '2026-11-01');
    assert.equal(url.searchParams.get('end'), '2026-11-01');
}
console.log('Passed generated dashboard URL checks for all history periods.');
