import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import {execFileSync} from 'node:child_process';
import {fileURLToPath} from 'node:url';

assert.ok(process.argv[2], 'Provide the synthetic render JSON from control_pages.php.');
const bootstrap = process.argv[3] ? fs.readFileSync(process.argv[3], 'utf8') : '';
const data = JSON.parse(fs.readFileSync(process.argv[2], 'utf8'));
const helper = fileURLToPath(new URL('./browser_viewport.cjs', import.meta.url));
const temporary = fs.mkdtempSync(path.join(os.tmpdir(), 'firefly-controls-'));
try {
    for (const width of [390, 1440]) {
        const check = `setTimeout(async()=>{let menu=null;if(window.bootstrap){const button=document.querySelector('.piggy-sortable [data-bs-toggle=dropdown]'),scroll=button.closest('.table-responsive');button.scrollIntoView({block:'center',inline:'nearest'});const dropdown=new bootstrap.Dropdown(button);dropdown.show();await new Promise(resolve=>setTimeout(resolve,100));const last=button.nextElementSibling.querySelector('li:last-child a'),hit=()=>{const r=last.getBoundingClientRect();return last.contains(document.elementFromPoint(r.x+r.width/2,r.y+r.height/2))};const before=hit();if(innerWidth<768)scroll.scrollLeft=Math.max(0,scroll.scrollLeft-20);await new Promise(resolve=>setTimeout(resolve,100));menu={before,after:hit(),position:button.nextElementSibling.style.position};}const visible=node=>node.getClientRects().length>0&&getComputedStyle(node).visibility!=='hidden',rect=node=>{const r=node.getBoundingClientRect();return{width:r.width,right:r.right,left:r.left}},bars=[...document.querySelectorAll('.piggyBar [role=progressbar],.d-lg-none [role=progressbar]')].filter(visible);document.body.setAttribute('data-check',JSON.stringify({width:innerWidth,page:document.documentElement.scrollWidth,bars:bars.map(node=>({now:node.getAttribute('aria-valuenow'),width:node.getBoundingClientRect().width,fill:node.firstElementChild.getBoundingClientRect().width})),cards:[...document.querySelectorAll('.card')].map(rect),links:[...document.querySelectorAll('a[href]')].filter(visible).map(node=>({tab:node.tabIndex,href:node.getAttribute('href')})),targetDate:document.body.textContent.includes('31.01.2027'),queue:document.getElementById('control-renewals-title').textContent,menu,errors:window.errors}));},100);`;
        const html = '<!doctype html><html lang="uk" data-bs-theme="dark"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>' + data.css + '</style><body><main class="container-fluid p-3">' + data.budget + '<div class="row g-3"><div class="col-xl-8">' + data.queue + data.piggyList + data.empty + '</div><div class="col-xl-4">' + data.overview + '</div></div></main><script>' + bootstrap.replaceAll('</script', '<\\/script') + '</script><script>window.errors=[];window.addEventListener("error",e=>window.errors.push(e.message));' + check + '</script></body></html>';
        const fixture = path.join(temporary, `fixture-${width}.html`);
        const prefix = path.join(temporary, `check-${width}`);
        fs.writeFileSync(fixture, html);
        execFileSync(process.execPath, [helper, fixture, String(width), prefix], {stdio: ['ignore', 'pipe', 'pipe'], timeout: 30000});
        const rendered = fs.readFileSync(prefix + '.html', 'utf8');
        const result = JSON.parse(rendered.match(/data-check="([^"]+)"/)[1].replaceAll('&quot;', '"').replaceAll('&amp;', '&'));
        assert.deepEqual(result.errors, []);
        if (bootstrap) assert.ok(result.menu.before && result.menu.after && result.menu.position === 'fixed', 'Piggy action menus escape the table scroll clip and remain clickable after scrolling.');
        assert.ok(result.page <= width + 1, 'The real native CSS must contain the goal table in its responsive card: ' + JSON.stringify(result));
        assert.ok(result.cards.every(card => card.left >= 0 && card.right <= width + 1), 'Summary, renewal, savings and annual-cost cards fit the viewport.');
        assert.equal(result.bars.length, 1, 'Only the appropriate desktop/mobile one-percent goal indicator is visible.');
        assert.equal(result.bars[0].now, '1');
        assert.ok(Math.abs(result.bars[0].fill / result.bars[0].width - 0.01) < 0.01, 'Visible progress is one percent rather than a fabricated minimum of thirty percent.');
        assert.ok(result.targetDate && result.links.length >= 3 && result.links.every(link => link.tab === 0 && link.href), 'Native detail/create actions retain keyboard access and the goal date remains available.');
    }
    console.log('Passed actual native Blade/CSS control cards, honest goal progress, keyboard links and overflow at 390px/1440px.');
} finally {
    fs.rmSync(temporary, {recursive: true, force: true});
}
