/**
 * Lane NV — the desktop menu row, filled edge to edge (owner, 4 October).
 *
 *   KBB_NV_URL=http://127.0.0.1:9892 KBB_NV_OUT=storage/nv-logs/shots KBB_NV_TAG=after-12 \
 *   KBB_NV_WIDTHS=1000,1280,1440,1600,1920 KBB_NV_PATH=/ node tests/browser/nv-nav-fill.mjs
 *
 * For every width: a picture of the header (menu row included) and the numbers
 * that matter — the link font size, the empty space between the last item and
 * the end of the row, how many rows the items occupy, the bar's height and
 * document.documentElement.scrollWidth. Point it at a preview, never at
 * production. KBB_NV_NOJS=1 shows the CSS-only first paint. Measuring here is the harness, in Playwright; nothing in it is
 * served to a shopper.
 */
import { chromium } from 'playwright';
import { mkdirSync, writeFileSync } from 'fs';

const BASE = process.env.KBB_NV_URL || 'http://127.0.0.1:9892';
const OUT = process.env.KBB_NV_OUT || 'storage/nv-logs/shots';
const TAG = process.env.KBB_NV_TAG || 'shot';
const PATH = process.env.KBB_NV_PATH || '/';
const WIDTHS = (process.env.KBB_NV_WIDTHS || '1000,1280,1440,1600,1920').split(',').map(Number);
const CHROME = process.env.KBB_NV_CHROME || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';

mkdirSync(OUT, { recursive: true });
const b = await chromium.launch({ executablePath: CHROME, args: ['--no-sandbox'] });
const rows = [];

for (const width of WIDTHS) {
  const ctx = await b.newContext({ viewport: { width, height: 900 }, javaScriptEnabled: process.env.KBB_NV_NOJS !== '1' });
  const p = await ctx.newPage();
  const errors = [];
  p.on('pageerror', (e) => errors.push(e.message));
  await p.goto(BASE + PATH, { waitUntil: 'networkidle' });
  await p.evaluate(() => document.fonts.ready);
  await p.waitForTimeout(400);

  // With scripts off, page timers do not run, so CLS is only read with them on.
  const cls = process.env.KBB_NV_NOJS === '1' ? null : await p.evaluate(() => new Promise((done) => {
    let sum = 0;
    try {
      new PerformanceObserver((list) => { for (const e of list.getEntries()) if (!e.hadRecentInput) sum += e.value; })
        .observe({ type: 'layout-shift', buffered: true });
    } catch (e) { /* no layout-shift support */ }
    setTimeout(() => done(+sum.toFixed(4)), 50);
  }));

  const m = await p.evaluate(() => {
    const wrap = document.querySelector('.mbar .wrap');
    if (!wrap) return { missing: true };
    const cs = getComputedStyle(wrap);
    const box = wrap.getBoundingClientRect();
    const start = box.left + parseFloat(cs.paddingLeft);
    const end = box.right - parseFloat(cs.paddingRight);
    const items = [...wrap.children].map((el) => el.getBoundingClientRect());
    const rtl = cs.direction === 'rtl';
    const tops = new Set(items.map((r) => Math.round((r.top + r.bottom) / 2)));
    const minL = Math.min(...items.map((r) => r.left));
    const maxR = Math.max(...items.map((r) => r.right));
    const links = [...wrap.querySelectorAll(':scope > .navitem > .navlink')];
    const pill = links.find((a) => a.getAttribute('style'));
    const ind = wrap.querySelector('.navlink .ind');
    return {
      items: items.length,
      rtl,
      font: parseFloat(getComputedStyle(links[0]).fontSize).toFixed(2),
      padX: parseFloat(getComputedStyle(links[1] || links[0]).paddingLeft).toFixed(2),
      pillPadX: pill ? parseFloat(getComputedStyle(pill).paddingLeft).toFixed(2) : null,
      indFont: ind ? parseFloat(getComputedStyle(ind).fontSize).toFixed(2) : null,
      emptyEnd: +(rtl ? minL - start : end - maxR).toFixed(1),
      emptyStart: +(rtl ? end - maxR : minL - start).toFixed(1),
      rowsUsed: tops.size,
      barHeight: +document.querySelector('.mbar').getBoundingClientRect().height.toFixed(1),
      scale: getComputedStyle(document.querySelector('.mbar')).getPropertyValue('--nav-scale').trim(),
      scrollWidth: document.documentElement.scrollWidth,
      clientWidth: document.documentElement.clientWidth,
      mbarTop: document.querySelector('.mbar').getBoundingClientRect().top,
    };
  });

  const file = `${OUT}/${TAG}-${width}.png`;
  if (!m.missing) {
    await p.screenshot({ path: file, clip: { x: 0, y: 0, width, height: Math.ceil(m.mbarTop + m.barHeight + 4) } });
  }
  rows.push({ width, file, errors, cls, ...m });
  await ctx.close();
}

await b.close();
writeFileSync(`${OUT}/${TAG}.json`, JSON.stringify(rows, null, 2));
console.table(rows.map(({ file, errors, mbarTop, clientWidth, padX, pillPadX, ...r }) => ({ ...r, errors: errors.length })));
