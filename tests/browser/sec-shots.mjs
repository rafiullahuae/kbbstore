/**
 * Lane SEC's pictures — the admin-path leak, and the basket that could not be
 * paid for.
 *
 * Shot in real Chromium at 390 and 1280 against a preview of THIS checkout
 * whose admin path is `mr-cool` — the one the owner named — so a leak would be
 * visible in the address bar rather than argued about.
 *
 * TWO PHASES, because the second half is a before and an after of the same
 * basket. Phase `before` adds the set and the loose toner while the shelf holds
 * five of them and shoots the basket with both lines on it; the caller then
 * takes the shelf down to one; phase `after` comes back with the same browser
 * state and shoots what the shopper is shown. The state is carried in a
 * storageState file, because a fresh context has no cart cookie and the cart
 * cookie is encrypted — it cannot be written from outside the application.
 *
 *   KBB_SEC_URL=http://127.0.0.1:8631 KBB_SEC_PHASE=before \
 *   KBB_SEC_OUT=docs/sec-shots node tests/browser/sec-shots.mjs
 *
 * newContext({ viewport }) rather than page.setViewportSize(): the latter does
 * not take in this environment, and a measurement at the wrong width is worse
 * than none.
 */
import { chromium } from 'playwright';
import { mkdirSync } from 'node:fs';

const BASE = process.env.KBB_SEC_URL || 'http://127.0.0.1:8631';
const OUT = process.env.KBB_SEC_OUT || 'docs/sec-shots';
const STATE = process.env.KBB_SEC_STATE || '/tmp/sec-state';
const PHASE = process.env.KBB_SEC_PHASE || 'before';
const CHROME = process.env.KBB_SEC_CHROME || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';

// The two products this shoot is about, in the preview's own database. Passed
// in rather than looked up: /api/products is an ALLOWLIST and does not publish
// ids, which is ApiSecurityTest doing its job.
const SET_ID = Number(process.env.KBB_SEC_SET_ID);
const TONER_ID = Number(process.env.KBB_SEC_TONER_ID);

mkdirSync(OUT, { recursive: true });
mkdirSync(STATE, { recursive: true });

const ALL_WIDTHS = [
  { w: 390, h: 844, tag: '390' },
  { w: 1280, h: 1000, tag: '1280' },
];

/*
 * One width per run when asked, so the caller can put the shelf back between
 * them. The `after` phase PLACES AN ORDER, which takes the shop's last toner —
 * so a second width run against the same shop finds the set unsellable and
 * photographs a refusal instead of a receipt. That is the shop being right and
 * the shoot being wrong.
 */
const WIDTHS = process.env.KBB_SEC_WIDTHS
  ? ALL_WIDTHS.filter(x => process.env.KBB_SEC_WIDTHS.split(',').includes(x.tag))
  : ALL_WIDTHS;

const b = await chromium.launch({ executablePath: CHROME, args: ['--no-sandbox'] });
const measured = {};

for (const { w, h, tag } of WIDTHS) {
  const ctx = await b.newContext(
    PHASE === 'before'
      ? { viewport: { width: w, height: h } }
      : { viewport: { width: w, height: h }, storageState: `${STATE}/${tag}.json` }
  );

  const p = await ctx.newPage();
  p.on('pageerror', e => console.log('  page error:', e.message));

  const shot = async (name, opts = {}) => {
    await p.screenshot({ path: `${OUT}/${tag}-${name}.png`, ...opts });
    console.log('  shot:', `${tag}-${name}`);
  };

  const geometry = async () => p.evaluate(() => ({
    scrollWidth: document.documentElement.scrollWidth,
    clientWidth: document.documentElement.clientWidth,
    at: location.pathname + location.search,
  }));

  if (PHASE === 'before') {
    /* ── 1 · the tracking result, and the link the owner reported ───────── */

    await p.goto(`${BASE}/track-my-order?order=SEC-1001&email=shopper@example.com`, { waitUntil: 'networkidle' });
    await shot('01-tracking-result');
    measured[`${tag}.tracking`] = await geometry();

    const link = p.locator('.acw-fine a').first();
    measured[`${tag}.tracking_link_href`] = await link.getAttribute('href');

    /* ── 2 · where that link LANDS for a logged-out shopper ─────────────── */

    await Promise.all([p.waitForNavigation({ waitUntil: 'networkidle' }), link.click()]);
    await shot('02-lands-on-customer-login');
    measured[`${tag}.after_click`] = await geometry();
    measured[`${tag}.leaks_admin_path`] =
      p.url().includes('mr-cool') || (await p.content()).includes('mr-cool');

    /* ── 3 · the basket: the set, and the same product loose ────────────── */

    /*
     * Added through POST /api/cart/add, which is the endpoint the shop's own
     * Add-to-bag button posts to, carrying the CSRF token the page was served
     * with. Driving the button itself would be closer still, but the basket has
     * to be built by the browser session that is about to be photographed — the
     * cart cookie is encrypted and cannot be written from outside the
     * application, which is the whole reason these shots come in two phases.
     */
    await p.goto(`${BASE}/product/sec-medicube-booster-set`, { waitUntil: 'networkidle' });

    const added = await p.evaluate(async (ids) => {
      const token = (window.KBB && window.KBB.csrf) || document.querySelector('meta[name="csrf-token"]')?.content;
      const out = [];

      for (const id of ids) {
        const r = await fetch('/api/cart/add', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token, 'X-Requested-With': 'XMLHttpRequest' },
          body: JSON.stringify({ product_id: id, quantity: 1 }),
        });

        out.push(id + '=' + r.status);
      }

      return out;
    }, [SET_ID, TONER_ID]);

    measured[`${tag}.added`] = added;
    await p.waitForTimeout(600);

    await p.goto(`${BASE}/cart`, { waitUntil: 'networkidle' });
    await shot('03-basket-set-plus-the-same-product-loose', { fullPage: true });
    measured[`${tag}.before_cart`] = await geometry();
    measured[`${tag}.before_heading`] = (await p.locator('#cartLead').innerText().catch(() => '')).trim();
    measured[`${tag}.before_rows`] = await p.locator('#cartInner .ci').count();

    await ctx.storageState({ path: `${STATE}/${tag}.json` });
  } else {
    /* ── 4 · the shelf is down to one: what the shopper is shown ────────── */

    await p.goto(`${BASE}/cart`, { waitUntil: 'networkidle' });
    await shot('04-loose-line-gone-set-stays', { fullPage: true });
    measured[`${tag}.after_cart`] = await geometry();
    measured[`${tag}.after_heading`] = (await p.locator('#cartLead').innerText().catch(() => '')).trim();
    measured[`${tag}.after_rows`] = await p.locator('#cartInner .ci').count();
    measured[`${tag}.notice`] = (await p.locator('.woocommerce-info').first().textContent().catch(() => '')).trim();
    measured[`${tag}.notice_visible`] = await p.locator('.woocommerce-info').first().isVisible().catch(() => false);

    /* ── 5 · the checkout, carrying the same sentence ───────────────────── */

    await p.goto(`${BASE}/checkout`, { waitUntil: 'networkidle' });
    await shot('05-checkout-with-the-sentence');
    measured[`${tag}.checkout`] = await geometry();

    /* ── 6 · and the order places ───────────────────────────────────────── */

    /*
     * Filled through the DOM rather than with fill(), because the checkout
     * carries several of these as HIDDEN inputs that a visible address picker
     * writes into. A picture of the order placing is the point; typing into the
     * picker is a different shot and a different lane's screen.
     */
    await p.evaluate(() => {
      const set = (name, value) => {
        for (const el of document.querySelectorAll(`[name="${name}"]`)) {
          el.value = value;
          el.dispatchEvent(new Event('input', { bubbles: true }));
          el.dispatchEvent(new Event('change', { bubbles: true }));
        }
      };

      set('billing_email', 'shopper@example.com');
      set('billing_phone', '+971500000000');
      set('billing_first_name', 'Sam');
      set('billing_last_name', 'Shopper');
      set('billing_address_1', '12 Marina Walk');
      set('billing_city', 'Dubai');
      set('billing_state', 'Dubai');
      set('billing_country', 'AE');
      set('payment_method', 'cod');

      for (const el of document.querySelectorAll('input[name="payment_method"][value="cod"]')) {
        el.checked = true;
        el.dispatchEvent(new Event('change', { bubbles: true }));
      }
    });

    await p.locator('#kbbCheckoutForm').evaluate(f => f.submit());
    await p.waitForLoadState('networkidle');
    await p.waitForTimeout(1500);

    await shot('06-order-placed');
    measured[`${tag}.placed`] = await geometry();
    measured[`${tag}.placed_says`] = (await p.locator('h1, .or-head h1, .or-lead').first().innerText().catch(() => '')).trim();
  }

  await ctx.close();
}

console.log(JSON.stringify(measured, null, 2));
await b.close();
