/* Lane LG2: list the CSS animations running on a page (name, target, whether
   Chromium runs it on the compositor is not exposed, so the trace decides). */
const { chromium } = require('playwright');
(async () => {
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const p = await b.newPage({ viewport: { width: 390, height: 844 } });
  await p.goto(process.argv[2] + (process.argv[3] || '/'), { waitUntil: 'load' });
  await p.waitForTimeout(1500);
  console.log(JSON.stringify(await p.evaluate(() => document.getAnimations().map((a) => {
    const t = a.effect && a.effect.target; const pe = a.effect && a.effect.pseudoElement;
    return (a.animationName || a.constructor.name) + ' @ ' + (t ? t.tagName.toLowerCase() + '.' + String(t.className && t.className.baseVal !== undefined ? t.className.baseVal : t.className).split(' ').slice(0, 2).join('.') : '?') + (pe || '');
  }))));
  await b.close();
})();
