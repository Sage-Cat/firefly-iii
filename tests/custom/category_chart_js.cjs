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
    assert.deepEqual(Array.from(chart.data.datasets[0].data), ['18000.00'], 'Top expense bars keep positive net UAH amounts while refund-only categories stay outside the ranking.');
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
    assert.deepEqual(Array.from(chart.data.datasets[0].data), ['20000.00'], 'Native chart refresh ranks the positive net amounts again.');
    assert.equal(chart.options.scales.yAxes[0].ticks.callback(20000), '₴ 20000.00');
    assert.throws(() => context.ledgerBarChartOptions(clone(context.defaultChartOptions), {
        datasets: [{currency_code: 'EUR', currency_symbol: '€'}],
    }, 'categories-chart'), /UAH data/, 'Incorrect units must not be silently relabelled UAH.');
    assert.throws(() => context.ledgerBarChartOptions(clone(context.defaultChartOptions), payloads.other, 'revenue-accounts-chart'), /UAH data/, 'Income charts must reject stale EUR amounts rather than silently relabelling them UAH.');
    const ranking = {count: 1, labels: ['A', 'B', 'C', 'D', 'E', 'F', 'refund', 'zero'], datasets: [{label: 'Витрати', currency_code: 'UAH', currency_symbol: '₴', data: ['20.00', '30.00', '10.00', '30.00', '50.00', '60.00', '-100.00', '0.00'], backgroundColor: ['A', 'B', 'C', 'D', 'E', 'F', 'refund', 'zero']}]};
    const original = JSON.stringify(ranking);
    const topFive = context.ledgerTopChartData(ranking, 'categories-chart', 'bar');
    assert.deepEqual(Array.from(topFive.labels), ['F', 'E', 'B', 'D', 'A'], 'Categories show the five largest positive net values, with stable ties');
    assert.deepEqual(Array.from(topFive.datasets[0].data), ['60.00', '50.00', '30.00', '30.00', '20.00']);
    assert.deepEqual(Array.from(topFive.datasets[0].backgroundColor), ['F', 'E', 'B', 'D', 'A'], 'Per-point metadata follows its selected value');
    assert.equal(JSON.stringify(ranking), original, 'Display ranking must preserve the complete response');
    for (const id of ['expense-accounts-chart', 'revenue-accounts-chart']) {
        assert.deepEqual(Array.from(context.ledgerTopChartData(ranking, id, 'bar').labels), ['F', 'E', 'B']);
    }
    assert.strictEqual(context.ledgerTopChartData(ranking, 'generic-other-chart', 'bar'), ranking, 'Other charts keep their complete data');
    assert.strictEqual(context.ledgerTopChartData(ranking, 'constructor', 'bar'), ranking, 'Prototype names are not chart selectors');
    assert.strictEqual(context.ledgerTopChartData(ranking, 'categories-chart', 'line'), ranking, 'Non-bar renderers keep their own contracts');
    const large = {count: 1, labels: ['low', 'high', 'second'], datasets: [{data: ['9007199254740992.00', '9007199254740993.02', '9007199254740993.01']}]};
    assert.deepEqual(Array.from(context.ledgerTopChartData(large, 'revenue-accounts-chart', 'bar').labels), ['high', 'second', 'low'], 'Ranking preserves exact cents above binary floating-point integer precision');
    const none = {count: 1, labels: ['refund', 'zero'], datasets: [{currency_symbol: '₴', data: ['-50.00', '0.00']}]};
    assert.equal(context.ledgerTopChartData(none, 'expense-accounts-chart', 'bar').labels.length, 0);
    assert.equal(context.ledgerTopChartData(none, 'expense-accounts-chart', 'bar').count, 0);
    assert.equal(context.ledgerTopChartData(payloads.expense, 'expense-accounts-chart', 'bar').labels.length, 1, 'Fewer available positions are not padded with invented data');
    assert.throws(() => context.ledgerTopChartData({labels: ['bad'], datasets: [{data: ['NaN']}]}, 'categories-chart', 'bar'), /exact monetary/);
    assert.throws(() => context.ledgerTopChartData({labels: ['bad'], datasets: [{data: ['0.001']}]}, 'categories-chart', 'bar'), /exact monetary/);
    assert.throws(() => context.ledgerTopChartData({labels: ['bad'], datasets: [{data: []}]}, 'categories-chart', 'bar'), /aligned UAH/);
    assert.equal(created.get('expense-accounts-chart').data.datasets[0].backgroundColor, '#fca5a5', 'Expense colors survive native colorizeData');
    assert.equal(created.get('revenue-accounts-chart').data.datasets[0].backgroundColor, '#86efac', 'Revenue colors survive native colorizeData');
    payloads.ranking = ranking;
    vm.runInContext('columnChart("ranking", "categories-chart")', context);
    await tick();
    assert.deepEqual(Array.from(chart.data.labels), ['F', 'E', 'B', 'D', 'A'], 'The real native refresh uses top five rather than the full response');
    vm.runInContext('columnChart("ranking", "expense-accounts-chart")', context);
    await tick();
    assert.deepEqual(Array.from(created.get('expense-accounts-chart').data.labels), ['F', 'E', 'B']);
    assert.equal(created.get('expense-accounts-chart').data.datasets[0].backgroundColor, '#fca5a5');
    payloads.nonpositive = none;
    vm.runInContext('columnChart("nonpositive", "revenue-accounts-chart")', context);
    await tick();
    assert.equal(elements.get('revenue-accounts-chart').holder.innerHTML, '<p><em>No data</em></p>');
    vm.runInContext('columnChart("empty", "categories-chart")', context);
    await tick();
    assert.equal(elements.get('categories-chart').classes.has('general-chart-error'), false, 'An empty period must not access missing currency metadata or throw.');
    assert.equal(elements.get('categories-chart').holder.innerHTML, '<p><em>No data</em></p>', 'The native empty-period message remains intact.');
    console.log('Passed native top-five categories, top-three accounts, exact ranking, colors, refresh and empty-state checks.');
})().catch(error => {console.error(error); process.exitCode = 1;});
