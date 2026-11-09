const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

const path = require('node:path');
const root = path.resolve(__dirname, '../..');
const chartsPath = process.argv[2] || path.join(root, 'public/v1/js/ff/charts.js');
const defaultsPath = process.argv[3] || path.join(root, 'public/v1/js/ff/charts.defaults.js');

const clone = value => Array.isArray(value) ? value.map(clone)
    : value && typeof value === 'object' ? Object.fromEntries(Object.entries(value).map(([key, item]) => [key, clone(item)])) : value;
const payloads = {
    category: {count: 1, labels: ['Податки ФОП', 'Повернення'], datasets: [{label: 'Витрати', currency_symbol: '₴', currency_code: 'UAH', data: ['18000.00', '-4.00']}]},
    other: {count: 1, labels: ['Account'], datasets: [{label: 'Capital', currency_symbol: '€', data: ['50.00']}]},
    expense: {count: 1, labels: ['Shop'], datasets: [{label: 'Expense', currency_symbol: '₴', currency_code: 'UAH', data: ['800.00']}]},
    revenue: {count: 1, labels: ['Client'], datasets: [{label: 'Income', currency_symbol: '₴', currency_code: 'UAH', data: ['5000.00']}]},
    empty: {count: 0, labels: [], datasets: []},
};
const created = new Map();
const elements = new Map();
const context = {
    console: {log() {}}, anonymous: false, noDataForChart: 'No data',
    accounting: {settings: {currency: {symbol: '€'}}, formatMoney(value, symbol = '€', places = 2) {return `${symbol} ${Number(value).toFixed(places)}`;}},
    $: {extend(_deep, _target, value) {return clone(value);}},
    document: {getElementById(id) {
        if (!elements.has(id)) {
            const classes = new Set();
            const holder = {classList: {contains(name) {return name === 'card-body';}}, innerHTML: ''};
            elements.set(id, {classes, holder, classList: {remove(name) {classes.delete(name);}, add(name) {classes.add(name);}},
                parentNode: {parentNode: holder}, getContext() {return id;}});
        }
        return elements.get(id);
    }},
    window: {axios: {get(url) {return Promise.resolve({data: clone(payloads[url])});}}},
    Chart: function(id, options) {Object.assign(this, options); this.update = () => {}; created.set(id, this);},
};
context.Chart.defaults = {global: {legend: {}, animation: {}}};
vm.createContext(context);
vm.runInContext(fs.readFileSync(defaultsPath, 'utf8'), context);
vm.runInContext(fs.readFileSync(chartsPath, 'utf8'), context);
const tick = () => new Promise(resolve => setImmediate(resolve));

(async () => {
    vm.runInContext('columnChart("category", "categories-chart")', context);
    await tick();
    const chart = created.get('categories-chart');
    assert.ok(chart, 'The real native drawAChart must create the category chart.');
    assert.equal(chart.options.scales.yAxes[0].ticks.callback(18000), '₴ 18000.00');
    assert.equal(chart.options.tooltips.callbacks.label({datasetIndex: 0, yLabel: -4}, chart.data), 'Витрати: ₴ -4.00');
    assert.deepEqual(Array.from(chart.data.datasets[0].data), ['18000.00', '-4.00'], 'Chart values must remain signed UAH amounts.');
    assert.doesNotThrow(() => context.ledgerBarChartOptions(clone(context.defaultChartOptions), {datasets: [{currency_code: 'UAH', currency_symbol: 'грн'}]}, 'categories-chart'), 'Stale UAH symbol metadata remains valid during cache refresh.');
    context.anonymous = true;
    assert.equal(chart.options.scales.yAxes[0].ticks.callback(18000), '₴ 0.00');
    assert.equal(chart.options.tooltips.callbacks.label({datasetIndex: 0, yLabel: -4}, chart.data), 'Витрати: ₴ 0.00');
    context.anonymous = false;
    vm.runInContext('columnChart("expense", "expense-accounts-chart")', context);
    await tick();
    assert.equal(created.get('expense-accounts-chart').options.scales.yAxes[0].ticks.callback(800), '₴ 800.00', 'Expense accounts use their UAH dataset metadata.');
    vm.runInContext('columnChart("revenue", "revenue-accounts-chart")', context);
    await tick();
    assert.equal(created.get('revenue-accounts-chart').options.scales.yAxes[0].ticks.callback(5000), '₴ 5000.00', 'Income accounts use converted UAH dataset metadata.');
    vm.runInContext('columnChart("other", "generic-other-chart")', context);
    await tick();
    assert.equal(created.get('generic-other-chart').options.scales.yAxes[0].ticks.callback(50), '€ 50.00', 'Other charts retain their original formatter.');
    assert.equal(context.accounting.settings.currency.symbol, '€', 'The global accounting currency remains EUR.');
    payloads.category.datasets[0].data = ['20000.00', '-15.00'];
    vm.runInContext('columnChart("category", "categories-chart")', context);
    await tick();
    assert.deepEqual(Array.from(chart.data.datasets[0].data), ['20000.00', '-15.00'], 'Native chart refresh must retain negative refund credits.');
    assert.equal(chart.options.scales.yAxes[0].ticks.callback(20000), '₴ 20000.00');
    assert.throws(() => context.ledgerBarChartOptions(clone(context.defaultChartOptions), {
        datasets: [{currency_code: 'EUR', currency_symbol: '€'}],
    }, 'categories-chart'), /UAH data/, 'Incorrect units must not be silently relabelled UAH.');
    assert.throws(() => context.ledgerBarChartOptions(clone(context.defaultChartOptions), payloads.other, 'revenue-accounts-chart'), /UAH data/, 'Income charts must reject stale EUR amounts rather than silently relabelling them UAH.');
    vm.runInContext('columnChart("empty", "categories-chart")', context);
    await tick();
    assert.equal(elements.get('categories-chart').classes.has('general-chart-error'), false, 'An empty period must not access missing currency metadata or throw.');
    assert.equal(elements.get('categories-chart').holder.innerHTML, '<p><em>No data</em></p>', 'The native empty-period message remains intact.');
    console.log('Passed 17 native category chart rendering checks.');
})().catch(error => {console.error(error); process.exitCode = 1;});
