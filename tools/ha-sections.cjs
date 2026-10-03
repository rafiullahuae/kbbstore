// Lane HA: one picture per new homepage section, at 1280 and 390.
const { chromium } = require('playwright');
(async () => {
  const base = process.argv[2] || 'http://127.0.0.1:9871';
  const out = process.argv[3] || 'docs/ha-shots';
  const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
  for (const w of [1280, 390]) {
    const p = await b.newPage({ viewport: { width: w, height: w > 500 ? 900 : 844 }, deviceScaleFactor: w > 500 ? 1 : 2 });
    await p.goto(base + '/', { waitUntil: 'networkidle' });
    await p.evaluate(async () => { for (let y = 0; y < document.body.scrollHeight; y += 500) { window.scrollTo(0, y); await new Promise(r => setTimeout(r, 80)); } });
    await p.waitForTimeout(500);
    for (const key of ['bestselling', 'brands', 'trending', 'blog', 'under54', 'feature', 'about']) {
      const el = await p.$('section.hs-' + key);
      if (el) await el.screenshot({ path: `${out}/section-${key}-${w}.png` });
    }
    await p.close();
  }
  await b.close();
})();
