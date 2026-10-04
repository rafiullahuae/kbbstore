// Lane PX: the sold-out card on every grid skin, one sheet, at 390.
// node tools/px-skins.cjs [port]   ->  docs/lane-px-shots/after-soldout-skins-390.png
// The skin is a `data-skin` attribute and CSS only (kbb.css carries all of
// them), so each tile is the same /shop page with the attribute switched.
const { chromium } = require('playwright');
const path = require('path');
const CHROME = '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const BASE = `http://127.0.0.1:${process.argv[2] || 10040}`;
const OUT = path.join(__dirname, '..', 'docs', 'lane-px-shots');
const SKINS = 'classic overlay spotlight editorial minimal horizontal soft bold glass split round polaroid stacked petal glow luxe pastel rosegold actions ribbon frame duotone magazine fab outline pricetag reveal accentbar showcase showcase-compact showcase-row showcase-airy'.split(' ');

(async () => {
  const browser = await chromium.launch({ executablePath: CHROME });
  const page = await browser.newPage({ viewport: { width: 390, height: 844 } });
  await page.goto(BASE + '/shop/?s=Ampoule', { waitUntil: 'networkidle' });
  // The chat bubble and the welcome toast are fixed to the viewport and sit on
  // whichever card is under them; they are not what this sheet is about.
  await page.evaluate(() => document.querySelectorAll('body *').forEach((el) => { if (getComputedStyle(el).position === 'fixed') el.style.display = 'none'; }));
  const shots = [];
  for (const k of SKINS) {
    await page.evaluate((k) => { document.querySelector('.kbb-pgrid').dataset.skin = k; }, k);
    await page.waitForTimeout(60);
    const card = (await page.$$('.kbb-tile'))[1];
    await card.scrollIntoViewIfNeeded();
    const buf = await card.screenshot();
    shots.push([k, buf.toString('base64')]);
  }
  const sheet = await browser.newPage({ viewport: { width: 1400, height: 800 } });
  await sheet.setContent('<body style="margin:0;font:12px sans-serif;background:#f4f1f2"><div style="display:grid;grid-template-columns:repeat(8,1fr);gap:8px;padding:8px">'
    + shots.map(([k, b]) => `<figure style="margin:0;background:#fff;padding:4px"><figcaption>${k}</figcaption><img style="width:100%" src="data:image/png;base64,${b}"></figure>`).join('')
    + '</div></body>');
  await sheet.screenshot({ path: path.join(OUT, 'after-soldout-skins-390.png'), fullPage: true });
  await browser.close();
  console.log('skins sheet written');
})();
