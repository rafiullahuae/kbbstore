/* Lane BK: the basket after a payment that did not finish, before and after.
 *
 *   node tools/bk-shots.cjs <port> <before|after>
 *
 * Drives the REAL place() and the REAL return legs on the preview that
 * tools/bk-preview.sh boots (providers answered locally by
 * tools/bk-fake-providers.php): a Tabby order cancelled at Tabby (its cancel
 * address, /checkout/pending), and a card order whose 3-D Secure failed by
 * redirect (Stripe's return_url, /checkout/success?...&redirect_status=failed).
 * Shots at 390 and 1280, English and Arabic, with the numbers that matter.
 */
const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright');

const PORT = process.argv[2] || '8761';
const TAG = process.argv[3] || 'after';
const BASE = `http://127.0.0.1:${PORT}`;
const OUT = path.join(__dirname, '..', 'docs', 'BK-basket-back-shots');
const CHROME = process.env.CHROME || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const DB = path.join(__dirname, '..', 'storage', 'framework', 'testing', 'lane-bk-preview', 'preview.sqlite');

const VIEWPORTS = [{ tag: '390', w: 390, h: 844 }, { tag: '1280', w: 1280, h: 900 }];
const LANGS = [{ tag: 'en', prefix: '' }, { tag: 'ar', prefix: '/ar' }];
const FLOWS = ['tabby-cancel', 'stripe-3ds-cancel'];
const UA = 'Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Mobile Safari/537.36';

function sql(q) {
  const php = `$d=new PDO("sqlite:${DB}");foreach($d->query(${JSON.stringify(q)}) as $r){echo implode("|",array_filter($r,"is_int",ARRAY_FILTER_USE_KEY)),"\\n";}`;
  return require('child_process').execFileSync('php', ['-r', php], { encoding: 'utf8' }).trim();
}

async function placeUnpaid(page, prefix, method) {
  for (const slug of ['bk-glass-skin-serum', 'bk-rice-toner']) {
    await page.goto(`${BASE}${prefix}/product/${slug}/`, { waitUntil: 'domcontentloaded' });
    await page.click('#mainAdd');
    await page.waitForTimeout(600);
  }
  const resp = await page.goto(`${BASE}${prefix}/checkout/`, { waitUntil: 'domcontentloaded' });
  if (!resp || resp.status() !== 200) throw new Error('checkout answered ' + (resp && resp.status()));

  const placed = await page.evaluate(async (method) => {
    const form = document.getElementById('kbbCheckoutForm');
    const fd = new FormData(form);
    const set = {
      billing_email: 'shopper@example.com', billing_phone: '+971500000000', billing_first_name: 'Aisha',
      billing_last_name: 'Khan', billing_address_1: '12 Marina Walk', billing_city: 'Dubai',
      billing_state: 'Dubai', billing_country: 'AE', payment_method: method,
    };
    for (const [k, v] of Object.entries(set)) fd.set(k, v);
    const r = await fetch(form.action, { method: 'POST', body: fd, headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' });
    return { status: r.status, body: await r.json().catch(() => null) };
  }, method);

  if (placed.status !== 200 || !placed.body || placed.body.ok !== true) {
    throw new Error('place() refused: ' + JSON.stringify(placed));
  }
  return sql('select order_number, transaction_id from orders order by id desc limit 1').split('|');
}

async function measure(page) {
  return page.evaluate(async () => {
    const cls = await new Promise((resolve) => {
      let total = 0;
      try {
        new PerformanceObserver((l) => { for (const e of l.getEntries()) if (!e.hadRecentInput) total += e.value; })
          .observe({ type: 'layout-shift', buffered: true });
      } catch (e) { /* unsupported */ }
      setTimeout(() => resolve(total), 400);
    });
    const note = document.querySelector('.kbb-cartpage .co-note');
    const retry = document.querySelector('.kbb-cartpage .co-retry');
    const nr = note ? note.getBoundingClientRect() : null;
    const rr = retry ? retry.getBoundingClientRect() : null;
    let retryHit = null;
    if (rr) {
      const el = document.elementFromPoint(rr.left + rr.width / 2, rr.top + rr.height / 2);
      retryHit = !!(el && (el === retry || retry.contains(el)));
    }
    return {
      url: location.pathname + location.search,
      dir: document.documentElement.getAttribute('dir') || 'ltr',
      scrollWidth: document.documentElement.scrollWidth,
      clientWidth: document.documentElement.clientWidth,
      heading: (document.querySelector('.kbb-cartpage h1, .kbb-cartpage .ttl, h1') || {}).textContent?.trim().replace(/\s+/g, ' ') || null,
      lines: document.querySelectorAll('.kbb-cartpage .cn a').length,
      emptyBag: !!document.querySelector('.kbb-cartpage .empty'),
      notice: note ? note.textContent.trim().replace(/\s+/g, ' ') : null,
      noticeClass: note ? note.className : null,
      noticeBox: nr ? { w: Math.round(nr.width), h: Math.round(nr.height), font: getComputedStyle(note).fontSize } : null,
      restoreButton: document.querySelectorAll('.co-restore').length,
      retry: rr ? { w: Math.round(rr.width), h: Math.round(rr.height), href: retry.getAttribute('href'), hit: retryHit } : null,
      cls: Math.round(cls * 10000) / 10000,
    };
  });
}

(async () => {
  fs.mkdirSync(OUT, { recursive: true });
  const browser = await chromium.launch({ executablePath: CHROME });
  const rows = [];

  for (const flow of FLOWS) {
    for (const lang of LANGS) {
      for (const vp of VIEWPORTS) {
        // A shopper's browser, not "HeadlessChrome": the shop's bot gate (BlockGate,
        // bots_leave) refuses checkout to a headless user agent, as it should.
        const ctx = await browser.newContext({ viewport: { width: vp.w, height: vp.h }, userAgent: UA });
        const page = await ctx.newPage();
        const errors = [];
        page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
        page.on('pageerror', (e) => errors.push(String(e)));

        const [number, pi] = await placeUnpaid(page, lang.prefix, flow.startsWith('tabby') ? 'tabby' : 'stripe');
        const back = flow.startsWith('tabby')
          ? `${BASE}${lang.prefix}/checkout/pending?order=${encodeURIComponent(number)}`
          : `${BASE}${lang.prefix}/checkout/success?order=${encodeURIComponent(number)}&payment_intent=${pi}&redirect_status=failed`;

        errors.length = 0;
        await page.goto(back, { waitUntil: 'load' });
        await page.waitForTimeout(300);
        const m = await measure(page);
        const file = `${TAG}-${flow}-${lang.tag}-${vp.tag}.png`;
        await page.screenshot({ path: path.join(OUT, file), fullPage: vp.tag === '390' ? false : true });

        const status = sql(`select status from orders where order_number='${number}'`);
        const cart = sql(`select status from carts order by id desc limit 1`);
        const row = { tag: TAG, flow, lang: lang.tag, vp: vp.tag, file, order: number, orderStatus: status, ...m, consoleErrors: errors };

        // Try again: a real click, and where it lands.
        if (TAG === 'after' && m.retry) {
          await Promise.all([page.waitForNavigation({ waitUntil: 'load' }), page.click('.kbb-cartpage .co-retry')]);
          row.tryAgain = { url: new URL(page.url()).pathname, status: 'ok', lines: await page.locator('.kbb-checkout .woocommerce-checkout-review-order-table .product-name, .kbb-checkout .co-line, .kbb-checkout .ln').count() };
          if (lang.tag === 'en' && vp.tag === '1280') {
            await page.screenshot({ path: path.join(OUT, `${TAG}-${flow}-try-again-checkout-en-1280.png`), fullPage: false });
          }
        }
        rows.push(row);
        console.log(JSON.stringify(row));
        await ctx.close();
      }
    }
  }

  fs.writeFileSync(path.join(OUT, `${TAG}-numbers.json`), JSON.stringify(rows, null, 2));
  await browser.close();
})().catch((e) => { console.error(e); process.exit(1); });
