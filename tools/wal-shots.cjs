/*
 * Lane WAL screenshots and measurements — Apple Pay and Google Pay.
 *
 *   node tools/wal-shots.cjs <out-dir> <width> <height>
 *
 * Shoots, at one real viewport, in this order:
 *
 *   1. /checkout with both wallets SWITCHED OFF in the admin — the row is not
 *      rendered at all, which is the state this package ships in;
 *   2. /checkout with both wallets ON and js.stripe.com unreachable (egress is
 *      blocked in this sandbox, which is exactly the failure being
 *      photographed) — the row removes itself rather than leaving a button
 *      that cannot work;
 *   3. /checkout with both wallets ON and Stripe.js STUBBED to report NO
 *      available wallet — the canMakePayment() === false branch, removed;
 *   4. /checkout with both wallets ON and Stripe.js STUBBED to report Apple Pay
 *      and Google Pay available — the row present, in place, with its divider;
 *   5. Store → Payments → Credit or debit card, where the two switches and the
 *      Apple Pay domain box live;
 *   6. the footer of the homepage with the wallets on and again with them off.
 *
 * ── WHAT THE STUB IS AND WHAT IT IS NOT ────────────────────────────────────
 *
 * Shots 4 and 3 install a fake `window.Stripe` before the page loads. It is
 * NOT a picture of Stripe's real Apple Pay button: no wallet exists in this
 * container, no Apple hardware exists, and every Stripe host is unreachable
 * from here. What it proves is the half that is ours — that the page reveals
 * the row on `ready` with an available method, removes it on `ready` without
 * one, and leaves the payment list below untouched either way. The real button
 * is Stripe's own artwork and appears the moment a shopper with a wallet opens
 * the page. Filenames say `stub` so nobody mistakes one for the other.
 *
 * Every number printed is read in the browser from the finished page.
 * CLAUDE.md forbids the SHOP from measuring its own layout in JavaScript; a
 * camera is allowed to.
 */
const { chromium } = require('playwright');
const fs = require('fs');

const BASE = process.env.WAL_BASE || 'http://127.0.0.1:8994';

/* The fake Stripe. Two shapes: with wallets and without. */
function stripeStub(withWallets) {
  return `(function () {
    window.Stripe = function () {
      return {
        elements: function () {
          return {
            /*
             * ONLY THE EXPRESS ELEMENT DRAWS BUTTONS.
             *
             * partials/checkout/stripe-elements creates three CARD elements
             * from its own elements() group, and a stub that answered every
             * create() with a pair of wallet buttons painted them into the card
             * number, expiry and CVC boxes as well — a picture of a checkout
             * that has never existed. The type is honoured so the card fields
             * photograph as the empty mounts they are when Stripe is
             * unreachable.
             */
            create: function (type) {
              var handlers = {};
              var express = type === 'expressCheckout';
              return {
                on: function (name, fn) { handlers[name] = fn; return this; },
                mount: function (node) {
                  /* Stripe draws its own buttons inside an iframe. The stub
                     draws two plain buttons so the ROW's geometry is real —
                     its height, its gap and the divider under it are ours. */
                  node.innerHTML = express ? ${withWallets
                    ? "'<div style=\"display:grid;grid-template-columns:1fr 1fr;gap:10px\">'"
                    + " + '<button type=\"button\" style=\"height:48px;border-radius:11px;border:0;background:#000;color:#fff;font-weight:700;font-size:15px\">\\uf8ff&nbsp;Pay</button>'"
                    + " + '<button type=\"button\" style=\"height:48px;border-radius:11px;border:1.5px solid #dadce0;background:#fff;color:#3c4043;font-weight:700;font-size:15px\">G Pay</button>'"
                    + " + '</div>'"
                    : "''"} : '';
                  setTimeout(function () {
                    if (express && handlers.ready) {
                      handlers.ready({ availablePaymentMethods: ${withWallets ? '{ applePay: true, googlePay: true }' : 'undefined'} });
                    }
                  }, 10);
                },
                update: function () {},
              };
            },
            update: function () {},
            submit: function () { return Promise.resolve({}); },
          };
        },
        confirmPayment: function () { return Promise.resolve({ paymentIntent: { status: 'succeeded' } }); },
      };
    };
  })();`;
}

async function login(page) {
  await page.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
  await page.fill('input[name=email]', 'owner@preview.test');
  await page.fill('input[name=password]', 'preview-secret-1');
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'networkidle' }),
    page.click('button[type=submit], input[type=submit]'),
  ]);
}

/** Switch both wallets on or off through the same endpoint the admin uses. */
async function setWallets(page, on) {
  return page.evaluate(async (value) => {
    /* window.KBB.csrf, NOT a <meta> tag — this storefront publishes the token
       to the front end as JSON and has no csrf-token meta at all. Reading a
       meta that is not there sends an empty token and every POST is a 419,
       which reads exactly like "the admin endpoint is broken". */
    const token = (window.KBB && window.KBB.csrf) || '';
    const r = await fetch('/admin-api/payments', {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': token,
        'X-Requested-With': 'XMLHttpRequest',
        Accept: 'application/json',
      },
      body: JSON.stringify({
        id: 'stripe',
        enabled: true,
        settings: { wallet_apple_pay: value, wallet_google_pay: value },
      }),
    });
    return { status: r.status, body: await r.text() };
  }, on ? '1' : '');
}

/**
 * A basket, put there the way a shopper puts one there.
 *
 * The real Add to cart button, not a hand-rolled fetch: the point of a
 * screenshot harness is that the state it photographs is a state the shop can
 * actually get into. /checkout bounces to /cart when the basket is empty, so a
 * failure here is silent and produces a picture of the wrong page.
 */
async function fillBasket(page) {
  await page.goto(BASE + '/product/cellmazing-fit-serum', { waitUntil: 'networkidle' });
  await page.click('#mainAdd');
  await page.waitForTimeout(1200);

  return page.evaluate(() => ({ count: (window.KBB && window.KBB.cartCount) ?? null }));
}

/** Everything worth writing down about one rendered checkout. */
async function measure(page) {
  return page.evaluate(() => {
    const row = document.querySelector('[data-kbb-express]');
    const divider = document.querySelector('[data-kbb-express-divider]');
    const list = document.querySelector('#payment .payment_methods');

    const box = (el) => {
      if (!el) return null;
      const r = el.getBoundingClientRect();
      return { w: Math.round(r.width), h: Math.round(r.height) };
    };

    return {
      scrollWidth: document.documentElement.scrollWidth,
      clientWidth: document.documentElement.clientWidth,
      /* Which language and which direction this page actually rendered in.
         An "Arabic" screenshot that is quietly the English page is the easiest
         wrong evidence there is to produce, so the picture carries the proof
         beside it rather than relying on the filename. (Lane WAL2) */
      lang: document.documentElement.getAttribute('lang'),
      dir: document.documentElement.getAttribute('dir') || 'ltr',
      expressRowInDom: !!row,
      expressRowVisible: !!row && !row.hidden,
      dividerInDom: !!divider,
      expressRow: box(row),
      paymentList: box(list),
      paymentOptions: document.querySelectorAll('#payment .wc_payment_method').length,
      deadButtons: document.querySelectorAll('.xbtn.xapple, .xbtn.xgoogle').length,
      footerChips: Array.from(document.querySelectorAll('.fpay span')).map((s) => s.textContent.trim()),
    };
  });
}

(async () => {
  const [out, w, h] = process.argv.slice(2);
  fs.mkdirSync(out, { recursive: true });

  const browser = await chromium.launch({
    executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
  });

  const report = { viewport: Number(w), base: BASE, shots: {} };

  /*
   * `locale` is '' for English and 'ar' for Arabic. The shop serves Arabic at
   * /ar/... and English unprefixed — SetLocaleFromPath strips the segment
   * rather than declaring a Route::prefix('ar') — so the ONLY difference
   * between an English and an Arabic shot is this prefix on the final goto.
   * Everything before it (the login, the wallet switch, the basket) is
   * language-independent and is deliberately done unprefixed, exactly as a
   * shopper who switched language at the checkout would have done it.
   */
  const shoot = async (name, { stub, wallets, locale = '' }) => {
    const ctx = await browser.newContext({
      viewport: { width: +w, height: +h },
      deviceScaleFactor: 2,
    });
    const page = await ctx.newPage();

    await login(page);
    await page.goto(BASE + '/', { waitUntil: 'networkidle' });
    await setWallets(page, wallets);

    if (stub !== null) {
      await ctx.addInitScript(stripeStub(stub));
    }

    await fillBasket(page);

    const prefix = locale ? '/' + locale : '';

    await page.goto(BASE + prefix + '/checkout', { waitUntil: 'networkidle' });
    // The row is revealed (or removed) by Stripe's `ready`, and by the five
    // second give-up when Stripe.js never arrives. Wait past both.
    await page.waitForTimeout(stub === null ? 6500 : 900);

    const m = await measure(page);
    report.shots[name] = m;

    await page.screenshot({ path: `${out}/${name}-${w}.png`, fullPage: false });

    // The payment step on its own, which is what anybody reviewing this wants
    // to look at.
    const sec = await page.$('.sec.pay');
    if (sec) await sec.screenshot({ path: `${out}/${name}-paystep-${w}.png` });

    await ctx.close();
  };

  await shoot('checkout-wallets-off', { stub: null, wallets: false });
  await shoot('checkout-wallets-on-no-stripe', { stub: null, wallets: true });
  await shoot('checkout-stub-no-wallet', { stub: false, wallets: true });
  await shoot('checkout-stub-wallets', { stub: true, wallets: true });

  /* ------------------------------------------------- the Arabic pair (WAL2) */
  await shoot('checkout-ar-stub-wallets', { stub: true, wallets: true, locale: 'ar' });
  await shoot('checkout-ar-wallets-off', { stub: null, wallets: false, locale: 'ar' });

  /* ------------------------------------------------------- the admin screen */
  {
    const ctx = await browser.newContext({ viewport: { width: +w, height: +h }, deviceScaleFactor: 2 });
    const page = await ctx.newPage();
    await login(page);
    await setWallets(page, true);
    await page.goto(BASE + '/admin?go=payments', { waitUntil: 'networkidle' });
    /* The console is a single page app. `?go=payments` is the entry its own
       deep-link handler reads (admin/app.blade.php) and window.go() is the
       fallback once it has booted; the screen then fetches /admin-api/payments
       and paints, which is why the wait is on the CARD APPEARING rather than on
       a timer — a timer that was 300ms short photographed the dashboard and
       reported "no wallet switches" for a screen that has two. */
    await page.evaluate(() => { try { if (typeof window.go === 'function') window.go('payments'); } catch (e) {} });

    /* ONE GATEWAY AT A TIME. The screen is a tab bar over four cards and only
       the selected card is visible, so the Stripe card has to be SELECTED
       before it can be photographed — waiting for it to appear on its own times
       out against a card that is in the document and hidden, which is what it
       did. */
    await page.waitForSelector('[data-paytab="stripe"]', { timeout: 20000 });
    await page.click('[data-paytab="stripe"]');
    await page.waitForSelector('[data-paycard="stripe"]', { state: 'visible', timeout: 20000 });
    await page.waitForTimeout(1200);

    const card = await page.$('[data-paycard="stripe"]');
    if (card) {
      await card.scrollIntoViewIfNeeded();
      await page.waitForTimeout(400);
      await card.screenshot({ path: `${out}/admin-payments-stripe-${w}.png` });
    }

    /*
     * AND THE COLUMN THE TWO SWITCHES ARE ACTUALLY IN.
     *
     * The whole gateway card is several thousand pixels tall and the controls
     * this round added are at the bottom of its second column — a picture of
     * the card is a picture of the keys. `.paysec.is-set` is "How this shop
     * uses it", which is the exact place named in the report and in
     * docs/WALLETS-APPLE-GOOGLE-PAY.md.
     */
    const settings = await page.$('[data-paycard="stripe"] .paysec.is-set');
    if (settings) {
      await settings.scrollIntoViewIfNeeded();
      await page.waitForTimeout(400);
      await settings.screenshot({ path: `${out}/admin-wallet-switches-${w}.png` });
    }
    await page.screenshot({ path: `${out}/admin-payments-${w}.png`, fullPage: false });

    report.shots['admin-payments'] = await page.evaluate(() => ({
      scrollWidth: document.documentElement.scrollWidth,
      walletLabels: Array.from(document.querySelectorAll('[data-paycard="stripe"] label'))
        .map((l) => l.textContent.trim())
        .filter((t) => /Apple Pay|Google Pay|domain file/i.test(t)),
      walletSelects: Array.from(document.querySelectorAll('[data-paycard="stripe"] [data-payf]'))
        .map((el) => ({ field: el.getAttribute('data-payf'), value: el.value })),
    }));
    await ctx.close();
  }

  /* --------------------------------------------------------- the footer row */
  for (const wallets of [true, false]) {
    const ctx = await browser.newContext({ viewport: { width: +w, height: +h }, deviceScaleFactor: 2 });
    const page = await ctx.newPage();
    await login(page);
    await page.goto(BASE + '/', { waitUntil: 'networkidle' });
    await setWallets(page, wallets);
    await page.goto(BASE + '/', { waitUntil: 'networkidle' });

    const name = wallets ? 'footer-wallets-on' : 'footer-wallets-off';
    const row = await page.$('.fpay');
    if (row) {
      await row.scrollIntoViewIfNeeded();
      await page.waitForTimeout(200);
      await row.screenshot({ path: `${out}/${name}-${w}.png` });
    }

    report.shots[name] = await page.evaluate(() => ({
      scrollWidth: document.documentElement.scrollWidth,
      chips: Array.from(document.querySelectorAll('.fpay span')).map((s) => s.textContent.trim()),
    }));
    await ctx.close();
  }

  await browser.close();

  fs.writeFileSync(`${out}/measurements-${w}.json`, JSON.stringify(report, null, 2));
  console.log(JSON.stringify(report, null, 2));
})();
