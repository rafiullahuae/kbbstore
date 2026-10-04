/**
 * Lane NV — the fitted menu row under resizing and hovering.
 *
 *   KBB_NV_URL=http://127.0.0.1:9892 node tests/browser/nv-nav-resize.mjs
 *
 * Walks one page through 1920 → 1100 → 1500 → 1920, reading the font size and
 * the empty end of the row at each stop, then reads the same width twice more
 * to show the answer does not drift (no jitter). Then hovers the last two items
 * at 1920 and 1280 and reports where their panels land and the page's
 * scrollWidth while they are open. Harness only; never served.
 */
import { chromium } from 'playwright';

const BASE = process.env.KBB_NV_URL || 'http://127.0.0.1:9892';
const PATH = process.env.KBB_NV_PATH || '/';
const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome', args: ['--no-sandbox'] });
const ctx = await b.newContext({ viewport: { width: 1920, height: 900 } });
const p = await ctx.newPage();
const cdp = await ctx.newCDPSession(p);
await p.goto(BASE + PATH, { waitUntil: 'networkidle' });
await p.evaluate(() => document.fonts.ready);

const read = () => p.evaluate(() => {
  const wrap = document.querySelector('.mbar .wrap');
  const cs = getComputedStyle(wrap);
  const box = wrap.getBoundingClientRect();
  const items = [...wrap.children].map((el) => el.getBoundingClientRect());
  const rtl = cs.direction === 'rtl';
  const end = rtl ? Math.min(...items.map((r) => r.left)) - (box.left + parseFloat(cs.paddingLeft))
    : (box.right - parseFloat(cs.paddingRight)) - Math.max(...items.map((r) => r.right));
  return {
    inner: innerWidth,
    font: getComputedStyle(wrap.querySelector('.navlink')).fontSize,
    scale: getComputedStyle(document.querySelector('.mbar')).getPropertyValue('--nav-scale').trim(),
    emptyEnd: +end.toFixed(1),
    rows: new Set(items.map((r) => Math.round((r.top + r.bottom) / 2))).size,
    scrollWidth: document.documentElement.scrollWidth,
  };
});

const size = async (w) => {
  await cdp.send('Emulation.setDeviceMetricsOverride', { width: w, height: 900, deviceScaleFactor: 1, mobile: false });
  await p.evaluate(() => window.dispatchEvent(new Event('resize')));
  await p.waitForTimeout(250);
};

const out = [];
for (const w of [1920, 1100, 1500, 1920, 1920, 1920, 1280]) { await size(w); out.push(await read()); }
console.table(out);

const drops = [];
for (const w of [1920, 1280]) {
  // A fresh page per width: a screenshot drops a CDP metrics override.
  const hp = await (await b.newContext({ viewport: { width: w, height: 900 } })).newPage();
  await hp.goto(BASE + PATH, { waitUntil: 'networkidle' });
  await hp.evaluate(() => document.fonts.ready);
  await hp.waitForTimeout(300);
  for (const nth of [2, 1]) {
    const item = hp.locator(`.mbar .wrap > .navitem:nth-last-child(${nth})`);
    await item.hover();
    await hp.waitForTimeout(300);
    drops.push(await item.evaluate((el, w) => {
      const d = el.querySelector('.drop');
      const r = d.getBoundingClientRect();
      const mbar = el.closest('.mbar').getBoundingClientRect();
      return { width: w, item: el.querySelector('.navlink').textContent.trim().replace(/\s+/g, ' '),
        dropLeft: Math.round(r.left), dropRight: Math.round(r.right), barRight: Math.round(mbar.right),
        visible: getComputedStyle(d).visibility, wholeOnScreen: r.left >= 0 && r.right <= innerWidth,
        scrollWidth: document.documentElement.scrollWidth };
    }, w));
    if (process.env.KBB_NV_OUT) await hp.screenshot({ path: `${process.env.KBB_NV_OUT}/after-12-hover-${nth === 1 ? 'last' : 'second-last'}-${w}${PATH === '/' ? '' : '-ar'}.png`, clip: { x: 0, y: 0, width: w, height: 560 } });
  }
}
console.table(drops);
await b.close();
