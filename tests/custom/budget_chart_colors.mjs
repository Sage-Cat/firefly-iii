import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import {execFileSync} from 'node:child_process';
import {fileURLToPath} from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const [drawPath = path.join(root, 'resources/assets/v3/js/shared/draw-chart.js'),
    categoryPath = path.join(root, 'public/v1/js/ff/charts.js'),
    chartPath = path.join(root, 'node_modules/chart.js/dist/chart.umd.js')] = process.argv.slice(2);
let draw = fs.readFileSync(drawPath, 'utf8');
let expose;
if (draw.includes('export function drawMultiCurrencyChart(')) {
    draw = draw.replace(/^import[^\n]*\n/gm, '').replace('Chart.register(annotationPlugin);', '').replace(/\bexport\s+(?=function)/g, '');
    expose = 'window.budgetDraw=drawMultiCurrencyChart;window.BudgetChart=Chart;';
    draw = '(function(Chart,formatMoney,i18next){' + draw + expose + '})(window.Chart,window.testMoney,window.testTranslations);';
} else {
    const entry = draw.match(/export\{[^}]*?([\w$]+) as t\};/)?.[1];
    const constructor = draw.match(/new ([\w$]+)\(document\.getElementById/)?.[1];
    assert.ok(entry && constructor, 'The compiled chart entry and actual Chart.js 4 constructor must remain discoverable.');
    draw = draw.replace(/import\{([^}]+)\}from["`]([^"`]+)["`];/g, (_all, bindings, asset) => {
        const name = bindings.trim().split(/\s+as\s+/).at(-1);
        if (asset.includes('format-money-')) return `const ${name}=window.testMoney;`;
        if (asset.includes('format-date-')) return `const ${name}=()=>'';`;
        assert.ok(asset.includes('dates-'), 'Unexpected compiled chart dependency: ' + asset);
        return `const ${name}=window.testTranslations;`;
    }).replace(/export\{[^}]+\};/g, '');
    draw = '(function(){' + draw + `;window.budgetDraw=${entry};window.BudgetChart=${constructor};})();`;
}
const legacy = fs.readFileSync(categoryPath, 'utf8');
const category = legacy.slice(legacy.indexOf('function ledgerCategoryColor('), legacy.indexOf('function ledgerTopChartColors('));
assert.ok(category.startsWith('function ledgerCategoryColor('), 'Use the actual synchronous semantic palette.');
const chart = fs.readFileSync(chartPath, 'utf8');
const script = String.raw`
let checks=0;const failures=[];
function check(value,message){checks++;if(!value)failures.push(message);}
function same(actual,expected,message){check(JSON.stringify(actual)===JSON.stringify(expected),message+': '+JSON.stringify(actual));}
const row=(label,currency_code,budgeted,spent,left,overspent)=>({label,currency_code,entries:{budgeted:String(budgeted),spent:String(spent),left:String(left),overspent:String(overspent)}});
const longName='Довга назва бюджету харчування для перевірки перенесення слів';
const rows=[row('Нульовий перед усіма','UAH',0,0,0,0),row('Продукти','UAH',100,-40,60,0),row('Транспорт','UAH',100,-125,0,25),row('Підписки','EUR',50,-50,0,0),row('Нульовий посередині','EUR',0,0,0,0),row(longName,'UAH',0,-12.75,0,0),row('Здоров’я','UAH',80,0,80,0)];
const visible=rows.filter(r=>Object.values(r.entries).some(value=>Number(value)!==0));
const palette=window.ledgerCategoryColor;
let chartInstance;
async function render(payload,{holder='budgets-chart',anonymous=false,colorData=true,provider=palette,reject=false}={}){
    chartInstance?.destroy();chartInstance=undefined;
    document.getElementById('fixture').innerHTML='<section><canvas id="'+holder+'"></canvas></section>';
    window.ledgerCategoryColor=provider;
    window.axios={get:()=>reject?Promise.reject(new Error('synthetic failure')):Promise.resolve({data:structuredClone(payload)})};
    window.budgetDraw('stacked-column','synthetic',holder,anonymous,false,colorData);
    await new Promise(resolve=>setTimeout(resolve,60));
    chartInstance=window.BudgetChart.getChart(holder);
    if(chartInstance){chartInstance.options.animation=false;chartInstance.update('none');}
    return chartInstance;
}
(async()=>{
    const seen=[];
    const chart=await render(rows,{provider:name=>{seen.push(name);return palette(name);}});
    check(!!chart,'Real Chart.js 4 creates the homepage chart');
    same(seen,visible.map(r=>r.label),'Palette receives raw names after zero filtering, before wrapping and currency suffixes');
    check(chart.data.labels.length===5,'Every nonzero budget is visible');
    same(chart.data.labels.map(parts=>parts.join(' ')),visible.map(r=>r.label+' ('+r.currency_code+')'),'Wrapped labels preserve names and currencies');
    check(chart.data.labels[3].length>1,'A long raw budget name is actually wrapped');
    same(chart.data.datasets.map(d=>d.label),['budgetedUAH','spentUAH','leftUAH','overspentUAH','budgetedEUR','spentEUR','leftEUR','overspentEUR'],'Four stack meanings and currency ordering remain unchanged');
    const expected={budgetedUAH:[0,100,0,0,0,0,0],spentUAH:[40,0,0,12.75,0,0,0],leftUAH:[60,0,0,0,80,0,0],overspentUAH:[0,25,0,0,0,0,0],budgetedEUR:[0,0,50,0,0,0,0],spentEUR:[0,0,0,0,0,0,0],leftEUR:[0,0,0,0,0,0,0],overspentEUR:[0,0,0,0,0,0,0]};
    const offsets={budgeted:0,spent:-10,left:12,overspent:-22};
    for(const [index,dataset] of chart.data.datasets.entries()){
        same(dataset.data,expected[dataset.label],'Exact stack amounts '+dataset.label);
        check(dataset.yAxisID==='y'+dataset.currency_code,'Currency axis retained');
        const kind=Object.keys(offsets).find(k=>dataset.label===k+dataset.currency_code);
        for(let i=0;i<visible.length;i++){
            const base=palette(visible[i].label).background.match(/hsl\((\d+), (\d+)%, (\d+)%\)/).slice(1).map(Number);
            for(const [field,delta] of [['backgroundColor',0],['borderColor',-18]]){
                const color=dataset[field][i],hue=Number(color.match(/hsl\((\d+)/)?.[1]);
                check((hue>=25&&hue<=60)||(hue>=195&&hue<=290),'Budget fill/border excludes red and green');
                check(color==='hsl('+base[0]+', '+base[1]+'%, '+(base[2]+offsets[kind]+delta)+'%)','Stable semantic hue and stack lightness');
                check(chart.getDatasetMeta(index).data[i].options[field]===color,'Actual Chart.js bar resolves per-budget '+field);
            }
        }
        const tooltip=chart.options.plugins.tooltip.callbacks.label({dataIndex:1,dataset});
        check(tooltip==='firefly.'+kind+': '+dataset.data[1]+' '+dataset.currency_code,'Translated stack tooltip and currency unchanged');
    }
    check(new Set(chart.data.datasets.slice(0,4).map(d=>d.backgroundColor[0])).size===4,'All four stack meanings have distinct stable shades');
    check(chart.options.plugins.legend.display===false&&chart.options.scales.x.stacked&&chart.options.scales.yUAH.stacked&&chart.options.scales.yEUR.stacked,'Legend and currency stacking options unchanged');
    check(chart.options.scales.yEUR.ticks.callback(42)==='42 EUR','Axis money formatter unchanged');
    check(chart.options.plugins.tooltip.callbacks.title([{label:'A,B'}])==='A B','Tooltip title unchanged');
    const originalColors=Object.fromEntries(visible.map((r,i)=>[r.label,chart.data.datasets[0].backgroundColor[i]]));
    const refreshed=await render([...rows].reverse().map(r=>({...r,entries:{...r.entries,spent:String(Number(r.entries.spent)*2)}})));
    for(let i=0;i<visible.length;i++)check(refreshed.data.datasets[0].backgroundColor[i]===originalColors[visible.at(-i-1).label],'Color follows raw name after period/order/amount changes');
    const duplicate=await render([row('  ПРОДУКТИ  ','UAH',100,-10,90,0),row('Продукти','EUR',100,-20,80,0)]);
    same(duplicate.data.datasets[0].backgroundColor,[originalColors['Продукти'],originalColors['Продукти']],'Normalized raw names have one color independent of currency');
    const many=await render(Array.from({length:20},(_,i)=>row('Новий бюджет '+i,'UAH',100,-i-1,99-i,0)));
    check(many.data.labels.length===20,'Budget palette retains every budget without a top-N limit');
    const anonymous=await render(rows,{anonymous:true});
    for(const dataset of anonymous.data.datasets){same(dataset.data,expected[dataset.label],'Anonymous retains source stack amounts');check(anonymous.options.plugins.tooltip.callbacks.label({dataIndex:1,dataset}).endsWith(': 0 '+dataset.currency_code),'Anonymous tooltips hide amounts');}
    check(anonymous.options.scales.yUAH.ticks.callback(42)==='0 UAH','Anonymous axis hides amounts');
    const other=await render(rows,{holder:'other-budget-chart',provider:null});
    check(typeof other.data.datasets[0].backgroundColor==='string'&&other.data.datasets[0].backgroundColor==='rgba(18,124,175, 0.5)','Other holders retain original generic palette');
    check(other.data.datasets[1].backgroundColor==='rgba(200,78,16, 0.5)'&&other.data.datasets[2].backgroundColor==='rgba(18,130,125, 0.5)','Other holders preserve spent and left meanings');
    check(!!await render(rows,{colorData:false}),'Homepage palette also works without generic coloring');
    for(const empty of [[],[row('Порожній','UAH',0,0,0,0)]]){
        const result=await render(empty,{provider:null});
        check(!result&&document.getElementById('fixture').innerText==='firefly.no_data_for_chart','Empty chart keeps its existing message without a provider');
    }
    for(const provider of [null,()=>({background:'hsl(120, 70%, 65%)'})]){
        const result=await render(rows,{provider});
        check(!result&&document.getElementById('fixture').innerText.includes('firefly.could_not_load_chart'),'Missing or unsafe palette follows the existing controlled error path');
    }
    check(!await render(rows,{reject:true})&&document.getElementById('fixture').innerText.includes('firefly.could_not_load_chart'),'API error behavior unchanged');
    document.body.setAttribute('data-check',JSON.stringify({width:innerWidth,checks,failures,errors:window.errors}));
})().catch(error=>document.body.setAttribute('data-check',JSON.stringify({width:innerWidth,checks,failures:[error.stack],errors:window.errors})));
`;
const temporary = fs.mkdtempSync(path.join(os.tmpdir(), 'budget-chart-colors-'));
const safe = value => value.replace(/<\/script/gi, '<\\/script');
try {
    const fixture = path.join(temporary, 'fixture.html');
    fs.writeFileSync(fixture, '<!doctype html><html lang="uk"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>#fixture{width:900px}section{height:320px}</style><body><div id="fixture"></div>'
        + '<script>window.errors=[];window.addEventListener("error",event=>window.errors.push(event.message));window.testMoney=(amount,currency)=>String(amount)+" "+currency;window.testTranslations={t:key=>key};</script>'
        + '<script>' + safe(chart) + '</script><script>' + safe(category) + '</script><script>' + safe(draw) + '</script><script>' + safe(script) + '</script></body></html>');
    const helper = fileURLToPath(new URL('./browser_viewport.cjs', import.meta.url));
    execFileSync(process.execPath, [helper, fixture, '1200', path.join(temporary, 'result')], {stdio: ['ignore', 'pipe', 'pipe'], timeout: 30000});
    const html = fs.readFileSync(path.join(temporary, 'result.html'), 'utf8');
    const encoded = html.match(/data-check="([^"]+)"/)?.[1];
    assert.ok(encoded, 'Actual Chart.js 4 checks must finish.');
    const result = JSON.parse(encoded.replaceAll('&quot;', '"').replaceAll('&amp;', '&').replaceAll('&lt;', '<').replaceAll('&gt;', '>'));
    assert.deepEqual(result.errors, [], 'Unexpected browser errors');
    assert.deepEqual(result.failures, [], 'Actual Chart.js 4 regressions');
    assert.ok(result.checks >= 300);
    console.log(`Passed ${result.checks} actual Chart.js 4 budget palette/stack/order/anonymous/empty checks.`);
} finally {
    fs.rmSync(temporary, {recursive: true, force: true});
}
