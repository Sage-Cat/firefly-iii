import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import {execFileSync} from 'node:child_process';
import {pathToFileURL} from 'node:url';

const [chartPath, legacyPath, v3Path, legacyChartPath] = process.argv.slice(2);
assert.ok(chartPath && legacyPath && v3Path && legacyChartPath, 'Provide Chart.js 4 UMD, legacy charts.js, shared/draw-chart.js and Chart.js 2 UMD.');
const chart = fs.readFileSync(chartPath, 'utf8');
const legacy = fs.readFileSync(legacyPath, 'utf8');
const legacyChart = fs.readFileSync(legacyChartPath, 'utf8');
const v3 = fs.readFileSync(v3Path, 'utf8').replace(/^import[^\n]*\n/gm, '').replace('Chart.register(annotationPlugin);', '').replace(/\bexport\s+(?=function)/g, '');
const styles = fs.readFileSync(new URL('../../resources/views/reports/dashboard-chart-period-scripts.blade.php', import.meta.url), 'utf8')
    .match(/<style[\s\S]*?<\/style>/)[0].replace(/ nonce="[^"]*"/g, '');
const temporary = fs.mkdtempSync(path.join(os.tmpdir(), 'dashboard-period-layout-'));
const cases = ['live-0', 'live-1', 'live-2', 'live-3', 'legacy-empty', 'legacy-error', 'v3-empty', 'v3-error'];
const body = cases.map(id => `<section class="card-body"><div id="toolbar-${id}" class="toolbar"><button>30 днів</button><button>3 міс</button><button>1 рік</button></div><div class="dashboard-period-chart card-body p-0"><div class="dashboard-period-canvas"><canvas id="${id}"></canvas></div></div></section>`).join('');
const checks = String.raw`
window.maximumHeight=0;
const expected=innerWidth<=767?280:400;
const timer=setInterval(()=>{for(const id of ['live-0','live-1','live-2','live-3']){window.maximumHeight=Math.max(window.maximumHeight,document.getElementById(id).getBoundingClientRect().height);}},20);
for(const id of ['live-0','live-1','live-2','live-3']){new window.Chart4(document.getElementById(id),{type:'bar',data:{labels:['A','B'],datasets:[{data:[10,20]}]},options:{responsive:true,maintainAspectRatio:false,animation:false,plugins:{legend:{display:false}}}});}
var noDataForChart='Немає даних';
window.axios={get:(url)=>url==='error'?Promise.reject(new Error('synthetic failure')):Promise.resolve({data:[]})};
var i18next={t:key=>key==='firefly.no_data_for_chart'?'Немає даних':'Не вдалося завантажити графік'};
drawAChart('empty','legacy-empty','bar',{},false);
drawAChart('error','legacy-error','bar',{},false);
window.periodTestDrawV3('stacked-column','empty','v3-empty',false,false,false);
window.periodTestDrawV3('stacked-column','error','v3-error',false,false,false);
setTimeout(()=>{clearInterval(timer);const targetIds=['legacy-empty','legacy-error','v3-empty','v3-error'];document.body.setAttribute('data-check',JSON.stringify({width:innerWidth,page:document.documentElement.scrollWidth,expected,maximum:window.maximumHeight,errors:window.errors,heights:['live-0','live-1','live-2','live-3'].map(id=>document.getElementById(id).getBoundingClientRect().height),toolbars:targetIds.every(id=>document.getElementById('toolbar-'+id)?.isConnected&&document.querySelectorAll('#toolbar-'+id+' button').length===3),legacyEmpty:document.getElementById('toolbar-legacy-empty').nextElementSibling.innerText.includes('Немає даних'),legacyError:document.getElementById('legacy-error').classList.contains('general-chart-error'),v3Empty:document.getElementById('toolbar-v3-empty').nextElementSibling.innerText.includes('Немає даних'),v3Error:document.getElementById('toolbar-v3-error').nextElementSibling.innerText.includes('Не вдалося')}));},1200);
`;
try {
    for (const width of [1440, 390]) {
        const fixture = path.join(temporary, `fixture-${width}.html`);
        const html = '<!doctype html><html lang="uk"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">' + styles
            + '<style>body{margin:20px}.card-body{padding:10px}.p-0{padding:0}.toolbar{display:flex;gap:8px;margin:8px 0}button{min-height:44px}</style><body>' + body
            + '<script>window.errors=[];window.addEventListener("error",e=>window.errors.push(e.message));</script>'
            + '<script>' + chart.replace(/<\/script/gi, '<\\/script') + '</script><script>window.Chart4=window.Chart;</script><script>' + legacyChart.replace(/<\/script/gi, '<\\/script') + '</script><script>' + legacy.replace(/<\/script/gi, '<\\/script') + '</script><script>'
            + ('(function(Chart){' + v3 + ';window.periodTestDrawV3=drawMultiCurrencyChart;})(window.Chart4);').replace(/<\/script/gi, '<\\/script') + '</script><script>' + checks + '</script></body></html>';
        fs.writeFileSync(fixture, html);
        const profile = path.join(temporary, 'profile-' + width);
        const dom = execFileSync('/usr/bin/google-chrome', ['--headless', '--disable-gpu', '--disable-background-networking', '--disable-sync', '--no-first-run',
            '--no-default-browser-check', '--user-data-dir=' + profile, '--window-size=' + width + ',1000', '--virtual-time-budget=1700', '--dump-dom', pathToFileURL(fixture).href],
            {encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'], timeout: 30000, maxBuffer: 5 * 1024 * 1024});
        const encoded = dom.match(/data-check="([^"]+)"/)?.[1];
        assert.ok(encoded, 'The headless chart fixture did not finish.');
        const result = JSON.parse(encoded.replaceAll('&quot;', '"').replaceAll('&amp;', '&'));
        assert.deepEqual(result.errors, []);
        assert.ok(result.page <= result.width + 1, 'Period controls or chart canvases overflow.');
        assert.ok(result.maximum <= result.expected + 1 && result.heights.every(height => Math.abs(height - result.expected) <= 1), 'Chart.js must keep bounded plot height.');
        assert.ok(result.toolbars && result.legacyEmpty && result.legacyError && result.v3Empty && result.v3Error,
            'Native empty/error rendering must preserve every selector toolbar.');
    }
    console.log('Passed desktop/mobile Chart.js height and native empty/error toolbar-preservation checks.');
} finally {
    fs.rmSync(temporary, {recursive: true, force: true});
}
