/*
 * Lane SG evidence. Chromium at 390 and 1280, and the numbers under each shot.
 *
 * The pinned headless-shell build is not in this container, so the browser is
 * the full Chromium at the path below — `npx playwright install` is forbidden
 * here.
 *
 * WHAT IS MEASURED. The price TEXT on each of the three sets' tiles, the price
 * text on each set's own product page, and document.documentElement.scrollWidth
 * at both widths. The point of this lane is that the first two agree, so both
 * are read off the rendered page rather than asserted.
 *
 * SG_BEFORE=1 labels the run `-before`, for the pass taken with the set columns
 * removed from Store\ShopController::CARD_COLUMNS — the defect, photographed.
 */
const { chromium } = require('playwright');
const fs = require('fs');

const BASE = process.env.SG_BASE || 'http://127.0.0.1:8710';
const OUT = process.env.SG_OUT || __dirname + '/../docs/lane-sg-shots';
const SUFFIX = process.env.SG_BEFORE ? '-before' : '';

const SETS = ['sg-night-repair-set', 'sg-barrier-rescue-set', 'sg-glow-starter-set'];

const text = (el) => (el ? el.textContent.replace(/\s+/g, ' ').trim() : null);

/* Every tile's price, keyed by slug. Scoped to the CARD: a grid carries two
   dozen prices and reading one off the document would read somebody else's. */
const GRID = () => {
  const out = {};
  for (const card of document.querySelectorAll('.kbb-tile')) {
    const href = card.querySelector('a.cn')?.getAttribute('href') || '';
    const slug = href.replace(/\/$/, '').split('/').pop();
    const t = (el) => (el ? el.textContent.replace(/\s+/g, ' ').trim() : null);
    out[slug] = {
      was: t(card.querySelector('.kbb-card-reg')),
      now: t(card.querySelector('.kbb-card-price')),
      badge: t(card.querySelector('.kbb-badge-sale')),
    };
  }
  return {
    viewport: document.documentElement.clientWidth,
    scrollWidth: document.documentElement.scrollWidth,
    tiles: out.length === 0 ? out : out,
  };
};

const PAGE = () => {
  const t = (el) => (el ? el.textContent.replace(/\s+/g, ' ').trim() : null);
  return {
    viewport: document.documentElement.clientWidth,
    scrollWidth: document.documentElement.scrollWidth,
    now: t(document.querySelector('span.now')),
    was: t(document.querySelector('span.now + s')),
    off: t(document.querySelector('span.now ~ .off')),
    heading: t(document.querySelector('h1')),
  };
};

/* The CART probe reads the LINE price cell (`.cpr`, resources/views/store/
   cart-inner.blade.php) and the order total, scoped to the line list. An
   unscoped textContent sweep read the inline <style> and <script> the set row
   ships with and reported four kilobytes of CSS as a "line". */
const CART = () => {
  const t = (el) => (el ? el.textContent.replace(/\s+/g, ' ').trim() : null);
  const lines = [...document.querySelectorAll('.items .cpr')].map((cell, i) => ({
    name: t(document.querySelectorAll('.items .cn')[i]),
    // The struck-through figure is a CHILD of the cell, so the cell's own text
    // is "now" + "was" run together. Split rather than trimmed.
    was: t(cell.querySelector('.cwas')),
    price: t(cell).replace(t(cell.querySelector('.cwas')) || '~none~', '').trim(),
  }));
  return {
    viewport: document.documentElement.clientWidth,
    scrollWidth: document.documentElement.scrollWidth,
    lines,
    subtotal: t(document.querySelector('.srow span:last-child')),
    total: t(document.querySelector('.srow.tot span:last-child')),
  };
};

async function shoot(page, name, w, h, probe, fullPage = true) {
  await page.setViewportSize({ width: w, height: h });
  await page.waitForTimeout(300);
  const m = await page.evaluate(probe);
  await page.screenshot({ path: `${OUT}/${name}${SUFFIX}-${w}.png`, fullPage });
  return { shot: `${name}${SUFFIX}-${w}`, ...m };
}

(async () => {
  fs.mkdirSync(OUT, { recursive: true });

  const browser = await chromium.launch({
    executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
  });
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 1400 }, deviceScaleFactor: 2 });
  const page = await ctx.newPage();
  const out = [];

  /* ── the shop grid: three sets, one per pricing mode, beside four ordinary
       products ───────────────────────────────────────────────────────────── */
  for (const w of [390, 1280]) {
    await page.goto(`${BASE}/shop/`, { waitUntil: 'networkidle' });
    out.push(await shoot(page, 'shop-grid-three-sets', w, 1400, GRID));
  }

  /* ── each set's own product page, for comparison ──────────────────────── */
  for (const slug of SETS) {
    for (const w of [390, 1280]) {
      await page.goto(`${BASE}/product/${slug}/`, { waitUntil: 'networkidle' });
      out.push(await shoot(page, `product-page-${slug}`, w, 1400, PAGE));
    }
  }

  /* ── the basket, holding two of the three ─────────────────────────────────
   *
   * Filled through the shop's own /api/cart/add with the page's CSRF token, not
   * by planting the cookie: this application encrypts cookies, so a raw token
   * set from outside is not a cart the server can read. tools/set-shots.cjs
   * carries the full reasoning.
   */
  /* The ids come off the product pages themselves, so nothing here has to know
     one — the same trick tools/set-shots.cjs uses. */
  const ids = [];
  for (const slug of [SETS[0], SETS[2]]) {
    await page.goto(`${BASE}/product/${slug}/`, { waitUntil: 'networkidle' });
    ids.push(await page.evaluate(() => Number(
      document.querySelector('form.kbb-cart-form')?.dataset.product_id
      || document.querySelector('[data-kbb-add]')?.dataset.kbbAdd
    )));
  }

  const added = await page.evaluate(async ([base, productIds]) => {
    const token = decodeURIComponent(
      (document.cookie.match('(^|;)\\s*XSRF-TOKEN\\s*=\\s*([^;]+)') || [])[2] || ''
    );
    const out = [];
    for (const id of productIds) {
      const r = await fetch(base + '/api/cart/add', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-XSRF-TOKEN': token, Accept: 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify({ product_id: id, quantity: 1 }),
      });
      out.push([id, r.status, (await r.text()).slice(0, 200)]);
    }
    return out;
  }, [BASE, ids]);
  out.push({ addedToBasket: added });

  for (const w of [390, 1280]) {
    await page.goto(`${BASE}/cart/`, { waitUntil: 'networkidle' });
    out.push(await shoot(page, 'cart-two-sets', w, 1400, CART));
  }

  await browser.close();
  console.log(JSON.stringify(out, null, 2));
})();
