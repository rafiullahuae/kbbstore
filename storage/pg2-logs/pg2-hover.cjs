const { chromium } = require('playwright');
(async () => {
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const p = await b.newPage({ viewport: { width: 1280, height: 900 } });
  await p.goto('http://127.0.0.1:8931/collections/skincare-sets/', { waitUntil: 'networkidle' });
  const tile = await p.$('.kbb-tile');
  await tile.hover();
  await p.waitForTimeout(600);
  const geo = await p.evaluate(() => {
    const t = document.querySelector('.kbb-tile');
    const q = t.querySelector('.qv-btn');
    const s = t.querySelector('.kbb-card-shot');
    const r = (e) => { const b = e.getBoundingClientRect(); return [Math.round(b.x), Math.round(b.y), Math.round(b.width), Math.round(b.height)]; };
    return { qv: q ? r(q) : null, shot: r(s), card: r(t), qvOpacity: q ? getComputedStyle(q).opacity : null };
  });
  console.log(JSON.stringify(geo));
  await p.screenshot({ path: '/home/user/lane-pg2/docs/pg2-shots/hover-quickview-1280.png', clip: { x: 10, y: 250, width: 500, height: 480 } });
  await b.close();
})();
