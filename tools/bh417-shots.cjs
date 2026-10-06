/*
 * Lane BH: the Journal and an article against a normal shop page, at 390 and
 * 1280, with the numbers that say whether the header is the same one.
 *
 *   BH_BASE=http://127.0.0.1:<port> node tools/bh417-shots.cjs <before|after>
 *
 * Per page it records the main header's height, whether the shared header's
 * landmarks are present (.kbb-header search, desktop nav bar, phone menu
 * button), scrollWidth against innerWidth, and screenshots the top of the
 * page plus the whole page. The phone menu is opened and shot too, at 390.
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');
const CHROME = '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const BASE = process.env.BH_BASE || 'http://127.0.0.1:10870';
const OUT = process.env.BH_OUT || path.resolve(__dirname, '..', 'docs', 'lane-bh-shots');
const LABEL = process.argv[2] || 'after';
const PAGES = [
  ['blog', '/blog/'],
  ['article', '/blog/the-double-cleanse-explained/'],
  ['category', '/collections/cleansers/'],
];

(async () => {
  fs.mkdirSync(OUT, { recursive: true });
  const browser = await chromium.launch({ executablePath: CHROME });
  const rows = [];
  for (const [w, h] of [[390, 844], [1280, 900]]) {
    const ctx = await browser.newContext({ viewport: { width: w, height: h } });
    const page = await ctx.newPage();
    for (const [name, url] of PAGES) {
      const res = await page.goto(BASE + url, { waitUntil: 'networkidle' });
      await page.waitForTimeout(300);
      const m = await page.evaluate(() => {
        const hd = document.querySelector('body > header, header.hd, header.head, header');
        const r = hd ? hd.getBoundingClientRect() : null;
        const vis = (sel) => { const e = document.querySelector(sel); if (!e) return false; const s = getComputedStyle(e); return s.display !== 'none' && s.visibility !== 'hidden' && e.getBoundingClientRect().height > 0; };
        const h1 = document.querySelector('main h1, h1');
        return {
          header: hd ? hd.className || hd.tagName : null,
          headerH: r ? Math.round(r.height) : null,
          headers: document.querySelectorAll('header').length,
          footers: document.querySelectorAll('footer').length,
          searchVisible: vis('form[role="search"], .search, input[type="search"]'),
          h1: h1 ? h1.textContent.trim().slice(0, 50) : null,
          h1Size: h1 ? getComputedStyle(h1).fontSize : null,
          scrollWidth: document.documentElement.scrollWidth,
          innerWidth: window.innerWidth,
          title: document.title,
        };
      });
      const stem = `${LABEL}-${name}-${w}`;
      await page.screenshot({ path: `${OUT}/${stem}.png` });
      await page.screenshot({ path: `${OUT}/${stem}-full.png`, fullPage: true });
      // The phone menu, opened, on the two Journal pages: the sheet every
      // other page opens, not the four-link drawer these two used to have.
      let menuOpen = null;
      if (w === 390) {
        const opener = await page.$('#burger');
        if (opener) {
          await opener.click();
          await page.waitForTimeout(450);
          menuOpen = await page.evaluate(() => { const n = document.getElementById('mmenu'); return !!n && n.classList.contains('on'); });
          await page.screenshot({ path: `${OUT}/${stem}-menu.png` });
        }
      }
      rows.push({ shot: stem, status: res.status(), menuOpen, ...m });
      console.log(JSON.stringify(rows[rows.length - 1]));
    }
    await ctx.close();
  }
  fs.writeFileSync(`${OUT}/${LABEL}-measurements.json`, JSON.stringify(rows, null, 1) + '\n');
  await browser.close();
})().catch((e) => { console.error(e); process.exit(1); });
