import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

const helper = fs.readFileSync(new URL('../../public/v1/js/ff/dashboard-chart-periods.js', import.meta.url), 'utf8');
const ranges = Object.freeze({
    '30d': Object.freeze({period: '30d', start: '2026-10-11', end: '2026-11-09'}),
    '3m': Object.freeze({period: '3m', start: '2026-08-10', end: '2026-11-09'}),
    '1y': Object.freeze({period: '1y', start: '2025-11-10', end: '2026-11-09'}),
});
const bases = Object.freeze({capital: 'api/v1/chart/account/overview?net_worth_eur=1&period=1D',
    budget: 'api/v1/chart/budget/overview-with-limits', category: 'chart/category/frontpage',
    expense: 'chart/account/expense', revenue: 'chart/account/revenue'});
const state = Object.fromEntries(Object.keys(bases).map(key => [key, ranges['30d']]));
const window = {document: {baseURI: 'https://example.test/firefly/'}, location: {origin: 'https://example.test'},
    fireflyDashboardChartRanges: state, store: Object.freeze({start: 'unchanged', end: 'unchanged'})};
const context = vm.createContext({window, URL, Date, Object, Number, TypeError});
vm.runInContext(helper, context);
const url = window.fireflyDashboardPeriods.url;
let checks = 0;
const check = (condition, message) => {assert.ok(condition, message); ++checks;};
for (const [chart, base] of Object.entries(bases)) {
    for (const period of Object.keys(ranges)) {
        state[chart] = ranges[period];
        const before = JSON.stringify(state);
        const result = new URL(url(chart, base + (base.includes('?') ? '&' : '?') + 'keep=yes&start=1999-01-01&end=1999-02-01'));
        check(result.origin === 'https://example.test' && result.pathname.startsWith('/firefly/'), 'Reverse-proxy path and same origin are preserved.');
        check(result.searchParams.get('start') === ranges[period].start && result.searchParams.get('end') === ranges[period].end, 'Every chart uses the supplied server interval.');
        check(result.searchParams.get(chart === 'capital' ? 'capital_period' : 'dashboard_period') === period, 'The correct server opt-in parameter is sent.');
        check(result.searchParams.get('keep') === 'yes' && result.searchParams.getAll('start').length === 1, 'Other query values survive without duplicate date parameters.');
        check(before === JSON.stringify(state) && window.store.start === 'unchanged', 'Source ranges and global calendar are untouched.');
    }
    state[chart] = ranges['30d'];
}
state.expense = ranges['1y'];
check(new URL(url('budget', bases.budget)).searchParams.get('dashboard_period') === '30d', 'One chart does not inherit another chart selection.');
check(new URL(url('capital', bases.capital)).searchParams.get('net_worth_eur') === '1', 'The net-worth projection flag remains present.');
for (const chart of ['__proto__', 'unknown', null]) {
    assert.throws(() => url(chart, bases.budget), /Unknown dashboard/); ++checks;
}
for (const base of ['https://foreign.test/chart', '//foreign.test/chart', 'javascript:alert(1)', 'data:text/plain,test', 'https://user:password@example.test/chart']) {
    assert.throws(() => url('budget', base), /application origin/); ++checks;
}
for (const base of ['', null, {}]) {
    assert.throws(() => url('budget', base), /Unknown dashboard/); ++checks;
}
for (const invalid of [undefined, {}, {period: 'all', start: '2026-01-01', end: '2026-11-09'},
    {period: '30d', start: '2026-02-31', end: '2026-11-09'}, {period: '30d', start: '2026-11-10', end: '2026-11-09'},
    {period: '30d', start: ['2026-10-11'], end: '2026-11-09'}]) {
    state.budget = invalid;
    assert.throws(() => url('budget', bases.budget), /Invalid dashboard/); ++checks;
}
state.budget = ranges['30d'];
check(Object.isFrozen(window.fireflyDashboardPeriods), 'The public helper contract is immutable.');
console.log(`Passed ${checks} independent dashboard period URL checks.`);
