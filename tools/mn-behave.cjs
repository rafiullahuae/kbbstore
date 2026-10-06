/*
 * LANE MN — the side panel's behaviour, driven in Chromium at 390:
 * Tab stays inside, Esc / backdrop / swipe close it and focus returns to the
 * burger, Arabic swipes the other way, a long name wraps inside its column,
 * reduced motion fades, reduced transparency goes solid, and frame times while
 * it opens at 4x CPU slowdown with and without the glass. Also the glass over a
 * busy page (the product grid, a product page) for docs/mn-shots/after/.
 *
 *   KBB_BASE=http://127.0.0.1:10760 node tools/mn-behave.cjs
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const BASE = process.env.KBB_BASE || 'http://127.0.0.1:10760';
const EXE = process.env.KBB_CHROME || '/opt/pw-browsers/chromium';
const OUT = path.join(__dirname, '..', 'docs', 'mn-shots', 'after');
fs.mkdirSync(OUT, { recursive: true });

const isOpen = (p) => p.evaluate(() => document.getElementById('mmenu').classList.contains('on'));
const active = (p) => p.evaluate(() => document.activeElement.id || document.activeElement.className || document.activeElement.tagName);
const inMenu = (p) => p.evaluate(() => document.getElementById('mmenu').contains(document.activeElement));

async function page(browser, opts = {}) {
  const ctx = await browser.newContext({ viewport: { width: opts.w || 390, height: 844 }, deviceScaleFactor: 2, reducedMotion: opts.rm || 'no-preference' });
  const p = await ctx.newPage();
  p.errors = [];
  p.on('pageerror', (e) => p.errors.push(e.message));
  await p.goto(BASE + (opts.path || '/'), { waitUntil: 'networkidle' });
  return p;
}

async function swipe(p, from, to) {
  await p.mouse.move(from, 420);
  await p.mouse.down();
  for (let i = 1; i <= 8; i++) { await p.mouse.move(from + (to - from) * i / 8, 424); await p.waitForTimeout(16); }
  await p.mouse.up();
  await p.waitForTimeout(450);
}

(async () => {
  const browser = await chromium.launch({ executablePath: EXE });
  const r = {};

  // Tab cycling, Esc, backdrop.
  let p = await page(browser);
  await p.click('#burger'); await p.waitForTimeout(400);
  r.focusOnOpen = await active(p);
  let escaped = 0;
  for (let i = 0; i < 40; i++) { await p.keyboard.press('Tab'); if (!(await inMenu(p))) escaped++; }
  for (let i = 0; i < 10; i++) { await p.keyboard.press('Shift+Tab'); if (!(await inMenu(p))) escaped++; }
  r.tabEscapes = escaped;
  await p.keyboard.press('Escape'); await p.waitForTimeout(400);
  r.escCloses = !(await isOpen(p)); r.focusAfterEsc = await active(p);
  await p.click('#burger'); await p.waitForTimeout(400);
  await p.mouse.click(360, 600); await p.waitForTimeout(400);
  r.backdropCloses = !(await isOpen(p)); r.focusAfterBackdrop = await active(p);
  r.bodyOverflowAfter = await p.evaluate(() => document.body.style.overflow);

  // Swipe toward the edge closes; a short one springs back; a vertical one scrolls.
  await p.click('#burger'); await p.waitForTimeout(400);
  r.bodyOverflowOpen = await p.evaluate(() => document.body.style.overflow);
  await swipe(p, 200, 170);
  r.shortSwipeStaysOpen = await isOpen(p);
  r.transformAfterShortSwipe = await p.evaluate(() => document.getElementById('mmenu').style.transform);
  await swipe(p, 250, 60);
  r.swipeCloses = !(await isOpen(p));
  r.errorsEn = p.errors;
  await p.close();

  // Arabic: the panel is on the right and a swipe to the right closes it.
  p = await page(browser, { path: '/ar/' });
  await p.click('#burger'); await p.waitForTimeout(400);
  r.arPanel = await p.evaluate(() => { const b = document.getElementById('mmenu').getBoundingClientRect(); return [Math.round(b.left), Math.round(b.right)]; });
  await swipe(p, 120, 330);
  r.arSwipeCloses = !(await isOpen(p));
  r.errorsAr = p.errors;
  await p.close();

  // A long name, at 320: it wraps inside its column and nothing scrolls sideways.
  p = await page(browser, { w: 320 });
  await p.click('#burger'); await p.waitForTimeout(400);
  await p.click('#mmenu .mm-body > .mm-node > .mm-par'); await p.waitForTimeout(200);
  r.longName = await p.evaluate(() => {
    const a = document.querySelector('#mmenu .mm-node.on .mm-si');
    a.textContent = 'Beauty of Joseon Relief Sun Aqua-Fresh Rice B5 SPF50+';
    const b = document.querySelector('#mmenu .mm-node.on .mm-si:nth-child(2)');
    b.textContent = 'Dr.Ceuracle-Vegan-Kombucha-Tea-Essence';
    const body = document.querySelector('#mmenu .mm-body');
    return { h: Math.round(a.getBoundingClientRect().height), w: Math.round(a.getBoundingClientRect().width), hb: Math.round(b.getBoundingClientRect().height),
      bodyScroll: [body.scrollWidth, body.clientWidth], doc: [document.documentElement.scrollWidth, document.documentElement.clientWidth] };
  });
  await p.screenshot({ path: `${OUT}/en-320-5-long-name-wraps.png` });
  await p.close();

  // Reduced motion: no slide, a fade.
  p = await page(browser, { rm: 'reduce' });
  r.reducedMotion = await p.evaluate(() => { const s = getComputedStyle(document.getElementById('mmenu')); return { transform: s.transform, opacity: s.opacity, transition: s.transitionProperty }; });
  await p.close();

  // Reduced transparency: solid white, no blur.
  p = await page(browser);
  const cdp = await p.context().newCDPSession(p);
  await cdp.send('Emulation.setEmulatedMedia', { features: [{ name: 'prefers-reduced-transparency', value: 'reduce' }] });
  r.reducedTransparency = await p.evaluate(() => { const s = getComputedStyle(document.getElementById('mmenu')); return { bg: s.backgroundColor, filter: s.backdropFilter }; });
  await p.close();

  // Frame times while opening, 4x CPU slowdown, glass vs solid.
  const frames = async (solid) => {
    const q = await page(browser);
    const c = await q.context().newCDPSession(q);
    if (solid) await q.addStyleTag({ content: '.mmenu.mm-left{backdrop-filter:none!important;-webkit-backdrop-filter:none!important;background:#fff!important}' });
    await c.send('Emulation.setCPUThrottlingRate', { rate: 4 });
    await q.waitForTimeout(500);
    const out = await q.evaluate(() => new Promise((res) => {
      const t = [];
      const tick = (ts) => { t.push(ts); if (ts - t[0] < 700) requestAnimationFrame(tick); else res(t); };
      document.getElementById('burger').click();
      requestAnimationFrame(tick);
    }));
    await q.close();
    const d = out.slice(1).map((x, i) => x - out[i]);
    return { frames: d.length, avgMs: +(d.reduce((a, b) => a + b, 0) / d.length).toFixed(1), maxMs: +Math.max(...d).toFixed(1), over20: d.filter((x) => x > 20).length };
  };
  r.frames4xGlass = await frames(false);
  r.frames4xSolid = await frames(true);

  // The glass over a busy page: the product grid, and a product page.
  p = await page(browser);
  await p.evaluate(() => window.scrollTo(0, 1300)); await p.waitForTimeout(500);
  await p.click('#burger'); await p.waitForTimeout(500);
  await p.screenshot({ path: `${OUT}/en-390-6-glass-over-grid.png` });
  await p.close();
  const prod = await (await browser.newContext()).newPage();
  await prod.goto(BASE + '/shop/', { waitUntil: 'networkidle' });
  const href = await prod.evaluate(() => (document.querySelector('a[href*="/product/"]') || {}).getAttribute?.('href'));
  await prod.close();
  if (href) {
    p = await page(browser, { path: new URL(href, BASE).pathname });
    await p.click('#burger'); await p.waitForTimeout(500);
    await p.screenshot({ path: `${OUT}/en-390-7-glass-over-product.png` });
    await p.close();
  }

  fs.writeFileSync(`${OUT}/behaviour.json`, JSON.stringify(r, null, 2));
  console.log(JSON.stringify(r, null, 1));
  await browser.close();
})();
