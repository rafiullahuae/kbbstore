const { chromium } = require('playwright');
(async()=>{const b=await chromium.launch();const p=await b.newPage({viewport:{width:1925,height:1100}});
await p.goto('file://'+__dirname+'/banners.html');await p.evaluate(()=>document.fonts.ready);
await p.locator('#d').screenshot({path:'ar-desktop.png'});await p.locator('#m').screenshot({path:'ar-phone.png'});await b.close();})();
