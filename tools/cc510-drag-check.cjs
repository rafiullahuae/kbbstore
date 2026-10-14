const { chromium } = require('playwright');
(async()=>{const b=await chromium.launch({executablePath:'/opt/pw-browsers/chromium'});
const p=await b.newPage({viewport:{width:1280,height:900}});const errs=[];p.on('pageerror',e=>errs.push(String(e)));
await p.goto('http://127.0.0.1:9931/',{waitUntil:'networkidle'});
const sec=await p.$('.hs-concerns');await sec.scrollIntoViewIfNeeded();await p.waitForTimeout(300);
const sl=()=>p.evaluate(()=>Math.round(document.querySelector('.hs-cc-list').scrollLeft));
const box=await (await p.$('.hs-cc-list')).boundingBox();const y=box.y+box.height/2;
// slow drag 150px left: row moves ~150, no glide
await p.mouse.move(box.x+600,y);await p.mouse.down();for(let i=1;i<=15;i++){await p.mouse.move(box.x+600-i*10,y);await p.waitForTimeout(30);}await p.waitForTimeout(150);await p.mouse.up();
const a=await sl();await p.waitForTimeout(1200);const a2=await sl();
const urlAfterDrag=p.url();
// quick flick 120px: glides further, then stops
await p.mouse.move(box.x+700,y);await p.mouse.down();for(let i=1;i<=6;i++){await p.mouse.move(box.x+700-i*20,y);await p.waitForTimeout(8);}await p.mouse.up();
const f0=await sl();await p.waitForTimeout(300);const f1=await sl();await p.waitForTimeout(1500);const f2=await sl();await p.waitForTimeout(500);const f3=await sl();
console.log(JSON.stringify({slowDragEnd:a,after1s:a2,stayedOnPage:urlAfterDrag.endsWith(':9931/'),flickRelease:f0,flick300ms:f1,flick1800ms:f2,flick2300ms:f3,snapType:await p.evaluate(()=>getComputedStyle(document.querySelector('.hs-cc-list')).scrollSnapType)}),errs);
// a plain click still opens the chip
const chip=await p.$('.hs-cc-list li:nth-child(3) a');const href=await chip.getAttribute('href');
await Promise.all([p.waitForNavigation({waitUntil:'domcontentloaded'}),chip.click()]);console.log('click',href,'->',new URL(p.url()).pathname);
// phone: free swipe, no snap
const m=await b.newPage({viewport:{width:390,height:844},isMobile:true,hasTouch:true});await m.goto('http://127.0.0.1:9931/',{waitUntil:'networkidle'});
console.log('phone snap',await m.evaluate(()=>getComputedStyle(document.querySelector('.hs-cc-list')).scrollSnapType), 'drag attr', await m.evaluate(()=>document.querySelector('.hs-cc-list').hasAttribute('data-ymal-drag')));
await b.close();})();
