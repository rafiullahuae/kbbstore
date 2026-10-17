// Lane HT -- screenshots + checks of the Product tabs previews.
//   NODE_PATH=$(npm root -g) node tools/ht-shots.cjs <port>
// Writes docs/lane-ht/previews/<letter>-<width>[-ar][-state].png and prints a
// JSON line per shot: scrollWidth, console errors, requests made by a tab
// click (must be images only), tabs answering a real click, arrow-key switch.
const { chromium } = require('playwright');
const path = require('path');
const port = process.argv[2] || '9751';
const out = path.join(__dirname, '..', 'docs', 'lane-ht', 'previews');
const base = `http://127.0.0.1:${port}`;

const jobs = [];
for (const v of ['a', 'b', 'c', 'd', 'e']) for (const w of [1280, 390]) jobs.push({ v, w, ar: false });
for (const v of ['a', 'b', 'd']) jobs.push({ v, w: 390, ar: true });
jobs.push({ v: 'c', w: 1280, ar: true });

async function shotSection(page, file) {
  await page.evaluate(() => document.querySelector('.htp').scrollIntoView({ block: 'start' }));
  await page.evaluate(() => window.scrollBy(0, -60));
  await page.waitForTimeout(250);
  await page.evaluate(async () => {
    const imgs = [...document.querySelectorAll('.htp-panel:not([hidden]) img')];
    await Promise.all(imgs.map((i) => (i.complete ? 0 : new Promise((r) => { i.onload = i.onerror = r; setTimeout(r, 3000); }))));
  });
  const box = await page.evaluate(() => {
    const a = document.querySelector('.htp-badge').getBoundingClientRect();
    const b = document.querySelector('.htp').getBoundingClientRect();
    return { y: a.top + window.scrollY - 8, h: b.bottom - a.top + 16 };
  });
  await page.screenshot({ path: path.join(out, file), clip: { x: 0, y: box.y, width: page.viewportSize().width, height: Math.min(box.h, 2400) }, fullPage: true });
}

(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
  const results = [];
  for (const j of jobs) {
    const ctx = await browser.newContext({ viewport: { width: j.w, height: j.w > 900 ? 900 : 844 }, deviceScaleFactor: j.w > 900 ? 1 : 2, isMobile: j.w <= 900, hasTouch: j.w <= 900 });
    const page = await ctx.newPage();
    const errors = [];
    page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
    page.on('pageerror', (e) => errors.push(String(e)));
    page.on('response', (r) => { if (r.status() >= 400) errors.push(r.status() + ' ' + r.url()); });
    const url = `${base}${j.ar ? '/ar' : ''}/ht-preview/${j.v}`;
    await page.goto(url, { waitUntil: 'networkidle' });
    // The floating WhatsApp bubble is the shop's, not this section's; hidden in
    // the PICTURE only so it does not sit over the cards.
    await page.addStyleTag({ content: '.kbw{display:none!important}' });
    const tag = `${j.v}-${j.w}${j.ar ? '-ar' : ''}`;
    await shotSection(page, `${tag}.png`);
    const sw = await page.evaluate(() => document.documentElement.scrollWidth);

    // Every tab answers a real click at its own centre.
    const hits = await page.evaluate(() => [...document.querySelectorAll('.htp [role=tab]')].map((t) => {
      const r = t.getBoundingClientRect();
      if (r.right < 0 || r.left > innerWidth) return 'offscreen';
      const el = document.elementFromPoint(Math.min(innerWidth - 2, Math.max(1, r.left + r.width / 2)), r.top + r.height / 2);
      return el === t || t.contains(el);
    }));

    // Click the second tab: what goes over the network?
    const reqs = [];
    const onReq = (r) => reqs.push(r.resourceType() + ' ' + r.url().replace(base, ''));
    page.on('request', onReq);
    await page.locator('.htp [role=tab]').nth(1).click();
    await page.waitForTimeout(600);
    page.off('request', onReq);
    const state = await page.evaluate(() => ({
      selected: [...document.querySelectorAll('.htp [role=tab]')].map((t) => t.getAttribute('aria-selected')).join(','),
      open: [...document.querySelectorAll('.htp [role=tabpanel]')].map((p) => (p.hidden ? 0 : 1)).join(''),
    }));
    await shotSection(page, `${tag}-tab2.png`);

    // Arrow key moves to the next tab (mirrored in RTL).
    await page.locator('.htp [role=tab]').nth(1).focus();
    await page.keyboard.press(j.ar ? 'ArrowLeft' : 'ArrowRight');
    const keyed = await page.evaluate(() => document.activeElement && document.activeElement.textContent);

    // Carousel in motion: press next (laptop) or swipe the track (phone).
    let moved = null;
    const isCar = await page.evaluate(() => !!document.querySelector('.htp-panel:not([hidden]) .gs-car-' + (innerWidth > 900 ? 'd' : 'm')));
    if (isCar) {
      if (j.w > 900) {
        await page.locator('.htp-panel:not([hidden]) .gs-next').click();
      } else {
        await page.evaluate(() => document.querySelector('.htp-panel:not([hidden]) [class*=gs-car]').scrollBy({ left: (getComputedStyle(document.documentElement).direction === 'rtl' ? -1 : 1) * 260, behavior: 'smooth' }));
      }
      await page.waitForTimeout(900);
      moved = await page.evaluate(() => document.querySelector('.htp-panel:not([hidden]) [class*=gs-car]').scrollLeft);
      await shotSection(page, `${tag}-moved.png`);
    }
    results.push({ shot: tag, url, scrollWidth: sw, width: j.w, ok: sw === j.w, errors, tabHits: hits, tab2Requests: reqs, state, keyedTo: keyed, carouselScrollLeft: moved });
    await ctx.close();
  }
  await browser.close();
  for (const r of results) console.log(JSON.stringify(r));
})();
