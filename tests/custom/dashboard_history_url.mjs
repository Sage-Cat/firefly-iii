import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

const entry = process.argv[2] || 'public/build/' + JSON.parse(fs.readFileSync('public/build/manifest.json', 'utf8'))['js/pages/dashboard/dashboard.js'].file;
const source = fs.readFileSync(entry, 'utf8');
const helper = fs.readFileSync(new URL('../../public/v1/js/ff/dashboard-chart-periods.js', import.meta.url), 'utf8');
const periods = {'30d': {period: '30d', start: '2026-10-11', end: '2026-11-09'},
    '3m': {period: '3m', start: '2026-08-10', end: '2026-11-09'}, '1y': {period: '1y', start: '2025-11-10', end: '2026-11-09'}};
const keys = ['capital', 'budget', 'category', 'expense', 'revenue'];
const argumentsByChart = {};
for (const [chart, container] of [['capital', 'accounts-chart'], ['budget', 'budgets-chart']]) {
    const pattern = new RegExp(`(window\\.fireflyDashboardPeriods\\.url\\([\x22\x27\x60]${chart}[\x22\x27\x60],\\s*[\\s\\S]*?\\)),[\x22\x27\x60]${container}[\x22\x27\x60]`);
    const argument = source.match(pattern)?.[1];
    assert.ok(argument, `Generated ${chart} URL argument missing`);
    // Execute the real native argument; stub only the old global date formatter calls.
    let dates = 0;
    argumentsByChart[chart] = argument.replace(/\b[A-Za-z_$]\w*\([A-Za-z_$]\w*,`yyyy-LL-dd`\)/g, () => {++dates; return '"1999-01-01"';});
    assert.equal(dates, 2, 'Native date formatter call shape changed');
}
for (const search of ['', '?capital_period=30d&budget_period=3m', '?capital_period=3m&expense_period=1y',
    '?capital_period=1y&category_period=3m&revenue_period=1y', '?capital_period=invalid&budget_period=invalid']) {
    const query = new URLSearchParams(search);
    const selected = Object.fromEntries(keys.map(chart => [chart, periods[query.get(chart + '_period')] || periods['30d']]));
    const context = vm.createContext({window: {location: {search, origin: 'http://127.0.0.1:8080'},
        document: {baseURI: 'http://127.0.0.1:8080/'}, fireflyDashboardChartRanges: selected}, URL, URLSearchParams, encodeURIComponent});
    vm.runInContext(helper, context);
    for (const chart of ['capital', 'budget']) {
        const actual = new URL(vm.runInContext(argumentsByChart[chart], context));
        assert.equal(actual.searchParams.get(chart === 'capital' ? 'capital_period' : 'dashboard_period'), selected[chart].period);
        assert.equal(actual.searchParams.get('start'), selected[chart].start);
        assert.equal(actual.searchParams.get('end'), selected[chart].end);
        if (chart === 'capital') {
            assert.equal(actual.searchParams.get('net_worth_eur'), '1');
            assert.equal(actual.searchParams.get('period'), '1D');
        }
    }
}
if (process.argv[3]) {
    const legacy = fs.readFileSync(process.argv[3], 'utf8');
    for (const period of Object.keys(periods)) {
        const calls = [];
        const selected = Object.fromEntries(keys.map(chart => [chart, periods[period]]));
        const context = vm.createContext({window: {location: {origin: 'http://127.0.0.1:8080'},
            document: {baseURI: 'http://127.0.0.1:8080/'}, fireflyDashboardChartRanges: selected}, URL,
            $: () => {}, billCount: 0, accountExpenseUrl: 'chart/account/expense?keep=expense',
            accountRevenueUrl: 'chart/account/revenue?keep=revenue', columnChart(url, chart) {calls.push([url, chart]);}});
        vm.runInContext(helper + '\n' + legacy + '\ndrawChart();', context);
        assert.equal(calls.length, 3, 'All three legacy charts redraw without hidden widgets.');
        for (const [url, container] of calls) {
            const actual = new URL(url);
            assert.equal(actual.searchParams.get('dashboard_period'), period, container);
            assert.equal(actual.searchParams.get('start'), periods[period].start, container);
            assert.equal(actual.searchParams.get('end'), periods[period].end, container);
        }
    }
}
console.log('Passed generated dashboard URLs and independent date-range checks.');
