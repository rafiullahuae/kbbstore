/*
 * Lane PS — "You may also like" on the product page, measured in a real
 * browser at 390px and 1280px. Reports, does not judge.
 *
 *   PS_BASE   http://127.0.0.1:8987
 *   PS_OUT    where the PNGs and report.json go
 *   PS_TAG    before | after
 *   PS_SLUG   the product page to open
 *   PS_CHROME the Chromium binary
 *   PS_EMAIL / PS_PASSWORD  an owner, for the two admin screens (after only)
 *
 * Measurements are taken by the SCRIPT, from outside the page. The page's own
 * JavaScript reads no element geometry at all (CLAUDE.md rule 4).
 */
import { chromium } from 'playwright';
import { writeFileSync, mkdirSync } from 'node:fs';

const BASE = process.env.PS_BASE || 'http://127.0.0.1:8987';
const OUT = process.env.PS_OUT || './storage/ps-logs/shots';
const TAG = process.env.PS_TAG || 'after';
const SLUG = process.env.PS_SLUG || 'heartleaf-77-soothing-toner';
const CHROME = process.env.PS_CHROME;
const ADMIN = TAG !== 'before' && process.env.PS_EMAIL;

mkdirSync(OUT, { recursive: true });

const report = { tag: TAG, slug: SLUG, widths: [] };
const browser = await chromium.launch(
  CHROME ? { executablePath: CHROME, args: ['--no-sandbox'] } : { args: ['--no-sandbox'] },
);

async function measure(page) {
  return page.evaluate(() => {
    const sec = document.querySelector('#related');
    const cards = sec ? [...sec.children] : [];
    const r = (el) => (el ? el.getBoundingClientRect() : null);
    const visible = cards.filter((c) => {
      const b = c.getBoundingClientRect();
      const t = sec.getBoundingClientRect();
      return b.left >= t.left - 1 && b.right <= t.right + 1;
    }).length;
    const prev = document.querySelector('[data-ymal-prev]');
    const next = document.querySelector('[data-ymal-next]');
    return {
      docScrollWidth: document.documentElement.scrollWidth,
      innerWidth: window.innerWidth,
      cards: cards.length,
      fullyVisibleCards: visible,
      cardWidth: cards[0] ? Math.round(r(cards[0]).width * 10) / 10 : null,
      cardHeight: cards[0] ? Math.round(r(cards[0]).height * 10) / 10 : null,
      trackWidth: sec ? Math.round(r(sec).width * 10) / 10 : null,
      trackScrollWidth: sec ? sec.scrollWidth : null,
      trackScrollLeft: sec ? Math.round(sec.scrollLeft) : null,
      heading: (document.querySelector('.ymal h2, #related')?.closest('section')?.querySelector('h2')?.textContent || '').trim(),
      prevDisabled: prev ? prev.disabled : null,
      nextDisabled: next ? next.disabled : null,
      prevDisplay: prev ? getComputedStyle(prev).display : null,
      sectionTop: sec ? Math.round(r(sec.closest('section')).top + window.scrollY) : null,
    };
  });
}

try {
  for (const width of [390, 1280]) {
    const ctx = await browser.newContext({ viewport: { width, height: width === 390 ? 844 : 900 } });
    const page = await ctx.newPage();
    const block = { width };

    await page.goto(`${BASE}/product/${SLUG}/`, { waitUntil: 'networkidle' });
    const sec = page.locator('#related').first();
    if (await sec.count()) {
      await sec.evaluate((el) => { el.closest('section').scrollIntoView({ block: 'start', behavior: 'instant' }); window.scrollBy({ top: -190, behavior: 'instant' }); });
      await page.waitForTimeout(400);
      block.rest = await measure(page);
      await page.screenshot({ path: `${OUT}/${TAG}-product-${width}.png` });

      // Scrolled: a swipe on a phone, the Next arrow on a laptop.
      if (TAG !== 'before') {
        if (width >= 901) {
          await page.click('[data-ymal-next]');
        } else {
          await sec.evaluate((el) => el.scrollBy({ left: el.scrollWidth, behavior: 'instant' }));
        }
        await page.waitForTimeout(900);
        block.scrolled = await measure(page);
        await page.screenshot({ path: `${OUT}/${TAG}-product-${width}-scrolled.png` });

        // Keyboard: focus the track; the arrow keys scroll it natively.
        await page.focus('#related');
        for (let i = 0; i < 12; i++) { await page.keyboard.press('ArrowLeft'); await page.waitForTimeout(120); }
        await page.waitForTimeout(700);
        block.keyboardBack = await measure(page);
        block.keyboardFocus = await page.evaluate(() => document.activeElement && document.activeElement.id);
      }
    } else {
      block.rest = { noSection: true, docScrollWidth: await page.evaluate(() => document.documentElement.scrollWidth) };
    }

    // The Arabic page, for RTL, when it exists.
    if (TAG !== 'before') {
      const ar = await page.goto(`${BASE}/ar/product/${SLUG}/`, { waitUntil: 'networkidle' });
      if (ar && ar.ok() && (await page.locator('#related').count())) {
        await page.locator('#related').evaluate((el) => { el.closest('section').scrollIntoView({ block: 'start', behavior: 'instant' }); window.scrollBy({ top: -190, behavior: 'instant' }); });
        await page.waitForTimeout(400);
        block.rtl = await measure(page);
        await page.screenshot({ path: `${OUT}/${TAG}-product-ar-${width}.png` });
        if (width >= 901) {
          await page.click('[data-ymal-next]');
          await page.waitForTimeout(900);
          block.rtlScrolled = await measure(page);
          await page.screenshot({ path: `${OUT}/${TAG}-product-ar-${width}-scrolled.png` });
        }
      } else {
        block.rtl = { status: ar ? ar.status() : null };
      }
    }

    if (ADMIN) {
      await page.goto(`${BASE}/admin`, { waitUntil: 'networkidle' });
      if (await page.locator('input[type=email]').count()) {
        await page.fill('input[type=email]', process.env.PS_EMAIL);
        await page.fill('input[type=password]', process.env.PS_PASSWORD);
        await page.click('button[type=submit]');
        await page.waitForLoadState('networkidle');
      }
      await page.setViewportSize({ width, height: 1800 });
      await page.goto(`${BASE}/admin?go=productpage`, { waitUntil: 'networkidle' });
      await page.waitForTimeout(1500);
      const tab = page.locator('[data-pptab="ymal"]').first();
      if (await tab.count()) { await tab.click(); await page.waitForTimeout(800); }
      block.adminSettings = await page.evaluate(() => ({
        docScrollWidth: document.documentElement.scrollWidth,
        fields: document.querySelectorAll('[data-pya]').length,
      }));
      await page.screenshot({ path: `${OUT}/${TAG}-admin-product-page-ymal-${width}.png` });

      const id = process.env.PS_PRODUCT_ID || '5';
      await page.goto(`${BASE}/admin?go=product-editor`, { waitUntil: 'networkidle' });
      await page.waitForTimeout(1500);
      await page.evaluate((pid) => window.peoEdit(Number(pid)), id);
      await page.waitForTimeout(2000);
      const panel = page.locator('[data-peo-panel="alsolike"]').first();
      if (await panel.count()) {
        const q = panel.locator('#peo-ymalq');
        await q.fill('serum');
        await page.waitForTimeout(1200);
        // Add two picks, so the picture shows the list as well as the search.
        for (let i = 0; i < 2; i++) {
          const add = page.locator('[data-peo-ymaladd]').first();
          if (await add.count()) { await add.click(); await page.waitForTimeout(500); }
        }
        await panel.scrollIntoViewIfNeeded();
        block.adminEditor = await page.evaluate(() => ({
          docScrollWidth: document.documentElement.scrollWidth,
          picked: document.querySelectorAll('[data-peo-ymalrow]').length,
          found: document.querySelectorAll('[data-peo-ymaladd]').length,
        }));
        await panel.screenshot({ path: `${OUT}/${TAG}-admin-editor-picker-${width}.png` });
      } else {
        block.adminEditor = { noPanel: true };
      }
    }

    report.widths.push(block);
    await ctx.close();
  }
} finally {
  await browser.close();
  writeFileSync(`${OUT}/${TAG}-report.json`, JSON.stringify(report, null, 2));
  console.log(JSON.stringify(report, null, 2));
}
