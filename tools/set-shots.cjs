/*
 * Lane SET evidence. Chromium at 390 and 1280, the shots the owner asked for,
 * and the numbers under each of them.
 *
 * The pinned headless-shell build is not in this container, so the browser is
 * the full Chromium at the path below — `npx playwright install` is forbidden
 * here.
 *
 * WHAT IS MEASURED. document.documentElement.scrollWidth at both widths, WITH
 * THE POPUP OPEN, because the owner asked for that specifically: a popup that
 * widens the page at 390px is a popup that gives the whole shop a horizontal
 * scrollbar. Plus the geometry of the fan and the row it sits in.
 */
const { chromium } = require('playwright');
const fs = require('fs');

const BASE = process.env.SET_BASE || 'http://127.0.0.1:8989';
const OUT = process.env.SET_OUT || '/home/user/lane-set/docs/lane-set-shots';
const TOKEN = 'laneset-preview-cart-token';

/* Scoped, because a page carries more than one set row: the cart PANEL is
   rendered on every page of this shop and its browsed tab has one too. An
   unscoped querySelector measured the hidden drawer copy and reported a width
   of nothing. */
const MEASURE = (scope) => {
  const root = document.querySelector(scope) || document;
  const px = (el, p) => (el ? Math.round(parseFloat(getComputedStyle(el)[p])) : null);
  const box = (el) => (el ? Math.round(el.getBoundingClientRect().width) : null);
  const circles = [...root.querySelectorAll('.kset-c')];
  return {
    viewport: document.documentElement.clientWidth,
    scrollWidth: document.documentElement.scrollWidth,
    setRows: root.querySelectorAll('.kset').length,
    memberCircles: circles.length,
    circleDiameter: circles[0] ? Math.round(circles[0].getBoundingClientRect().width) : null,
    fanWidth: box(root.querySelector('.kset-fan')),
    openerFontSize: px(root.querySelector('.kset-btn'), 'fontSize'),
    openerText: root.querySelector('.kset-btn')?.textContent ?? null,
    popupOpen: !!root.querySelector('.kset-pop.is-open'),
    popupWidth: box(root.querySelector('.kset-pop.is-open')),
    popupRightEdge: (() => {
      const p = root.querySelector('.kset-pop.is-open');
      return p ? Math.round(p.getBoundingClientRect().right) : null;
    })(),
    popupLines: [...root.querySelectorAll('.kset-pop.is-open li')].map((l) => l.textContent.trim()),
    saving: root.querySelector('.kset-save')?.textContent ?? null,
    ariaExpanded: root.querySelector('.kset-btn')?.getAttribute('aria-expanded') ?? null,
    ariaControls: root.querySelector('.kset-btn')?.getAttribute('aria-controls') ?? null,
    labelsOnCircles: circles.map((c) => c.textContent.trim()).filter(Boolean),
  };
};

/* `fullPage` is a per-shot choice, not a default. The cart PANEL is drawn over
   whatever page the shopper is on, and the home page is ~18 000px tall — a
   full-page shot of it is a thumbnail strip in which the panel is four pixels
   high and proves nothing. Those shots are the viewport. */
async function shoot(page, name, w, h, scope = 'body', fullPage = true) {
  await page.setViewportSize({ width: w, height: h });
  await page.waitForTimeout(350);
  const m = await page.evaluate(MEASURE, scope);
  await page.screenshot({ path: `${OUT}/${name}-${w}.png`, fullPage });
  console.log(JSON.stringify({ shot: `${name}-${w}`, ...m }));
  return m;
}

(async () => {
  fs.mkdirSync(OUT, { recursive: true });

  const browser = await chromium.launch({
    executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
  });

  const ctx = await browser.newContext({ viewport: { width: 1280, height: 1400 }, deviceScaleFactor: 2 });
  const page = await ctx.newPage();

  /* ── FILL THE BASKET THROUGH THE SHOP'S OWN DOOR ──────────────────────────
   *
   * Not by planting the cart cookie: this application ENCRYPTS cookies, so a
   * raw token set from outside is not a cart the server can read — the page
   * renders empty and the shot proves nothing. The tests get round that with
   * withoutMiddleware(EncryptCookies), which a real browser has no equivalent
   * of. So the basket is filled by POSTing to the same /cart/add a shopper's
   * Add-to-cart button posts to, from inside the page, with the page's own
   * CSRF token — which also means these shots exercise the real add path.
   */
  await page.goto(`${BASE}/product/laneset-glow-starter-set/`, { waitUntil: 'networkidle' });

  const added = await page.evaluate(async (base) => {
    const token = decodeURIComponent(
      (document.cookie.match('(^|;)\\s*XSRF-TOKEN\\s*=\\s*([^;]+)') || [])[2] || ''
    );
    // The id the page itself carries, so nothing here has to know one.
    const setId = Number(document.querySelector('form.kbb-cart-form')?.dataset.product_id);
    const out = [];
    for (const id of [setId, setId - 1]) {
      const r = await fetch(base + '/api/cart/add', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-XSRF-TOKEN': token, Accept: 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify({ product_id: id, quantity: 1 }),
      });
      out.push([id, r.status]);
    }
    return out;
  }, BASE);

  console.log(JSON.stringify({ addedToBasket: added }));

  /* ── the set published on the storefront ─────────────────────────────── */
  await page.goto(`${BASE}/product/laneset-glow-starter-set/`, { waitUntil: 'networkidle' });
  for (const w of [390, 1280]) await shoot(page, 'storefront-set-page', w, 1400, 'main, body');

  /* ── the cart PAGE, with the popup open ──────────────────────────────── */
  for (const w of [390, 1280]) {
    await page.goto(`${BASE}/cart/`, { waitUntil: 'networkidle' });
    await page.setViewportSize({ width: w, height: 1400 });
    await page.waitForTimeout(250);
    // Scoped to the cart page's own line list: the drawer on this same page
    // carries a set row too, and its copy is hidden.
    await page.click('.items .kset-btn');
    await page.waitForTimeout(250);
    await shoot(page, 'cart-page-popup-open', w, 1400, '.items');
  }

  /* ── the cart PANEL (the drawer), with the popup open ─────────────────── */
  for (const w of [390, 1280]) {
    await page.goto(`${BASE}/`, { waitUntil: 'networkidle' });
    await page.setViewportSize({ width: w, height: 1000 });
    await page.waitForTimeout(250);
    /* `.on`, which is the class resources/js/kbb/overlay.js adds and removes —
       not `.open`. Opened directly rather than by clicking the header basket,
       because the header's own control differs between the phone and the
       desktop chrome and this shot is about the row inside the panel. */
    await page.evaluate(() => {
      document.querySelector('.drawer')?.classList.add('on');
      document.body.classList.add('kbb-locked');
    });
    await page.waitForTimeout(250);
    const btn = await page.$('#kcCart .kset-btn');
    if (btn) { await btn.click({ force: true }); await page.waitForTimeout(250); }
    await shoot(page, 'cart-panel-popup-open', w, 1000, '#kcCart', false);
  }

  /* ── the checkout summary, with the popup open ────────────────────────── */
  for (const w of [390, 1280]) {
    await page.goto(`${BASE}/checkout/`, { waitUntil: 'networkidle' });
    await page.setViewportSize({ width: w, height: 1600 });
    await page.waitForTimeout(250);
    const opener = await page.$('#kbbSummary .kset-btn');
    if (opener) { await opener.click(); await page.waitForTimeout(250); }
    await shoot(page, 'checkout-summary-popup-open', w, 1600, '#kbbSummary');
  }

  await browser.close();
})();
