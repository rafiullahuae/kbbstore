/* Lane PW: measure whether element full screen survives a page navigation on the shop (it does not). Needs tools/pwa-shop-preview.sh running on :8731. */
const { chromium } = require('playwright');
(async () => {
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
  const p = await (await b.newContext({ viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true })).newPage();
  await p.goto('http://127.0.0.1:8731/', { waitUntil: 'domcontentloaded' });
  await p.evaluate(() => { const x = document.createElement('button'); x.id = 'fsx'; x.textContent = 'fs'; x.style.cssText = 'position:fixed;top:0;left:0;z-index:99999;width:80px;height:80px'; x.onclick = () => document.documentElement.requestFullscreen(); document.body.appendChild(x); });
  await p.click('#fsx'); await p.waitForTimeout(400);
  console.log('before nav fullscreen:', await p.evaluate(() => !!document.fullscreenElement), 'innerHeight', await p.evaluate(() => innerHeight));
  await p.goto('http://127.0.0.1:8731/shop/', { waitUntil: 'domcontentloaded' }); await p.waitForTimeout(400);
  console.log('after nav fullscreen:', await p.evaluate(() => !!document.fullscreenElement));
  await b.close();
})();
