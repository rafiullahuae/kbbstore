/*
 * Lane PLC — the Place-order overlay, photographed in every state it has.
 *
 * Boot the preview first:   ./tools/plc-preview.sh 8981
 * Then:                     node tools/plc-shots.cjs 8981
 *
 * ── HOW EACH STATE IS REACHED, AND WHICH ONES ARE REAL ─────────────────────
 *
 * The three states the SERVER decides are reached for real: the resting
 * checkout, the refusal (the preview's Tamara credentials are fake, so
 * TamaraGateway::start() genuinely fails and place() genuinely refuses), and
 * the return-after-decline, which is the real controller answering the real
 * address the provider sends a declined shopper to.
 *
 * The three the BROWSER decides are reached by intercepting POST /checkout/place
 * and answering with each of the shapes place() really returns — a request that
 * never comes back (the working state), `action: placed` (the tick), and
 * `action: redirect` (leaving for Tamara). This is a photograph of the branch,
 * not a claim that the branch fired: tests/Feature/CheckoutPlacingOverlayTest
 * is what asserts the wiring.
 *
 * NOTHING MEASURES LAYOUT IN THE PAGE'S OWN CODE. This file does — it is the
 * instrument, not the shop — and what it reads is what the brief asks for:
 * document.documentElement.scrollWidth at every shot, the overlay card's box,
 * and the wall-clock from the press to the tick.
 */
const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright');

const PORT = process.argv[2] || '8981';
const BASE = `http://127.0.0.1:${PORT}`;
const OUT = path.join(__dirname, '..', 'docs', 'PLC-overlay-shots');
const CHROME = process.env.CHROME || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';

const VIEWPORTS = [
  { tag: '390', w: 390, h: 844 },
  { tag: '1280', w: 1280, h: 900 },
];

const rows = [];

/** The most recent order the preview wrote, straight out of its SQLite file. */
function lastOrderNumber() {
  const db = path.join(__dirname, '..', 'storage', 'framework', 'testing', 'lane-plc-preview', 'preview.sqlite');
  const php = `$d=new PDO("sqlite:${db}");foreach($d->query("select order_number from orders order by id desc limit 1") as $r){echo $r[0];}`;
  try {
    return require('child_process').execFileSync('php', ['-r', php], { encoding: 'utf8' }).trim();
  } catch (e) {
    return '';
  }
}

/** What the brief asks for at every shot. */
async function measure(page, key, vp, extra = {}) {
  const m = await page.evaluate(() => {
    const box = document.querySelector('.kbb-placing-card');
    const overlay = document.querySelector('.kbb-placing');
    const cs = box ? getComputedStyle(box) : null;
    const os = overlay ? getComputedStyle(overlay) : null;
    const r = box ? box.getBoundingClientRect() : null;
    return {
      scrollWidth: document.documentElement.scrollWidth,
      clientWidth: document.documentElement.clientWidth,
      dir: document.documentElement.getAttribute('dir') || 'ltr',
      lang: document.documentElement.getAttribute('lang') || '',
      card: r ? { w: Math.round(r.width), h: Math.round(r.height), x: Math.round(r.left) } : null,
      cardFont: cs ? getComputedStyle(document.querySelector('.kbb-placing-title')).fontSize : null,
      overlayBg: os ? os.backgroundColor : null,
      backdrop: os ? (os.backdropFilter || os.webkitBackdropFilter || 'none') : null,
      title: document.querySelector('.kbb-placing-title')?.textContent || null,
      note: document.querySelector('.kbb-placing-note')?.textContent || null,
      live: document.querySelector('.kbb-placing-sr')?.textContent || null,
      done: !!document.querySelector('.kbb-placing.is-done'),
      /* The freeze, asserted rather than assumed: the checkout must be inert
         while the overlay is up, and must not be once it is down. */
      /* THE FREEZE. `inert` goes on every child of <body> except the overlay,
         and on this layout the checkout lives inside <main> — so the count is
         what to read, not one element. 0 while the overlay is down. */
      inert: document.querySelectorAll('body > [inert]').length,
      buttonsDisabled: Array.from(document.querySelectorAll('[data-place]')).map((b) => b.disabled),
      notice: document.querySelector('#kbbPlacingNotice .co-note')?.textContent || null,
      /* The refusal band's own colours, because "the overlay came down and the
         reason is on the page" is only half the claim — the other half is that
         it READS as a refusal. `.co-note.err` had no rule at all until this
         round and computed identically to a neutral note. */
      noticeStyle: (() => {
        const el = document.querySelector('#kbbPlacingNotice .co-note')
          || document.querySelector('.kbb-checkout .co-note.err')
          /* The BASKET page draws the same band from its own rules — the
             partial ships them inline, because kbb-checkout.css is not loaded
             on /cart/. Read it here so the two are comparable. */
          || document.querySelector('.kbb-cartpage .co-note.err')
          || document.querySelector('.kbb-cartpage .co-note.ok');
        if (!el) return null;
        const cs = getComputedStyle(el);
        return { bg: cs.backgroundColor, color: cs.color, border: cs.borderTopColor, weight: cs.fontWeight };
      })(),
      focus: document.activeElement ? (document.activeElement.className || document.activeElement.tagName) : null,
      /* THE WAY BACK, counted rather than eyeballed. The offer is drawn only
         when pressing it would work, so 0 and 1 are both meaningful states and
         the shots have to say which one they are. */
      restoreButtons: document.querySelectorAll('.co-restore').length,
      restoreBox: (() => {
        const b = document.querySelector('.co-restore');
        if (!b) return null;
        const r = b.getBoundingClientRect();
        const cs = getComputedStyle(b);
        return { w: Math.round(r.width), h: Math.round(r.height), font: cs.fontSize, bg: cs.backgroundColor };
      })(),
      bandText: document.querySelector('.kbb-cartpage .co-note')?.textContent.trim().slice(0, 90) || null,
    };
  });

  rows.push({ shot: key, width: vp.tag, ...m, ...extra });
  console.log(
    `${key.padEnd(26)} ${vp.tag.padStart(4)}  sw=${m.scrollWidth}/${m.clientWidth} dir=${m.dir}` +
    ` card=${m.card ? m.card.w + 'x' + m.card.h : '-'} done=${m.done} inert=${m.inert}` +
    ` btn=[${m.buttonsDisabled}] "${(m.title || m.notice || '').slice(0, 40)}"`
  );
  return m;
}

async function shot(page, key, vp, extra) {
  fs.mkdirSync(OUT, { recursive: true });
  const m = await measure(page, key, vp, extra);
  /*
   * TWO GOES, and the second one freezes the animations.
   *
   * Capture hung repeatedly on the 1280 refusal shot — "fonts loaded" and then
   * nothing — while the same state at 390 captured instantly. The page has an
   * infinite CSS animation on it (the free-delivery bar) and the compositor
   * does not always settle for the capture. The retry pins every animation at
   * its first frame, which is a faithful picture of a state that is static
   * anyway, and it is only reached when the ordinary capture has already
   * failed.
   */
  const file = path.join(OUT, `plc-${key}-${vp.tag}.png`);

  await page.waitForLoadState('load').catch(() => {});

  try {
    await page.screenshot({ path: file, fullPage: false, timeout: 20000 });
    return m;
  } catch (e) {
    console.log('  (retrying ' + key + ' ' + vp.tag + ' with animations frozen)');
  }

  try {
    await page.screenshot({ path: file, fullPage: false, timeout: 20000, animations: 'disabled' });
    return m;
  } catch (e) {
    console.log('  (falling back to CDP for ' + key + ' ' + vp.tag + ')');
  }

  /*
   * LAST RESORT: the browser's own capture, through CDP, with none of
   * Playwright's stabilisation in front of it.
   *
   * Playwright waits for fonts and for the compositor to settle before it
   * captures, and on this box that wait occasionally never ends on a page with
   * an infinite CSS animation on it — twice on the reduced-motion shot, which
   * is the one shot where a missing picture would be read as "they did not do
   * it". Page.captureScreenshot asks for the frame that is on screen and
   * returns it. Same pixels, no waiting.
   */
  const cdp = await page.context().newCDPSession(page);
  const { data } = await cdp.send('Page.captureScreenshot', { format: 'png' });
  fs.writeFileSync(file, Buffer.from(data, 'base64'));
  await cdp.detach().catch(() => {});
  return m;
}

/** Fill the three visible required fields so :invalid is empty. */
async function fillForm(page) {
  await page.fill('#billing_first_name', 'Aisha Khan');
  await page.fill('#billing_phone', '+971500000000');
  await page.fill('#billing_email', 'buyer@preview.test');
  /* The address lands in HIDDEN inputs, which constraint validation never
     looks at — see the note in store/checkout.blade.php. Filled here so the
     REAL place() below has something to validate. */
  await page.evaluate(() => {
    const set = (id, v) => { const el = document.getElementById(id); if (el) el.value = v; };
    set('billing_address_1', '12 Marina Walk');
    set('billing_city', 'Dubai');
    set('billing_state', 'Dubai');
    set('billing_country', 'AE');
  });
}

async function openCheckout(ctx, prefix) {
  const page = await ctx.newPage();
  await page.goto(BASE + prefix + '/product/plc-glass-skin-serum/', { waitUntil: 'domcontentloaded' });

  /*
   * THE BAG, BY PRESSING THE SHOP'S OWN BUTTON.
   *
   * Not by POSTing /api/cart/add with a product id: the demo content this
   * preview migrates in seeds two dozen products of its own, so this lane's is
   * not id 1 — and id 1 happens to be `outofstock`, so a hardcoded id answered
   * 422 "That product is sold out", which reads exactly like a broken harness.
   * /api/search deliberately does not publish ids (see Product::toApi()), so
   * there is nothing to look one up with either.
   *
   * Pressing #mainAdd is both simpler and more faithful: it is what a shopper
   * does, and it carries the cookie and the CSRF token the shop issued.
   */
  await page.click('#mainAdd');
  await page.waitForTimeout(700);

  const resp = await page.goto(BASE + prefix + '/checkout', { waitUntil: 'domcontentloaded' });

  if (!resp || resp.status() !== 200) {
    throw new Error('checkout answered ' + (resp ? resp.status() : 'nothing') + ' — the preview is not serving this lane\'s fixture');
  }

  const form = await page.$('#kbbCheckoutForm');
  if (!form) throw new Error('no checkout form on the page — wrong server?');

  return page;
}

/** Arm the click and report the milliseconds from press to tick. */
async function pressAndTime(page) {
  await page.evaluate(() => {
    window.__plc = { pressed: 0, tick: 0 };
    new MutationObserver(() => {
      if (!window.__plc.tick && document.querySelector('.kbb-placing.is-done')) {
        window.__plc.tick = performance.now();
      }
    }).observe(document.body, { subtree: true, childList: true, attributes: true, attributeFilter: ['class'] });
  });

  await page.evaluate(() => {
    window.__plc.pressed = performance.now();
    document.querySelector('.kbb-mobile-order [data-place], [data-place]').click();
  });
}

/*
 * ONE BLOCK'S FAILURE MUST NOT COST THE OTHER TWELVE.
 *
 * A run once died on the eleventh shot of the second viewport with "Target
 * page, context or browser has been closed", and took the four shots after it
 * with it — so the evidence folder was silently short and the console had
 * scrolled past the reason. Each block is now its own attempt, and the exit
 * code says whether anything failed.
 */
let failures = 0;

async function step(name, fn) {
  try {
    await fn();
  } catch (e) {
    failures += 1;
    console.log('  !! ' + name + ' failed: ' + (e && e.message ? e.message.split('\n')[0] : e));
  }
}

(async () => {
  const browser = await chromium.launch({ executablePath: CHROME });

  for (const vp of VIEWPORTS) {
    /* ─────────────────────────── the ordinary shopper ─────────────────────── */
    await step('block@' + vp.tag, async () => {
      const ctx = await browser.newContext({ viewport: { width: vp.w, height: vp.h } });
      const page = await openCheckout(ctx, '');
      await fillForm(page);
      await shot(page, 'checkout-resting', vp);

      /* 1. THE WORKING STATE. The request is taken and never answered, which is
            exactly what a slow server looks like. */
      await page.route('**/checkout/place', () => { /* held open on purpose */ });
      await pressAndTime(page);
      await page.waitForSelector('.kbb-placing.is-up', { timeout: 4000 });
      await page.waitForTimeout(1200);
      await shot(page, 'overlay-working', vp);

      /* The no-backdrop-filter fallback, which is what a browser that cannot
         blur renders: the @supports block simply does not apply to it. */
      await page.addStyleTag({ content: '.kbb-placing{-webkit-backdrop-filter:none!important;backdrop-filter:none!important;background:rgba(255,248,245,.95)!important}' });
      await page.waitForTimeout(150);
      await shot(page, 'overlay-no-backdrop', vp);

      await ctx.close();
    });

    /* 2. THE TICK. place() answers `placed`, which is the cash-on-delivery
          journey and the one the owner watches most. */
    await step('block@' + vp.tag, async () => {
      const ctx = await browser.newContext({ viewport: { width: vp.w, height: vp.h } });
      const page = await openCheckout(ctx, '');
      await fillForm(page);
      /* THE NAVIGATION IS HELD, so the tick can be photographed finished.
         confirmed() schedules it 900ms after the tick starts — deliberately, so
         the animation can never hold up an order — and that is less time than a
         measure and a capture take at 1280. Holding the request keeps the page
         where it is without touching the 900ms, which is the number that
         matters and must not be tuned for a screenshot. */
      await page.route('**/checkout/success**', () => { /* held open */ });
      await page.route('**/checkout/place', (route) => route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ ok: true, action: 'placed', order: 'PLCPAID01', success_url: '/checkout/success?order=PLCPAID01' }),
      }));
      await pressAndTime(page);
      await page.waitForSelector('.kbb-placing.is-done', { timeout: 5000 });
      const timing = await page.evaluate(() => Math.round(window.__plc.tick - window.__plc.pressed));
      await page.waitForTimeout(700);
      await shot(page, 'overlay-tick', vp, { pressToTickMs: timing });
      await ctx.close();
    });

    /* 3. LEAVING FOR TAMARA. place() answers `redirect`; the navigation itself
          is aborted so the card can be photographed. */
    await step('block@' + vp.tag, async () => {
      const ctx = await browser.newContext({ viewport: { width: vp.w, height: vp.h } });
      const page = await openCheckout(ctx, '');
      await fillForm(page);
      /* THE LABEL, NOT THE RADIO. The radios on this list are visually hidden
         and styled through their <label>, so Playwright's check() fails the
         actionability test on them and a swallowed failure left the shot
         reading "Taking you to Cash on delivery…" — which is a picture of the
         wrong branch, and exactly the kind of thing a screenshot is supposed to
         catch rather than commit. */
      await page.click('label[for="payment_method_tamara"]');
      /*
       * THE NAVIGATION IS HELD OPEN, NOT ABORTED.
       *
       * Aborting it let the browser land on Chrome's own "This site can't be
       * reached" page between the measurement and the capture, so the 1280 shot
       * was a picture of an error page rather than of the leaving card — which
       * is precisely the kind of thing a screenshot is for, and precisely the
       * kind of thing that gets committed if nobody opens the file. A request
       * that never answers keeps the browser on the page it is leaving, which
       * is also what a slow provider looks like.
       */
      await page.route('**/checkout.tamara.co/**', () => { /* held open */ });
      await page.route('**/checkout/place', (route) => route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ ok: true, action: 'redirect', url: 'https://checkout.tamara.co/preview-not-real' }),
      }));
      await pressAndTime(page);
      await page.waitForSelector('.kbb-placing.is-up', { timeout: 4000 });
      await page.waitForTimeout(300);
      await shot(page, 'overlay-leaving', vp);
      await ctx.close();
    });

    /* 4. A REAL REFUSAL. Nothing is intercepted: Tamara is chosen, the preview's
          credentials are fake, TamaraGateway::start() fails and place() answers
          422 with the sentence the gateway wrote. The overlay must be GONE and
          the reason on the page. */
    await step('block@' + vp.tag, async () => {
      const ctx = await browser.newContext({ viewport: { width: vp.w, height: vp.h } });
      const page = await openCheckout(ctx, '');
      await fillForm(page);
      await page.click('label[for="payment_method_tamara"]');
      await pressAndTime(page);
      await page.waitForSelector('#kbbPlacingNotice', { timeout: 30000 });
      await page.waitForTimeout(400);
      await shot(page, 'failure-real-refusal', vp);

      /* 5. THE RETURN AFTER A DECLINE, at the real address the provider sends a
            declined shopper to, answered by the real controller. */
      /*
       * THE RETURN AFTER A DECLINE, at the real address the provider sends a
       * declined shopper to, answered by the real controller — and for the
       * order this browser has just genuinely failed to place, which is what
       * put `kbb_last_order` in its session.
       *
       * The number is read out of the preview's own database rather than
       * invented, because inventing one would exercise the "not this session's"
       * branch and photograph the generic sentence instead of the real one.
       */
      const order = lastOrderNumber();

      if (order) {
        await page.goto(BASE + '/checkout/pending?order=' + order, { waitUntil: 'domcontentloaded' });
        await page.waitForTimeout(250);
        await shot(page, 'return-after-decline', vp, { order });

        /*
         * AND THE WAY OUT OF IT. The same real order, the same real session:
         * the button is offered because this browser placed it, it is unpaid,
         * and its basket is sitting `converted`. Pressing it is the whole of
         * round 2 — the basket comes back, the order goes, and the page they
         * land on is the one App\Services\SetStockReconciler runs on.
         */
        /*
         * ▲ THE SAME PAGE AGAIN, WHICH IS WHERE THE WAY BACK USED TO VANISH.
         *
         * The reason is FLASHED and the offer is a SESSION VALUE, and the
         * partial's outer gate asked only about the flash — so the second GET
         * of /cart/ drew no band and no button while the offer was still live
         * and the basket still sitting `converted`. Probed in the suite before
         * the fix: offer set, first render button true, RELOAD FALSE. This is
         * the shot of the reload, and `restoreButtons` in the measurements is
         * the number that says it is there.
         */
        await page.goto(BASE + '/cart/', { waitUntil: 'domcontentloaded' });
        await page.waitForTimeout(250);
        await shot(page, 'return-reload-keeps-the-way-back', vp, { order });

        /*
         * THE SECOND TAB IS OPENED NOW, BEFORE ANY PRESS, because that is what
         * makes it stale. Opened after the press it would simply re-render with
         * the offer gone and draw no button — which is the shop being right and
         * proves nothing about a tab that has been sitting open.
         */
        const staleTab = await ctx.newPage();
        await staleTab.goto(BASE + '/cart/', { waitUntil: 'domcontentloaded' });
        await staleTab.waitForTimeout(200);

        const button = await page.$('.co-restore');

        if (button) {
          await button.click();
          await page.waitForTimeout(600);
          await shot(page, 'basket-restored', vp, { order });

          /*
           * AND THE STALE TAB — A SECOND REAL TAB, NOT THE BACK BUTTON.
           *
           * Back was tried first and is the wrong instrument: Playwright's
           * goBack re-requests the page, so the server re-renders /cart/ with
           * the offer already consumed and there is no button to press. The run
           * said so — "the back page had no button" — which is the shop being
           * right, not the state being unreachable.
           *
           * A stale tab does not re-request anything. It is holding a DOM that
           * was true when it was drawn, with the shop's own form and the
           * session's own token still in it. So: two tabs on the basket page,
           * press in the first, then press the one that has been sitting there.
           * Same context, same cookies, same session.
           */
          const stale = await staleTab.$('.co-restore');

          if (stale) {
            await stale.click();
            await staleTab.waitForTimeout(700);
            await shot(staleTab, 'restore-pressed-twice', vp, { order });
          } else {
            console.log('  (the second tab drew no button — the offer was already gone)');
          }

          await staleTab.close();
        } else {
          console.log('  (no restore button on the page — the offer was not written)');
        }
      } else {
        console.log('  (no order row to return from — the refusal did not reach place())');
      }
      await ctx.close();
    });

    /* 5b. THE BAND WITH NO WAY BACK UNDER IT — the basket page's other state,
           and the one a control that does nothing would ruin.

           ▲ ITS OWN CONTEXT, AND THAT IS THE POINT RATHER THAN TIDINESS. This
           was first written as two more navigations inside the step above: a
           bogus order number, then the real one again to re-arm. The re-arm
           printed "(no restore button on the page — the offer was not
           written)" twice over and cost this lane a wrong diagnosis before the
           right one — because BOTH halves are the shop behaving correctly. A
           number this session did not place takes pending()'s generic branch
           and rememberRestorable() drops the offer, which it should; and after
           /cart/ has re-cookied the browser there is no live cookie left that
           names the `converted` basket, so nothing can be re-derived. The
           offer is deliberately not resurrectable from a stranger's number.

           A fresh context has no `kbb_last_order` at all, which is the state of
           anybody who opens this address cold. `restoreButtons` 0 beside the
           same band is the measurement. */
    await step('block@' + vp.tag, async () => {
      const ctx = await browser.newContext({ viewport: { width: vp.w, height: vp.h } });
      const page = await ctx.newPage();
      await page.goto(BASE + '/checkout/pending?order=KBB-NOT-THIS-SESSION', { waitUntil: 'domcontentloaded' });
      await page.waitForTimeout(250);
      await shot(page, 'return-band-without-a-way-back', vp);
      await ctx.close();
    });

    /* 6. THE RETURN LEG, on the order-received page. Signed in as the customer
          who owns the seeded Tamara orders, which is the other of mayView()'s
          two doors; `confirming=1` is the marker the page's own single refresh
          carries, and it is what puts this visit in the arrival state. */
    await step('block@' + vp.tag, async () => {
      const ctx = await browser.newContext({ viewport: { width: vp.w, height: vp.h } });
      const page = await ctx.newPage();
      await page.goto(BASE + '/my-account', { waitUntil: 'domcontentloaded' });
      await page.fill('input[name="email"]', 'buyer@preview.test').catch(() => {});
      await page.fill('input[name="password"]', 'preview-secret-1').catch(() => {});
      await page.click('button[type="submit"]').catch(() => {});
      await page.waitForTimeout(600);

      /* 800, not 350. The check stroke is drawn over 360ms after a 180ms
         delay, so a shot at 350 catches a green badge with half a tick in it —
         which is a different symbol, not a slower one. Comfortably before the
         card's own 1.25s self-dismissal. */
      await page.goto(BASE + '/checkout/success?order=PLCPAID01&confirming=1', { waitUntil: 'domcontentloaded' });
      await page.waitForTimeout(800);
      await shot(page, 'return-tick', vp);

      await page.goto(BASE + '/checkout/success?order=PLCWAIT01&confirming=1', { waitUntil: 'domcontentloaded' });
      await page.waitForTimeout(350);
      await shot(page, 'return-confirming', vp);
      await ctx.close();
    });

    /*
     * 5b. NO ANSWER AT ALL — the 45-second abort, which is the one state in the
     *     whole feature that cannot be produced against a live server.
     *
     * It is produced here with NOTHING ON THE SERVER SIDE AT ALL: the request
     * is taken by the browser's own interception and never answered, which is
     * exactly what a hung connection is, and the overlay's own AbortController
     * fires its 45-second timer against it. No test-only route, no sleeping
     * endpoint, no flag — so there is nothing that could reach a package,
     * because nothing was added to the application to keep out of one.
     *
     * The sentence it produces is the highest-stakes one in the feature: it
     * says the order may already have been placed, and it deliberately does NOT
     * invite a retry, because the request was sent.
     */
    await step('timeout@' + vp.tag, async () => {
      const ctx = await browser.newContext({ viewport: { width: vp.w, height: vp.h } });
      const page = await openCheckout(ctx, '');
      await fillForm(page);
      await page.route('**/checkout/place', () => { /* taken and never answered */ });
      await pressAndTime(page);
      await page.waitForSelector('.kbb-placing.is-up', { timeout: 4000 });

      /* ABORT_MS is 45000 in partials/checkout/placing-overlay. Waited out
         rather than shortened: a shot of a timer that is not the shipped one is
         a shot of something the shopper will never see. */
      const armed = Date.now();
      await page.waitForSelector('#kbbPlacingNotice', { timeout: 70000 });
      const waited = Date.now() - armed;

      await page.waitForTimeout(400);
      await shot(page, 'timeout-no-answer', vp, { abortWaitedMs: waited });
      await ctx.close();
    });

    /*
     * 6b. THE WHOLE HAPPY PATH, FOR REAL, AND THE ONLY HONEST PRESS-TO-TICK
     *     FIGURE IN THIS FILE.
     *
     * Nothing intercepted: cash on delivery, a real POST to a real place(),
     * a real order written to the database, and the tick drawn on the answer.
     * The figures from the intercepted shots above measure this overlay's own
     * work and nothing else, because the answer was already in the browser.
     */
    await step('block@' + vp.tag, async () => {
      const ctx = await browser.newContext({ viewport: { width: vp.w, height: vp.h } });
      const page = await openCheckout(ctx, '');
      await fillForm(page);

      /*
       * THE NAVIGATION IS DELAYED, NOT HELD AND THEN UNROUTED.
       *
       * The first version held /checkout/success open, photographed the tick,
       * then called unroute() and drove the same address by hand. unroute()
       * does not resolve a request Playwright has ALREADY intercepted, so the
       * page was left with a navigation pending and the goto() that followed
       * deadlocked behind it — a run sat on that line for nineteen minutes and
       * produced half an evidence folder. Delaying the same request instead
       * leaves confirmed()'s own navigation to complete on its own: nothing is
       * driven by hand, and there is nothing to un-hold.
       */
      await page.route('**/checkout/success**', async (route) => {
        await new Promise((r) => setTimeout(r, 1600));
        await route.continue();
      });

      await pressAndTime(page);
      await page.waitForSelector('.kbb-placing.is-done', { timeout: 30000 });
      const timing = await page.evaluate(() => Math.round(window.__plc.tick - window.__plc.pressed));
      await page.waitForTimeout(700);
      await shot(page, 'real-cod-tick', vp, { pressToTickMs: timing, intercepted: false });

      /* And it arrives at the order-received page the owner already has, by
         the navigation confirmed() scheduled and nothing else. */
      await page.waitForURL('**/checkout/success**', { timeout: 20000 });
      await page.waitForTimeout(500);
      await shot(page, 'real-cod-received', vp, { intercepted: false });
      await ctx.close();
    });

    /* 7. REDUCED MOTION. Same press, same state, nothing that moves. */
    await step('block@' + vp.tag, async () => {
      const ctx = await browser.newContext({ viewport: { width: vp.w, height: vp.h }, reducedMotion: 'reduce' });
      const page = await openCheckout(ctx, '');
      await fillForm(page);
      await page.route('**/checkout/success**', () => { /* held, as above */ });
      await page.route('**/checkout/place', (route) => route.fulfill({
        status: 200, contentType: 'application/json',
        body: JSON.stringify({ ok: true, action: 'placed', order: 'PLCPAID01', success_url: '/checkout/success?order=PLCPAID01' }),
      }));
      await pressAndTime(page);
      await page.waitForSelector('.kbb-placing.is-done', { timeout: 5000 });
      /* 700, not 250. At 250 the first version of this shot caught the check
         stroke half drawn — which is what sent the reduced-motion rules back
         for a rethink, and is why the wait is comfortably past every transition
         on the card rather than just past most of them. */
      await page.waitForTimeout(700);
      await shot(page, 'reduced-motion-tick', vp, {
        pressToTickMs: await page.evaluate(() => Math.round(window.__plc.tick - window.__plc.pressed)),
      });
      await ctx.close();
    });

    /* 8. ARABIC. Right to left, same card, no [dir] selector behind it. */
    await step('block@' + vp.tag, async () => {
      const ctx = await browser.newContext({ viewport: { width: vp.w, height: vp.h } });
      const page = await openCheckout(ctx, '/ar');
      await fillForm(page);
      await page.route('**/checkout/place', () => { /* held open */ });
      await pressAndTime(page);
      await page.waitForSelector('.kbb-placing.is-up', { timeout: 4000 });
      await page.waitForTimeout(900);
      await shot(page, 'arabic-working', vp);
      await ctx.close();
    });
  }

  await browser.close();
  fs.mkdirSync(OUT, { recursive: true });
  fs.writeFileSync(path.join(OUT, 'plc-measurements.json'), JSON.stringify(rows, null, 2));
  console.log('\nwrote ' + rows.length + ' rows to ' + path.join(OUT, 'plc-measurements.json'));

  if (failures) {
    console.log(failures + ' block(s) failed — the evidence folder is short.');
    process.exit(1);
  }
})().catch((e) => { console.error(e); process.exit(1); });
