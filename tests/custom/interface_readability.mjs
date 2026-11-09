import assert from 'node:assert/strict';
import crypto from 'node:crypto';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import {execFileSync} from 'node:child_process';
import {fileURLToPath} from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const read = relative => fs.readFileSync(path.join(root, relative), 'utf8');
const bootstrap = fs.readFileSync(process.argv[2] || path.join(root, 'node_modules/bootstrap/dist/js/bootstrap.bundle.min.js'), 'utf8');
const manifest = JSON.parse(read('public/build/manifest.json'));
let assets = 0;
for (const entry of Object.values(manifest)) {
    if (!entry.file.endsWith('.js')) continue;
    const filename = path.join(root, 'public/build', entry.file);
    const body = fs.readFileSync(filename, 'utf8');
    assert.equal(entry.integrity, 'sha384-' + crypto.createHash('sha384').update(body).digest('base64'));
    for (const dependency of [...(entry.imports || []), ...(entry.dynamicImports || [])]) assert.ok(manifest[dependency]);
    for (const [, dependency] of body.matchAll(/["'](\.{1,2}\/[^"'?#]+\.js)["']/g)) {
        assert.ok(fs.existsSync(path.resolve(path.dirname(filename), dependency)));
    }
    assets++;
}
const drawEntry = Object.values(manifest).find(entry => entry.name === 'draw-chart');
assert.ok(drawEntry && /\.defaults\.font\.size=13/.test(read('public/build/' + drawEntry.file)),
    'The normal Vite build retains the reviewed Chart.js 4 font override.');
const native = {css: read('public/build/' + manifest['sass/app.scss'].file), view: read('resources/views/index.blade.php'), budget: read('resources/views/budgets/index.blade.php'),
    legacy: read('public/v1/js/lib/Chart.bundle.min.js') + '\n' + read('public/v1/js/ff/charts.defaults.js'), assets};
native.css = native.css.replace(/url\(([^)]+\.woff2)\)/g, (original, value) => {
    const filename = path.join(root, 'public/build/assets', path.basename(value.replace(/["']/g, '')));
    return fs.existsSync(filename) ? 'url(data:font/woff2;base64,' + fs.readFileSync(filename).toString('base64') + ')' : original;
});
for (const layout of fs.readdirSync(path.join(root, 'resources/views/layout/v3'))) {
    if (layout.endsWith('.blade.php')) assert.ok(read('resources/views/layout/v3/' + layout).includes("@vite(['sass/app.scss'])"));
}
let legacyViews = 0;
function verifyViews(directory) {
    for (const entry of fs.readdirSync(directory, {withFileTypes: true})) {
        const filename = path.join(directory, entry.name);
        if (entry.isDirectory()) {verifyViews(filename); continue;}
        if (!entry.name.endsWith('.blade.php')) continue;
        const body = fs.readFileSync(filename, 'utf8');
        const vendor = body.indexOf('v1/js/lib/Chart.bundle.min.js');
        if (vendor < 0) continue;
        legacyViews++;
        const defaults = body.indexOf("asset('v1/js/ff/charts.defaults.js')");
        assert.ok(defaults > vendor, 'Each legacy consumer applies the existing native defaults after the unchanged vendor bundle.');
        assert.equal(body.split("asset('v1/js/ff/charts.defaults.js')").length - 1, 1);
        const tag = body.slice(body.lastIndexOf('<script', defaults), body.indexOf('</script>', defaults));
        assert.ok(tag.includes("hash_file('sha256', public_path('v1/js/ff/charts.defaults.js'))"));
        assert.ok(tag.includes("hash_file('sha384', public_path('v1/js/ff/charts.defaults.js'), true)"));
        assert.ok(tag.includes('nonce="{{ $JS_NONCE }}"') && tag.includes('crossorigin="anonymous"'));
        assert.ok(!tag.includes(' defer') && !tag.includes(' async'));
    }
}
verifyViews(path.join(root, 'resources/views'));
assert.equal(legacyViews, 16);
const order = ['categories-chart', 'revenue-accounts-chart', 'expense-accounts-chart'].map(id => native.view.indexOf(`id="${id}"`));
assert.ok(order.every(position => position >= 0) && order[0] < order[1] && order[1] < order[2], 'Income and spending cards must follow categories in that order');
assert.ok(order[2] < native.view.indexOf('id="all_transactions"'), 'Both cards stay in the main chart column');
let draw = read('node_modules/chart.js/dist/chart.umd.js') + ';window.Chart4=window.Chart;';
const drawSource = read('resources/assets/v3/js/shared/draw-chart.js').replace(/^import[^\n]*\n/gm, '')
    .replace('Chart.register(annotationPlugin);', '').replace(/\bexport\s+(?=function)/g, '');
draw += '(function(Chart){' + drawSource + '})(window.Chart4);';
const viewPaths = {'dashboard-capital-note.blade.php': 'reports/dashboard-capital-note.blade.php',
    'dashboard-chart-period-scripts.blade.php': 'reports/dashboard-chart-period-scripts.blade.php',
    'subscription-overview.blade.php': 'bills/subscription-overview.blade.php', 'payment-review.blade.php': 'reports/payment-review.blade.php'};
const styles = name => (read('resources/views/' + viewPaths[name]).match(/<style[\s\S]*?<\/style>/g) || []).join('').replace(/ nonce="[^"]*"/g, '');
const rootRules = native.css.match(/:root\{font-size:106\.25%\}/g) || [];
assert.equal(rootRules.length, 1, 'The actual built Sass scales the root once.');
const typography = '<style>' + rootRules[0] + '</style>';
native.css = native.css.replace(rootRules[0], '');
assert.ok(!/!important|\bbody\s*\{|\bbutton\s*\{/.test(typography), 'Typography scales the root instead of overriding controls');
const toolbar = '<div class="dashboard-chart-period d-flex flex-wrap align-items-center gap-2 mb-2"><div class="btn-group btn-group-sm"><a class="btn btn-primary">30 днів</a><a class="btn btn-outline-secondary">3 міс</a><a class="btn btn-outline-secondary">1 рік</a></div><span class="small text-muted">11.10.2026–09.11.2026 · ₴</span></div>';
const chartNames = ['accounts-chart', 'budgets-chart', 'categories-chart', 'revenue-accounts-chart', 'expense-accounts-chart'];
const chartColumn = native.view.slice(native.view.indexOf('    <div class="row" x-data="index">'), native.view.indexOf('<div class="col-lg-4 col-md-12 col-sm-12" id="all_transactions">'))
    .replace(/@include\('reports\.dashboard-(?:capital-note|chart-period)'[^\n]*/g, toolbar)
    .replace(/\{\{[\s\S]*?\}\}/g, expression => expression.includes('expense_accounts') ? 'Рахунки витрат'
        : expression.includes('revenue_accounts') ? 'Рахунки доходів' : expression.includes('categories') ? 'Категорії' : 'Бюджети та витрати');
const dashboard = chartColumn + '<aside class="col-lg-4"><div class="card card-body">Останні операції <span class="small">Приклад запису</span></div></aside></div>';
const table = '<div class="table-responsive"><table class="table"><thead><tr><th>Категорія</th><th>Період</th><th class="text-end">Залишок</th></tr></thead><tbody><tr><td>Підписки й робочі послуги</td><td>Листопад 2026</td><td class="text-end text-nowrap">12 345,67 ₴</td></tr></tbody></table></div>';
const budget = `<div class="card"><div class="card-header"><h2 class="card-title">Бюджети та витрати</h2></div><div class="card-body"><p>Ліміти поточного місяця</p>${table}<label class="form-label" for="budget-limit">Сума ліміту</label><input id="budget-limit" class="form-control" value="12 345,67"><button class="btn btn-primary mt-2">Переглянути</button></div></div>`;
const profit = `<div class="card card-body"><h2>Доходи, витрати та результат</h2><div class="d-flex flex-wrap gap-3 mb-3"><div class="btn-group"><a class="btn btn-sm btn-primary">₴</a><a class="btn btn-sm btn-outline-secondary">€</a></div><div class="btn-group"><a class="btn btn-sm btn-outline-secondary">30 днів</a><a class="btn btn-sm btn-outline-secondary">3 місяці</a><a class="btn btn-sm btn-outline-secondary">1 рік</a></div></div><form class="row g-2 align-items-end"><div class="col-sm-4"><label class="form-label">Від</label><input type="date" class="form-control" value="2026-10-11"></div><div class="col-sm-4"><label class="form-label">До</label><input type="date" class="form-control" value="2026-11-09"></div><div class="col-sm-4"><button class="btn btn-primary" type="button">Показати</button></div></form><p class="small mt-2">Валюта цього звіту не змінює основну валюту системи.</p>${table}</div>`;
const subscriptions = '<aside id="subscription-overview" class="card"><div class="card-header"><h2 class="card-title">Витрати на підписки</h2></div><div class="card-body"><p class="small">Середня місячна вартість активних підписок.</p><div class="fs-3">12 345,67 ₴</div><ul class="list-unstyled"><li class="d-flex gap-2 align-items-start py-2 border-bottom"><span class="subscription-swatch"></span><a class="subscription-name flex-grow-1">Хмарне сховище та інструменти для роботи</a><span class="text-nowrap fw-semibold">1 234,56 ₴</span></li></ul></div></aside>';
const review = '<div id="payment-review"><article class="payment-card card"><div class="payment-card-header"><div><div class="payment-meta">09.11.2026 · Особистий рахунок</div><h2 class="payment-card-title payment-description">Оплата робочих інструментів і послуг</h2></div><div class="payment-amount-block"><span class="payment-amount-label">Сума</span><strong class="payment-amount">1 234,56 ₴</strong></div></div><div class="payment-card-body"><div class="payment-category-layout"><div class="payment-choice"><label class="payment-section-title">Категорія</label><div class="payment-select-row"><select class="form-select"><option>Робочі інструменти</option></select><button class="btn btn-primary">Зберегти</button></div></div><div><p class="payment-hint">Рекомендовані категорії</p><button class="payment-recommendation btn"><span class="payment-recommendation-name">Підписки та сервіси</span></button></div></div></div></article></div>';
assert.match(native.budget, /class="card-body p-0 table-responsive">\s*<table[^>]*id="budgetList"/, 'The native budget table scrolls inside its card when enlarged text needs more width');
assert.equal((native.budget.match(/data-bs-config='\{"popperConfig":\{"strategy":"fixed"\}\}'/g) || []).length, 3, 'Every native budget action and limit dropdown uses viewport positioning');
const temporary = fs.mkdtempSync(path.join(os.tmpdir(), 'interface-readability-'));
const safeScript = text => text.replace(/<\/script/gi, '<\\/script');
try {
    for (const [width, increased] of [[1440, false], [1440, true], [720, true], [390, true]]) {
        const charts = String.raw`
window.errors=[];window.addEventListener('error',event=>window.errors.push(event.message));
window.chartInstances=[];
for(const [index,id]of ['accounts-chart','budgets-chart','categories-chart','revenue-accounts-chart','expense-accounts-chart'].entries()){
document.getElementById(id).closest('.card').dataset.chart=id;
const Chart=index<2?window.Chart4:window.Chart;
const options={responsive:true,maintainAspectRatio:false,animation:false};
window.chartInstances.push(new Chart(document.getElementById(id),{type:'bar',data:{labels:['Жовтень','Листопад'],datasets:[{label:'Приклад',data:[10,20]}]},options}));
}
setTimeout(()=>{const css=selector=>parseFloat(getComputedStyle(document.querySelector(selector)).fontSize);const controls=Array.from(document.querySelectorAll('input,select,button,.btn'));document.body.setAttribute('data-check',JSON.stringify({width:innerWidth,page:document.documentElement.scrollWidth,root:css('html'),body:css('body'),heading:css('#type-heading'),small:css('#type-small'),normal:css('#type-normal'),button:css('#type-button'),smallButton:css('#type-small-button'),largeButton:css('#type-large-button'),input:css('#type-input'),fonts:[window.Chart4.defaults.font.size,window.Chart.defaults.global.defaultFontSize],canvas:window.chartInstances.map(chart=>({height:chart.canvas.getBoundingClientRect().height,width:chart.canvas.getBoundingClientRect().width})),overflow:controls.filter(node=>node.getBoundingClientRect().right>innerWidth+1||node.getBoundingClientRect().left< -1).map(node=>node.outerHTML.slice(0,100)),order:Array.from(document.querySelectorAll('[data-chart]')).map(node=>node.dataset.chart),errors:window.errors}));},1000);
`;
        const html = '<!doctype html><html lang="uk"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><style>' + native.css + '</style>'
            + (increased ? typography : '') + styles('dashboard-capital-note.blade.php') + styles('dashboard-chart-period-scripts.blade.php') + styles('subscription-overview.blade.php') + styles('payment-review.blade.php')
            + '<body><main class="container-fluid py-3"><section class="card card-body mb-3"><h1 id="type-heading">Огляд фінансів</h1><p id="type-normal">Звичайний текст сторінки</p><p id="type-small" class="small">Допоміжний текст</p><div class="d-flex flex-wrap gap-2"><button id="type-button" class="btn btn-primary">Звичайна</button><button id="type-small-button" class="btn btn-sm btn-primary">Менша</button><button id="type-large-button" class="btn btn-lg btn-primary">Більша</button></div><input id="type-input" class="form-control mt-2" value="Приклад"></section>'
            + [dashboard, budget, profit, subscriptions, review].map(content => `<section class="mb-4">${content}</section>`).join('')
            + '</main><script>' + safeScript(draw) + '</script><script>' + safeScript(native.legacy) + '</script><script>' + charts + '</script></body></html>';
        const fixture = path.join(temporary, `${width}-${increased}.html`);
        fs.writeFileSync(fixture, html);
        const output = path.join(temporary, `viewport-${width}-${increased}`);
        execFileSync(process.execPath, [fileURLToPath(new URL('./browser_viewport.cjs', import.meta.url)), fixture, String(width), output],
            {stdio: ['ignore', 'pipe', 'pipe'], timeout: 30000});
        const dom = fs.readFileSync(output + '.html', 'utf8');
        const encoded = dom.match(/data-check="([^"]+)"/)?.[1];
        assert.ok(encoded, 'The real browser fixture must complete');
        const result = JSON.parse(encoded.replaceAll('&quot;', '"').replaceAll('&amp;', '&'));
        assert.equal(result.width, width, 'Chrome must use the requested device viewport rather than its minimum window width');
        assert.deepEqual(result.errors, []);
        assert.ok(Math.abs(result.body - (increased ? 15.3 : 14.4)) < 0.02, 'The existing 0.9rem base gains approximately one pixel');
        assert.equal(result.root, increased ? 17 : 16);
        assert.ok(result.heading > result.body && result.small < result.body, 'Heading and helper text hierarchy is preserved');
        assert.ok(result.smallButton < result.button && result.button < result.largeButton && Math.abs(result.input - result.button) < 0.02, 'Bootstrap control sizes keep their hierarchy');
        assert.deepEqual(result.fonts, [13, 13], 'Real Chart.js 4 and 2 use the larger default font');
        assert.ok(result.page <= result.width + 1 && result.overflow.length === 0, 'Representative money UI must not overflow horizontally: ' + JSON.stringify(result));
        assert.deepEqual(result.order, chartNames);
        assert.ok(result.canvas.every((canvas, index) => Math.abs(canvas.height - (result.width <= 767 ? 280 : index === 0 ? 360 : 400)) <= 1 && canvas.width > 0 && canvas.width <= result.width), 'Existing desktop/mobile chart height constraints are preserved: ' + JSON.stringify(result.canvas));
    }
    for (const width of [390, 1440]) {
        for (const count of [1, 6]) {
            for (const kind of ['actions', 'limit-new', 'limit-existing']) {
                const rows = Array.from({length: count}, (_, index) => `<tr><td style="min-width:540px">Бюджет ${index + 1}</td><td style="min-width:200px"><div class="dropdown"><button id="menu-${index}" type="button" class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown" data-bs-config='{"popperConfig":{"strategy":"fixed"}}'>${kind === 'actions' ? 'Дії' : 'Ліміт'}</button><ul class="dropdown-menu ${kind === 'actions' ? '' : 'dropdown-menu-end'}"><li><a class="dropdown-item" href="#">Переглянути</a></li><li><a class="dropdown-item" href="#">Редагувати</a></li><li><a class="dropdown-item" href="#">Останній пункт</a></li></ul></div></td></tr>`).join('');
                const check = `window.errors=[];window.addEventListener('error',event=>window.errors.push(event.message));(async()=>{const box=document.getElementById('scroll'),button=document.getElementById('menu-${count - 1}');button.scrollIntoView({block:'center',inline:'nearest'});const menu=button.nextElementSibling,dropdown=new bootstrap.Dropdown(button);dropdown.show();await new Promise(r=>setTimeout(r,150));const visible=()=>{const item=menu.querySelector('li:last-child a'),r=item.getBoundingClientRect();return item.contains(document.elementFromPoint(r.x+r.width/2,r.y+r.height/2))};const before=visible();if(innerWidth<768)box.scrollLeft-=20;await new Promise(r=>setTimeout(r,150));document.body.setAttribute('data-check',JSON.stringify({width:innerWidth,page:document.documentElement.scrollWidth,position:menu.style.position,visibleBefore:before,visibleAfter:visible(),scroll:box.scrollLeft,errors:window.errors}));})();`;
                const html = '<!doctype html><html lang="uk"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>' + native.css + '</style>' + typography + '<body style="margin:16px"><section class="card"><div id="scroll" class="card-body p-0 table-responsive"><table class="table mb-0"><tbody>' + rows + '</tbody></table></div></section><script>' + safeScript(bootstrap) + '</script><script>' + check + '</script></body></html>';
                const fixture = path.join(temporary, `menu-${width}-${count}-${kind}.html`);
                fs.writeFileSync(fixture, html);
                const output = fixture + '-viewport';
                execFileSync(process.execPath, [fileURLToPath(new URL('./browser_viewport.cjs', import.meta.url)), fixture, String(width), output], {stdio: ['ignore', 'pipe', 'pipe'], timeout: 30000});
                const dom = fs.readFileSync(output + '.html', 'utf8');
                const result = JSON.parse(dom.match(/data-check="([^"]+)"/)[1].replaceAll('&quot;', '"').replaceAll('&amp;', '&'));
                assert.equal(result.width, width);
                assert.deepEqual(result.errors, []);
                assert.ok(result.page <= width + 1 && result.position === 'fixed' && result.visibleBefore && result.visibleAfter, 'Every budget menu item remains visible and clickable outside the scroll clip: ' + JSON.stringify(result));
                if (width === 390) assert.ok(result.scroll > 0, 'The menu must remain accessible after horizontal table scrolling');
            }
        }
    }
    console.log(`Passed native Sass/chart typography, ${legacyViews} legacy consumers and ${native.assets} JS integrity/import checks; real Chrome baseline/desktop/tablet/mobile layouts preserve hierarchy, chart fonts, card order and overflow constraints; 12 Bootstrap budget-menu cases remain accessible after scrolling.`);
} finally {
    fs.rmSync(temporary, {recursive: true, force: true});
}
