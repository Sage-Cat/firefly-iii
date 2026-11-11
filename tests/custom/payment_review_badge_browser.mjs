import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import {execFileSync} from 'node:child_process';
import {fileURLToPath} from 'node:url';
const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'../..');
const image=process.argv[2]||'firefly-iii-local:6.7.9';
const output=execFileSync('docker',['run','--rm','--user','root','--network','none','--entrypoint','php','-v',`${root}:/work:ro`,'-e','FIREFLY_TEST_APP_ROOT=/var/www/html',image,'/work/tests/custom/payment_review_badge.php','--json'],{encoding:'utf8',maxBuffer:12*1024*1024});
const match=output.match(/^REVIEW_BADGE_FIXTURE=(.*)$/m);assert.ok(match,'Actual Blade badge fixtures must render successfully.');
const fixtures=JSON.parse(match[1]);
const temporary=fs.mkdtempSync(path.join(os.tmpdir(),'review-menu-badge-'));
try{
 for(const [width,collapsed,kind]of [[1440,false,'zero'],[1440,false,'one'],[1440,false,'many'],[1440,false,'active'],[1440,false,'anonymous'],[1440,true,'many'],[390,false,'many'],[390,false,'zero']]){
  const bodyClass=`layout-fixed sidebar-expand-lg${collapsed?' sidebar-mini sidebar-collapse':''}${width<992?' sidebar-open':''}`;
  const check=String.raw`window.addEventListener('load',()=>{const link=document.querySelector('.payment-review-nav-link'),badge=document.querySelector('.payment-review-count'),aside=document.querySelector('.app-sidebar');link.focus();const rect=node=>{const r=node.getBoundingClientRect();return {x:r.x,y:r.y,right:r.right,bottom:r.bottom,width:r.width,height:r.height}};let badgeText=null;if(badge){const range=document.createRange();range.selectNodeContents(badge);badgeText=rect(range);}document.body.setAttribute('data-check',JSON.stringify({width:innerWidth,page:document.documentElement.scrollWidth,link:rect(link),aside:rect(aside),badge:badge?rect(badge):null,badgeText,text:badge?.textContent.trim()??null,name:link.getAttribute('aria-label'),focus:document.activeElement===link,active:link.classList.contains('active'),attention:link.classList.contains('payment-review-attention'),color:badge?getComputedStyle(badge).backgroundColor:null,animation:getComputedStyle(link).animationName}));});`;
  const html='<!doctype html><html lang="uk" data-bs-theme="dark"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta http-equiv="Content-Security-Policy" content="default-src \'none\';style-src \'unsafe-inline\';script-src \'unsafe-inline\';font-src data:;img-src data:"><style>'+fixtures.css+'</style></head><body class="'+bodyClass+'"><div class="app-wrapper"><aside class="app-sidebar bg-body-secondary" data-bs-theme="dark"><div class="sidebar-wrapper"><nav><ul class="nav sidebar-menu flex-column">'+fixtures[kind]+'</ul></nav></div></aside><main class="app-main"><div class="app-content p-3">Приклад сторінки</div></main></div><script>'+check+'</script></body></html>';
  const prefix=path.join(temporary,`${width}-${collapsed}-${kind}`);const fixture=prefix+'-fixture.html';fs.writeFileSync(fixture,html);
  execFileSync(process.execPath,[path.join(root,'tests/custom/browser_viewport.cjs'),fixture,String(width),prefix],{stdio:['ignore','pipe','pipe'],timeout:30000});
  const result=JSON.parse(fs.readFileSync(prefix+'.html','utf8').match(/data-check="([^"]+)"/)[1].replaceAll('&quot;','"').replaceAll('&amp;','&'));
  assert.equal(result.width,width);assert.ok(result.page<=width+1,'The badge must not overflow the viewport.');assert.ok(result.focus,'The link remains keyboard-focusable.');assert.equal(result.animation,'none');
  if(kind==='zero'||kind==='anonymous'){assert.equal(result.badge,null);assert.equal(result.attention,false);assert.equal(result.name,'Уточнення платежів');}
  else{
   assert.equal(result.text,kind==='one'?'1':'1000');assert.ok(result.name.endsWith('у черзі '+result.text));assert.equal(result.attention,true);assert.equal(result.color,'rgb(255, 182, 92)');
   assert.ok(result.badge.width>0&&result.badge.height>0&&result.badge.x>=result.aside.x&&result.badge.right<=result.aside.right+1,'The orange badge stays inside normal/mobile/collapsed navigation: '+JSON.stringify(result));
   assert.ok(result.badgeText.width<=result.badge.width&&result.badgeText.right<=result.badge.right+1,'The full count remains readable.');
  }
  assert.equal(result.active,kind==='active');
 }
 console.log('Passed actual Blade/native CSS menu badges on 390/1440px: zero, one, 1000, anonymous, active, collapsed, keyboard focus and bounded placement.');
}finally{fs.rmSync(temporary,{recursive:true,force:true});}
