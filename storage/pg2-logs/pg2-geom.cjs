const { chromium } = require('playwright');
(async () => {
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome' });
  const p = await b.newPage({ viewport: { width: 1280, height: 900 } });
  await p.goto('http://127.0.0.1:8931/collections/skincare-sets/', { waitUntil: 'networkidle' });
  console.log(JSON.stringify(await p.evaluate(() => {
    const t = document.querySelector('.kbb-tile');
    const r = (e) => { if (!e) return null; const b = e.getBoundingClientRect(); return [Math.round(b.x), Math.round(b.y), Math.round(b.width), Math.round(b.height)]; };
    return {
      card: r(t), shot: r(t.querySelector('.kbb-card-shot')),
      sale: r(t.querySelector('.kbb-badge-sale')), heart: r(t.querySelector('.heart')),
      cart: r(t.querySelector('.kbb-card-cart')), cp: r(t.querySelector('.cp')),
      cb: r(t.querySelector('.cb')), img: r(t.querySelector('.kbb-card-img')),
    };
  }), null, 1));
  await b.close();
})();
