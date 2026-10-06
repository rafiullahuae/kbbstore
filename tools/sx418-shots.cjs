// Lane SX shots: the moment the pointer slides from "Brands" onto "Skincare" (80% down the link).
const path = require('path'); const fs = require('fs');
const APP = path.dirname(__dirname);
const { chromium } = require(path.join(APP, 'node_modules', 'playwright'));
const base = (l) => 'http://127.0.0.1:' + fs.readFileSync(path.join(APP, 'storage/framework/testing/lane-spd-' + l, 'port'), 'utf8').trim();
const OUT = path.join(APP, 'docs/lane-sx-shots');
(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
  for (const [l, tag] of [['sxgood', '0-2.60.415'], ['sxhead', '1-before-HEAD'], ['sxfix', '2-after-fix']]) {
    const page = await browser.newPage({ viewport: { width: 1280, height: 800 } });
    await page.goto(base(l) + '/brands/anua/', { waitUntil: 'load' });
    await page.waitForTimeout(500);
    const links = page.locator('.mbar .wrap > .navitem > .navlink');
    const a = await links.nth(0).boundingBox(); const b = await links.nth(1).boundingBox();
    await page.mouse.move(a.x + a.width / 2, a.y + a.height * 0.8); await page.waitForTimeout(300);
    const x = b.x + b.width / 2; const y = b.y + b.height * 0.8;
    await page.mouse.move(x, y, { steps: 10 }); await page.waitForTimeout(400);
    const info = await page.evaluate(([px, py]) => {
      const e = document.elementFromPoint(px, py);
      const d = document.createElement('div');
      d.style.cssText = `position:fixed;left:${px - 7}px;top:${py - 7}px;width:14px;height:14px;border-radius:50%;background:#e0245e;border:2px solid #fff;box-shadow:0 0 0 2px #e0245e;z-index:99999;pointer-events:none`;
      document.body.appendChild(d);
      const open = [...document.querySelectorAll('.mbar .navitem')].filter((n) => n.matches(':hover')).map((n) => n.querySelector('.navlink').textContent.trim().split(/\s/)[0]);
      return { under: e.tagName + '.' + String(e.className).split(' ')[0], hovered: open[0] };
    }, [x, y]);
    await page.screenshot({ path: path.join(OUT, `1280-${tag}-slide-to-skincare.png`), clip: { x: 0, y: 0, width: 1280, height: 520 } });
    await page.mouse.down(); await page.waitForTimeout(60); await page.mouse.up(); await page.waitForTimeout(1500);
    console.log(l, JSON.stringify(info), 'after click at', new URL(page.url()).pathname);
    await page.close();
  }
  for (const [l, tag] of [['sxhead', 'before-HEAD'], ['sxfix', 'after-fix']]) {
    const page = await browser.newPage({ viewport: { width: 390, height: 844 }, deviceScaleFactor: 2, isMobile: true, hasTouch: true });
    await page.goto(base(l) + '/brands/anua/', { waitUntil: 'load' }); await page.waitForTimeout(800);
    const m = await page.evaluate(() => ({ sw: document.documentElement.scrollWidth, mbar: getComputedStyle(document.querySelector('.mbar')).display }));
    await page.screenshot({ path: path.join(OUT, `390-${tag}-brand.png`) });
    console.log('390', l, JSON.stringify(m));
    await page.close();
  }
  await browser.close();
})();
