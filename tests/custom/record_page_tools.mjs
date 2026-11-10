import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import {execFileSync} from 'node:child_process';
import {fileURLToPath} from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const image = process.argv[2] || 'firefly-iii-local:6.7.9';
const temporary = fs.mkdtempSync(path.join(os.tmpdir(), 'record-page-tools-'));
const probe = String.raw`
require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
foreach (glob('/work/resources/views/reports/record-*.blade.php') as $file) {copy($file, '/var/www/html/resources/views/reports/'.basename($file));}
$render = static fn ($name, $data = []) => view('reports.'.$name, array_merge(['JS_NONCE'=>'fixture','anonymous'=>false], $data))->render();
$filter = $render('record-filter', ['recordFilterKey'=>'fixture','recordFilterRootSelector'=>'#fixture-rows','recordFilterRowSelector'=>'.fixture-row']);
$quotes = ['EUR'=>'50','USD'=>'45','CHF'=>'52'];
$calculator = $render('record-currency-calculator', ['nbuTodayError'=>null,'nbuTodayQuotes'=>$quotes]);
$days = ['start'=>Carbon\Carbon::parse('2026-11-01'),'end'=>Carbon\Carbon::parse('2026-11-10')];
$summary = ['totals'=>['income'=>'1000.00','expenses'=>'250.00','net'=>'750.00']];
$profit = $render('record-profit', $days + ['summary'=>$summary,'currency'=>'UAH']);
if (!str_contains($profit,'25,00') || !str_contains($profit,'75,0%')) {throw new RuntimeException('Profit metrics mismatch.');}
if (trim($render('record-profit',$days + ['summary'=>$summary,'currency'=>'UAH','anonymous'=>true])) !== '') {throw new RuntimeException('Anonymous metrics exposed.');}
$summary['totals'] = ['income'=>'0.00','expenses'=>'250.00','net'=>'-250.00'];
if (!str_contains($render('record-profit',$days + ['summary'=>$summary,'currency'=>'UAH']), 'Немає доходів')) {throw new RuntimeException('Zero income requires explicit unavailable ratio.');}
$summary['totals'] = ['income'=>'100.00','expenses'=>'150.00','net'=>'-50.00'];
if (!str_contains($render('record-profit',$days + ['summary'=>$summary,'currency'=>'EUR']), '-50,0%')) {throw new RuntimeException('Negative results must retain their sign.');}
$transactions = $render('record-transactions',$days + ['periods'=>[1],'objectType'=>'withdrawal']);
$all = $render('record-transactions',$days + ['periods'=>[],'objectType'=>'all']);
if (substr_count($transactions,'2026-11-01/2026-11-10')!==4 || substr_count($all,'/all"')<4) {throw new RuntimeException('Transaction type links lost their period or all-time scope.');}
if (!str_contains(file_get_contents('/work/resources/views/accounts/index.blade.php'),'data-record-accounts')) {throw new RuntimeException('Account observer root missing.');}
echo json_encode(['filter'=>$filter,'calculator'=>$calculator,'profit'=>$profit,'transactions'=>$transactions],JSON_THROW_ON_ERROR);
`;
try {
    const output = execFileSync('docker', ['run','--rm','--user','root','--network','none','--entrypoint','php',
        '-e','APP_KEY=base64:MDEyMzQ1Njc4OWFiY2RlZjAxMjM0NTY3ODlhYmNkZWY=',
        '-v',`${root}:/work:ro`,image,'-r',probe],{encoding:'utf8',maxBuffer:6*1024*1024});
    const fragments = JSON.parse(output.slice(output.indexOf('{"filter":')));
    assert.match(fragments.filter,/Пошук на цій сторінці/);
    const setup = `<div id="fixture-rows"><article class="fixture-row">Близько 100 ₴</article><article class="fixture-row">Покупка € 25</article></div>`;
    const checks = String.raw`
(async()=>{
 if(document.readyState==='loading')await new Promise(resolve=>document.addEventListener('DOMContentLoaded',resolve,{once:true}));
 const filter=document.getElementById('record-filter-input-fixture'),clear=document.querySelector('.record-filter-clear'),count=document.getElementById('record-filter-count-fixture');
 const amount=document.getElementById('record-currency-amount'),from=document.getElementById('record-currency-from'),to=document.getElementById('record-currency-to'),out=document.getElementById('record-currency-result');
 const settle=()=>new Promise(resolve=>setTimeout(resolve,45));
 const change=(field,value)=>{field.value=value;field.dispatchEvent(new Event('input',{bubbles:true}));};
 const result={width:innerWidth,errors:[],filter:[],conversions:[]};
 result.filter.push(count.textContent);change(filter,'ПОКУПКА');result.filter.push(count.textContent);
 change(filter,'невідомий');result.filter.push(count.textContent);clear.click();result.filter.push(count.textContent);
 const extra=document.createElement('article');extra.className='fixture-row';extra.textContent='Дохід';document.getElementById('fixture-rows').append(extra);await settle();result.filter.push(count.textContent);
 change(filter,'дохід');result.filter.push(count.textContent);extra.textContent='Зарплата';await settle();result.filter.push(count.textContent);clear.click();
 for(const [value,a,b]of [['100','EUR','UAH'],['100','USD','EUR'],['100','UAH','EUR'],['0,01','UAH','EUR'],['1','USDT','UAH'],['100.125','EUR','EUR'],['999999999999999.99','EUR','UAH'],['-1','EUR','UAH'],['bad','EUR','UAH']]){change(amount,value);change(from,a);change(to,b);result.conversions.push(out.textContent);}
 result.invalid=amount.getAttribute('aria-invalid');result.page=document.documentElement.scrollWidth;result.hidden=Array.from(document.querySelectorAll('.fixture-row')).map(row=>row.hidden);
 document.body.setAttribute('data-check',JSON.stringify(result));
})();`;
    const strip = text => text.replace(/<\/script/gi,'<\\/script');
    for (const width of [1440,390]) {
        const fixture = path.join(temporary,`fixture-${width}.html`);
        // Minimal controls CSS; production Bootstrap layout and full-page checks belong to integration QA.
        const css = 'body{margin:16px;font:16px sans-serif}.d-flex{display:flex}.flex-wrap{flex-wrap:wrap}.gap-2{gap:.5rem}.form-control,.form-select{box-sizing:border-box;max-width:100%;padding:.5rem}.row{display:flex;flex-wrap:wrap;gap:12px}.col-md-4{flex:1 1 220px;min-width:0}.card{padding:12px}.record-filter-empty[hidden]{display:none}.d-block{display:block}';
        fs.writeFileSync(fixture,'<!doctype html><html lang="uk"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>'+css+'</style><body>'+fragments.filter+setup+fragments.calculator+'<script>'+strip(checks)+'</script></body></html>');
        const prefix=path.join(temporary,`result-${width}`);
        execFileSync(process.execPath,[path.join(root,'tests/custom/browser_viewport.cjs'),fixture,String(width),prefix],{stdio:['ignore','pipe','pipe'],timeout:30000});
        const html=fs.readFileSync(prefix+'.html','utf8');
        const result=JSON.parse(html.match(/data-check="([^"]+)"/)[1].replaceAll('&quot;','"').replaceAll('&amp;','&'));
        assert.deepEqual(result.filter,['2 із 2 на цій сторінці','1 із 2 на цій сторінці','0 із 2 на цій сторінці','2 із 2 на цій сторінці','3 із 3 на цій сторінці','1 із 3 на цій сторінці','0 із 3 на цій сторінці']);
        assert.deepEqual(result.conversions.slice(0,7),['5 000,00 ₴','90,00 €','2,00 €','0,00 €','45,00 ₴','100,13 €','49 999 999 999 999 999,50 ₴']);
        assert.ok(result.conversions.slice(7).every(text=>text.startsWith('Введи')));
        assert.equal(result.invalid,'true');assert.deepEqual(result.hidden,[false,false,false]);assert.ok(result.page<=width+1);
    }
    console.log('Passed actual Blade metrics/privacy/type links and desktop/mobile filter rerender, clear/empty states and exact NBU bridge calculator checks.');
} finally {fs.rmSync(temporary,{recursive:true,force:true});}
