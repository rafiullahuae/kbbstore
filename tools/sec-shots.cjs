/*
 * The console with a DEAD SESSION, photographed.                     (Lane SEC)
 *
 *   SEC_BASE=http://127.0.0.1:8977 SEC_LABEL=after node tools/sec-shots.cjs
 *
 * Run it TWICE, on the same preview, against the two trees:
 *
 *   SEC_LABEL=before   a clean checkout -- what the owner gets today
 *   SEC_LABEL=after    with tools/sec-apply-blocks.py applied
 *
 * WHAT IT PROVES. Nine addresses in this console are reached by NAVIGATING the
 * browser at them rather than by fetch. An admin-api address outside the secret
 * admin path answers a signed-out browser with a plain 404, so with an expired
 * session:
 *
 *   BEFORE, an export navigates the whole console away to that 404 -- the
 *   #nav sidebar is GONE from the document, and so is the list the owner was
 *   standing on. An order document opens a tab holding the same 404 and the
 *   console behind it says nothing at all.
 *
 *   AFTER, the console is still there, #nav is still there, and the modal on
 *   top of it says the session has ended and offers the sign-in.
 *
 * THE SESSION IS KILLED THE WAY IT DIES IN REAL LIFE: the session cookie is
 * dropped from the browser context, which is what an expired session looks like
 * to the next request. Nothing is stubbed and no response is intercepted.
 *
 * The measurements are taken from the document rather than claimed: whether
 * #nav still exists, how many rows the list still has, what the modal says, and
 * documentElement.scrollWidth against the viewport at both widths.
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const CHROME = '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const BASE = process.env.SEC_BASE || 'http://127.0.0.1:8977';
const LABEL = process.env.SEC_LABEL || 'after';
/* The preview pins this; see tools/sec-preview.sh. A NON-DEFAULT admin path is
   the shop this lane is photographing. */
const ADMIN = process.env.SEC_ADMIN || 'sec-console';
const APP = path.resolve(__dirname, '..');
const OUT = process.env.SEC_OUT || `${APP}/docs/sec-shots`;

const rows = [];

function measure() {
  return {
    viewport: document.documentElement.clientWidth,
    scrollWidth: document.documentElement.scrollWidth,
    // THE CONSOLE ITSELF. Gone means the navigation took the screen away.
    consoleOnScreen: !!document.getElementById('nav'),
    sidebarRows: document.querySelectorAll('#nav [data-go]').length,
    pageTitle: (document.getElementById('ptitle') || {}).textContent || null,
    tableRows: document.querySelectorAll('#content table tbody tr').length,
    // What, if anything, the console said.
    modalOpen: !!(document.getElementById('modalBg') || {}).classList
      && document.getElementById('modalBg').classList.contains('on'),
    modalHeading: (document.querySelector('#modal .modal-h b') || {}).textContent || null,
    modalBody: ((document.querySelector('#modal .modal-b') || {}).textContent || '')
      .replace(/\s+/g, ' ').trim().slice(0, 220) || null,
    signInButton: !!document.getElementById('kbbSessionSignIn'),
    toastShown: !!(document.getElementById('toast') || {}).classList
      && document.getElementById('toast').classList.contains('show'),
    toastText: ((document.getElementById('toast') || {}).textContent || '').trim() || null,
    // The 404 the browser lands on has a body and no console in it.
    bodyStart: (document.body.innerText || '').replace(/\s+/g, ' ').trim().slice(0, 90),
  };
}

async function shoot(page, name, w) {
  await page.setViewportSize({ width: w, height: w === 390 ? 844 : 1000 });
  /* TO THE TOP FIRST. These screens say what happened in a banner at the top of
     #content, and a viewport screenshot taken where the button was photographs
     the button and not the answer -- which is how the first Instagram shot came
     out showing "Configure now" and no sentence.

     EVERY SCROLLED ELEMENT, not window. This console scrolls an inner
     container, so window.scrollTo(0, 0) moved nothing at all and the second
     attempt came out identical to the first. */
  await page.evaluate(() => {
    window.scrollTo(0, 0);
    document.querySelectorAll('*').forEach((el) => { if (el.scrollTop) { el.scrollTop = 0; } });
  });
  await page.waitForTimeout(600);
  const m = await page.evaluate(measure);
  const file = `${OUT}/${LABEL}-${name}-${w}.png`;
  await page.screenshot({ path: file, fullPage: false });
  rows.push({ shot: `${LABEL}-${name}-${w}`, ...m });
  console.log(JSON.stringify({ shot: `${LABEL}-${name}-${w}`, ...m }));
}

async function signIn(ctx) {
  const page = await ctx.newPage();
  page.on('pageerror', (e) => console.log(JSON.stringify({ pageError: String(e) })));
  await page.goto(`${BASE}/${ADMIN}/login`, { waitUntil: 'networkidle' });
  await page.fill('input[name=email]', 'owner@preview.test');
  await page.fill('input[name=password]', 'preview-secret-1');
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'networkidle' }),
    page.click('button[type=submit], input[type=submit]'),
  ]);
  return page;
}

/* An expired session, exactly as the next request sees one. */
async function killSession(ctx) {
  const keep = (await ctx.cookies()).filter((c) => !/session/i.test(c.name));
  await ctx.clearCookies();
  await ctx.addCookies(keep);
  console.log(JSON.stringify({ sessionKilled: true, cookiesLeft: keep.map((c) => c.name) }));
}

(async () => {
  fs.mkdirSync(OUT, { recursive: true });

  const browser = await chromium.launch({ executablePath: CHROME });

  // ---------------------------------------------------------------- 1. EXPORT
  for (const w of [1280, 390]) {
    const ctx = await browser.newContext({ viewport: { width: w, height: w === 390 ? 844 : 1000 } });
    const page = await signIn(ctx);

    await page.goto(`${BASE}/${ADMIN}?go=orders`, { waitUntil: 'networkidle' });
    await page.waitForTimeout(1500);
    await shoot(page, 'orders-live', w);

    await killSession(ctx);

    await page.click('#olExport');
    await page.waitForTimeout(2500);
    await shoot(page, 'orders-export-expired', w);

    await ctx.close();
  }

  // ------------------------------------------------------- 2. ORDER DOCUMENT
  for (const w of [1280, 390]) {
    const ctx = await browser.newContext({ viewport: { width: w, height: w === 390 ? 844 : 1000 } });
    const page = await signIn(ctx);

    await page.goto(`${BASE}/${ADMIN}?go=orders`, { waitUntil: 'networkidle' });
    await page.waitForTimeout(1500);

    // Into the order detail card, where the four document buttons are drawn.
    await page.click('#content [data-olview]');
    await page.waitForTimeout(1800);
    await shoot(page, 'order-detail-live', w);

    await killSession(ctx);

    const before = ctx.pages().length;
    await page.click('#content [data-oddoc]');
    await page.waitForTimeout(2800);
    console.log(JSON.stringify({ tabsOpenedByTheDocumentButton: ctx.pages().length - before }));
    await shoot(page, 'order-document-expired', w);

    await ctx.close();
  }

  // --------------------------------------- 3. THE CATALOG LIST'S OWN MESSAGE
  //
  // Not a navigation: cpLoad()'s catch printed ONE sentence for every refusal,
  // and for an expired session that sentence sends the owner to Store -> Cache
  // to clear a route cache that is perfectly healthy. The session is killed and
  // the screen is asked to load its list again, which is what the owner's next
  // filter or page click would do.
  for (const w of [1280, 390]) {
    const ctx = await browser.newContext({ viewport: { width: w, height: w === 390 ? 844 : 1000 } });
    const page = await signIn(ctx);

    await page.goto(`${BASE}/${ADMIN}?go=catalog`, { waitUntil: 'networkidle' });
    await page.waitForTimeout(2000);
    await shoot(page, 'catalog-live', w);

    await killSession(ctx);

    // What a filter change, a page click or a status edit already does.
    await page.evaluate(() => window.catProducts && window.catProducts());
    await page.waitForTimeout(2000);

    const said = await page.evaluate(() => {
      const card = document.querySelector('#catBody .card');
      return card ? card.innerText.replace(/\s+/g, ' ').trim() : null;
    });
    console.log(JSON.stringify({ catalogSaid: said }));

    await shoot(page, 'catalog-expired', w);
    await ctx.close();
  }

  // ------------------------- 4. THE TWO NAVIGATIONS THAT LIVE IN PARTIALS
  //
  // Round 4. Neither is in admin/app.blade.php, which is why three scans of
  // that file never found the Reviews.io one at all.
  for (const w of [1280, 390]) {
    const ctx = await browser.newContext({ viewport: { width: w, height: w === 390 ? 844 : 1000 } });
    const page = await signIn(ctx);

    // ── Reviews -> Reviews.io -> Export. `window.location.href`, so the whole
    //    console goes with it.
    await page.goto(`${BASE}/${ADMIN}?go=rev-io`, { waitUntil: 'networkidle' });
    await page.waitForTimeout(2200);
    await shoot(page, 'reviewsio-live', w);

    await killSession(ctx);

    await page.click('#rio-export');
    await page.waitForTimeout(2500);
    console.log(JSON.stringify({
      reviewsIoSaid: await page.evaluate(() => {
        const b = document.querySelector('#content .rio-banner, #content [class*="banner"], #content .rio-note');
        return b ? b.innerText.replace(/\s+/g, ' ').trim().slice(0, 200) : (document.body.innerText || '').replace(/\s+/g, ' ').trim().slice(0, 120);
      }),
    }));
    await shoot(page, 'reviewsio-export-expired', w);

    await ctx.close();
  }

  // ── The Instagram handshake, both ways ────────────────────────────────────
  for (const w of [1280, 390]) {
    const ctx = await browser.newContext({ viewport: { width: w, height: w === 390 ? 844 : 1000 } });
    const page = await signIn(ctx);

    await page.goto(`${BASE}/${ADMIN}?go=instagram`, { waitUntil: 'networkidle' });
    await page.waitForTimeout(2200);

    /*
     * THE BLOCKED-POPUP FALLBACK. A real popup blocker cannot be turned on for
     * one click from here, so window.open is stubbed to return null -- which is
     * exactly what a blocker makes it do, and it is the ONLY thing the handler
     * branches on. Nothing else is stubbed and no response is intercepted.
     */
    await page.evaluate(() => { window.open = function () { return null; }; });
    await killSession(ctx);

    const before = ctx.pages().length;
    const link = await page.$('[data-igs-oauth]');
    if (link) { await link.click(); } else { console.log(JSON.stringify({ oauthLink: 'not on screen - app id and secret not saved' })); }
    await page.waitForTimeout(2600);

    console.log(JSON.stringify({
      width: w,
      instagramTabsOpened: ctx.pages().length - before,
      instagramStillOnConsole: await page.evaluate(() => !!document.getElementById('nav')),
      instagramSaid: await page.evaluate(() => {
        const b = document.querySelector('#content .igs-note');
        return b ? b.innerText.replace(/\s+/g, ' ').trim().slice(0, 200) : (document.body.innerText || '').replace(/\s+/g, ' ').trim().slice(0, 120);
      }),
    }));
    await shoot(page, 'instagram-fallback-expired', w);

    await ctx.close();
  }

  // -------------------------------------------- 5. WHAT SIGN OUT ACTUALLY DOES
  //
  // Not part of the gate, and found while measuring it: the top-bar Sign out
  // posts to a LITERAL '/admin/logout' and then navigates to a LITERAL
  // '/admin/login'. Both are dead on any shop whose admin path is not the
  // default -- routes/web.php answers `/admin/{any?}` with abort(404) precisely
  // so the old address cannot announce the new one. Measured here rather than
  // argued: what the network did, and whether the session survived it.
  {
    const ctx = await browser.newContext({ viewport: { width: 1280, height: 1000 } });
    const page = await signIn(ctx);
    const seen = [];
    page.on('request', (r) => { if (/logout|login/.test(r.url())) seen.push(r.method() + ' ' + r.url()); });
    page.on('response', (r) => { if (/logout|login/.test(r.url())) seen.push('  -> ' + r.status() + ' ' + r.url()); });

    await page.goto(`${BASE}/${ADMIN}`, { waitUntil: 'networkidle' });
    await page.waitForTimeout(1200);
    /* .click() ON THE ELEMENT, not a synthesised pointer. The preview's
       'PHASE 0 · FOUNDATION PREVIEW' flag sits over the top bar and takes the
       pointer, and even with force: true Playwright's click landed somewhere
       that never reached the handler -- the first run of this reported NO
       requests at all and an unchanged URL, which looked like a third bug and
       was the harness. A DOM click dispatches a real trusted-shaped event
       through the real handler and skips the hit-testing only. */
    await page.evaluate(() => document.getElementById('kbbSignout').click());
    await page.waitForTimeout(2500);

    const cookiesAfter = (await ctx.cookies()).map((c) => c.name);
    // Is the session really gone? Ask the server, not the cookie jar.
    const still = await page.evaluate(async (base) => {
      const r = await fetch(base + '/admin-api/stats', { credentials: 'same-origin', headers: { Accept: 'application/json' } });
      return r.status;
    }, BASE);

    console.log(JSON.stringify({
      signOutNetwork: seen,
      landedOn: page.url(),
      cookiesAfter,
      statsStatusAfterSignOut: still,
      stillSignedIn: still === 200,
    }));
    await page.screenshot({ path: `${OUT}/${LABEL}-signout-landing-1280.png` });
    await ctx.close();
  }

  await browser.close();

  fs.writeFileSync(`${OUT}/${LABEL}-measurements.json`, JSON.stringify(rows, null, 2));
  console.log('wrote ' + OUT + '/' + LABEL + '-measurements.json');
})();
