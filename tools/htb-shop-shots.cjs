// Lane HTB -- the published Product tabs section on the homepage, English and
// Arabic, at 390 and 1280: picture, then the checks CLAUDE.md asks of every
// front-end change. Usage: node tools/htb-shop-shots.cjs <base> <out-dir> <label>
//   - switching a tab makes no fetch/XHR (only the newly shown lazy images)
//   - ArrowRight (ArrowLeft on /ar) moves to the next tab and focuses it
//   - every visible tab and every card link in the open panel answers
//     elementFromPoint at its centre; a card click navigates
//   - scrollWidth == width; console errors; CLS
const { chromium } = require(process.env.NODE_PATH ? 'playwright' : '/opt/node22/lib/node_modules/playwright');

const BASE = process.argv[2];
const OUT = process.argv[3];
const LABEL = process.argv[4] || 'x';

// The section, clear of the sticky header: its top 150px below the top of a
// tall viewport, then a clip of its box -- an element screenshot would scroll
// it under the header and photograph the header instead.
async function shot(page, sec, path) {
  await sec.evaluate((e) => window.scrollTo(0, e.getBoundingClientRect().top + window.scrollY - 150));
  await page.waitForTimeout(400);
  const b = await sec.boundingBox();
  await page.screenshot({ path, clip: { x: 0, y: Math.max(0, b.y - 10), width: page.viewportSize().width, height: Math.min(b.height + 20, page.viewportSize().height - Math.max(0, b.y - 10)) } });
}

(async () => {
  const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
  const out = [];
  for (const lang of ['en', 'ar']) {
    for (const w of [390, 1280]) {
      const ctx = await browser.newContext({ viewport: { width: w, height: 1500 }, deviceScaleFactor: 1 });
      const page = await ctx.newPage();
      const errors = [];
      const bad = [];
      page.on('pageerror', (e) => errors.push(String(e)));
      page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
      page.on('response', (r) => { if (r.status() >= 400) bad.push(r.status() + ' ' + r.url()); });
      await page.addInitScript(() => {
        window.__cls = 0;
        try {
          new PerformanceObserver((l) => { for (const e of l.getEntries()) if (!e.hadRecentInput) window.__cls += e.value; })
            .observe({ type: 'layout-shift', buffered: true });
        } catch (e) {}
      });
      await page.goto(BASE + (lang === 'ar' ? '/ar/' : '/'), { waitUntil: 'networkidle' });
      const sec = page.locator('section.gs-tabs').first();
      await sec.scrollIntoViewIfNeeded();
      await page.waitForTimeout(700);
      const name = OUT + '/' + LABEL + '-' + w + '-' + lang;
      await shot(page, sec, name + '.png');

      const tabs = sec.locator('[role=tab]');
      const n = await tabs.count();
      // The row in the middle of the screen, clear of the sticky header.
      await sec.locator('[role=tablist]').evaluate((e) => e.scrollIntoView({ block: 'center' }));
      await page.waitForTimeout(300);

      // Every visible tab answers elementFromPoint at its centre.
      const tabHits = await sec.evaluate((s) => Array.from(s.querySelectorAll('[role=tab]')).map((t) => {
        const r = t.getBoundingClientRect();
        if (r.right <= 0 || r.left >= innerWidth) return 'offscreen';
        const x = Math.min(Math.max(r.left + r.width / 2, 1), innerWidth - 1);
        const el = document.elementFromPoint(x, r.top + r.height / 2);
        return el === t || t.contains(el) ? 'ok' : 'blocked:' + (el ? el.className : 'null');
      }));

      // Switch to tab 2 with a real click: no fetch/xhr may follow.
      const reqs = [];
      page.on('request', (r) => reqs.push(r.resourceType() + ' ' + r.url()));
      await tabs.nth(1).click();
      await page.waitForTimeout(900);
      const after = await sec.evaluate((s) => ({
        selected: Array.from(s.querySelectorAll('[role=tab]')).map((t) => t.getAttribute('aria-selected')).join(','),
        open: Array.from(s.querySelectorAll('[role=tabpanel]')).map((p) => (p.hidden ? 0 : 1)).join(','),
      }));
      await shot(page, sec, name + '-tab2.png');
      // fetch/xhr is what a tab switch must never make. (A "other" request
      // here is the shop's own prefetch-on-intent of a product link the
      // pointer came to rest over — InstantNav, not the tabs.)
      const nonImage = reqs.filter((r) => /^(fetch|xhr) /.test(r));

      // Keyboard: the arrow that means "next" in this direction.
      await tabs.nth(1).focus();
      await page.keyboard.press(lang === 'ar' ? 'ArrowLeft' : 'ArrowRight');
      const kb = await page.evaluate(() => {
        const a = document.activeElement;
        return a && a.getAttribute('role') === 'tab' ? a.id + ':' + a.getAttribute('aria-selected') : 'none';
      });

      // Card links in the open panel answer a click at their centre.
      await sec.locator('[role=tabpanel]:not([hidden]) .gs-grid').evaluate((e) => e.scrollIntoView({ block: 'center' }));
      await page.waitForTimeout(300);
      const cardHits = await sec.evaluate((s) => {
        const panel = Array.from(s.querySelectorAll('[role=tabpanel]')).find((p) => !p.hidden);
        let ok = 0, bad = 0, off = 0;
        for (const a of panel.querySelectorAll('.gs-cell a[href]')) {
          // The part of the link the carousel's scrollport actually shows: a
          // peeking card is clipped by its track, so its centre is tested
          // inside the visible sliver, the way a thumb would reach it.
          const b = a.getBoundingClientRect();
          const t = a.closest('.gs-grid').getBoundingClientRect();
          // 120px off the top: the sticky header, which covers the page by design.
          const r = { left: Math.max(b.left, t.left, 0), right: Math.min(b.right, t.right, innerWidth), top: Math.max(b.top, 120), bottom: Math.min(b.bottom, innerHeight) };
          if (b.width === 0 || r.right - r.left < 8 || r.bottom - r.top < 8) { off++; continue; }
          const el = document.elementFromPoint((r.left + r.right) / 2, (r.top + r.bottom) / 2);
          if (el === a || a.contains(el)) ok++; else bad++;
        }
        return { ok, bad, off };
      });

      const metrics = await page.evaluate(() => ({
        scrollWidth: document.documentElement.scrollWidth,
        cls: Math.round(window.__cls * 10000) / 10000,
      }));

      // And a card really navigates.
      const href = await sec.locator('[role=tabpanel]:not([hidden]) .gs-cell a[href*="/product/"]').first().getAttribute('href');
      await Promise.all([page.waitForURL((u) => u.pathname.includes('/product/'), { timeout: 15000 }), sec.locator('[role=tabpanel]:not([hidden]) .gs-cell a[href*="/product/"]').first().click()]);

      out.push({ label: LABEL, lang, w, tabs: n, tabHits, after, nonImageRequestsOnSwitch: nonImage, imageRequestsOnSwitch: reqs.length - nonImage.length, keyboard: kb, cardHits, navigated: href, ...metrics, errors, bad });
      await ctx.close();
    }
  }
  console.log(JSON.stringify(out, null, 1));
  await browser.close();
})();
