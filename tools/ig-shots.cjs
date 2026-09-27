/*
 * Instagram Profile — the pictures and the numbers. Lane IG, Phase 21.
 *
 *   node tools/ig-shots.cjs shop  <outDir> <label>   the section, both widths
 *   node tools/ig-shots.cjs page  <outDir>            the whole homepage
 *   node tools/ig-shots.cjs tap   <outDir>            the lightbox
 *   node tools/ig-shots.cjs admin <outDir>            Content -> Instagram
 *   node tools/ig-shots.cjs preview <outDir> <label>  the preview, and that it moves
 *   node tools/ig-shots.cjs popup   <outDir>          Configure now, in a real browser
 *
 * Chromium at deviceScaleFactor 2, which is what the rest of docs/*-shots was
 * taken at. Each invocation writes <label>.json beside its pictures and the shell
 * loop in tools/ig-shots.sh merges them.
 *
 * ── THE SETTING IS CHANGED FROM OUTSIDE, NOT FROM A QUERY STRING ────────────
 *
 * The first draft of this camera asked for `/?ig_layout=rail`, which would have
 * meant the shipped page reading a layout out of the URL. That is a setting taken
 * from a request — rule 5's "a select stores one of its own options or the default"
 * pointed at the thing that decides what to render — and it would have been in the
 * shop forever so that a screenshot was easier to take. The shell loop writes the
 * real module setting between shots instead.
 *
 * `document.documentElement.scrollWidth` is reported beside every shot, because a
 * horizontal overflow is the one layout fault a screenshot hides: the picture is
 * cropped to the viewport and looks fine.
 *
 * ── AND THE MEASURING HAPPENS HERE, NOT ON THE SHOP ─────────────────────────
 *
 * Rule 4 forbids JavaScript that measures layout IN THE SHIPPED PAGE. A camera
 * that reports the numbers is the opposite of that: it is how the calc() answers
 * get checked without the page ever asking the browser for a size.
 */
const { chromium } = require('playwright');
const fs = require('fs');
const BASE = 'http://127.0.0.1:8951';
const EXEC = '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';

const WIDTHS = [[390, 1500], [1280, 1400]];

async function measure(page) {
  return page.evaluate(() => {
    const box = (el) => el ? { w: Math.round(el.getBoundingClientRect().width), h: Math.round(el.getBoundingClientRect().height) } : null;
    const px = (el, p) => el ? Math.round(parseFloat(getComputedStyle(el)[p]) * 10) / 10 : null;
    const sec = document.querySelector('.igp');
    const track = document.querySelector('.igp-t');
    const cells = [...document.querySelectorAll('.igp-c')];
    const first = cells[0];
    return {
      viewport: document.documentElement.clientWidth,
      pageScrollWidth: document.documentElement.scrollWidth,
      horizontalOverflow: document.documentElement.scrollWidth - document.documentElement.clientWidth,
      sectionClass: track ? track.className : null,
      section: box(sec),
      tiles: cells.length,
      tile: box(first),
      tileAspect: first ? Math.round((first.getBoundingClientRect().width / first.getBoundingClientRect().height) * 100) / 100 : null,
      columns: track ? getComputedStyle(track).gridTemplateColumns : null,
      gap: track ? px(track, 'columnGap') : null,
      radius: first ? px(first, 'borderTopLeftRadius') : null,
      trackScrollWidth: track ? track.scrollWidth : null,
      trackClientWidth: track ? track.clientWidth : null,
      railOverflows: track ? track.scrollWidth > track.clientWidth : null,
      headingFontSize: px(document.querySelector('.igp-h h2'), 'fontSize'),
      profileBox: box(document.querySelector('.igp-p')),
      avatar: box(document.querySelector('.igp-p img')),
      followText: (document.querySelector('.igp-pn span') || {}).textContent?.trim() ?? null,
      followButton: (document.querySelector('.igp-pf') || {}).textContent?.trim() ?? null,
      countsDrawn: document.querySelectorAll('.igp-m').length,
      firstCount: (document.querySelector('.igp-m') || {}).innerText?.replace(/\n/g, ' ') ?? null,
      playBadges: document.querySelectorAll('.igp-c.is-video').length,
      albumMarks: document.querySelectorAll('.igp-c.is-album').length,
      stretchedLinks: document.querySelectorAll('.igp-c > .igp-lk').length,
      imgDimensioned: [...document.querySelectorAll('.igp-c > img')].every(i => i.getAttribute('width') && i.getAttribute('height')),
      imgLazy: [...document.querySelectorAll('.igp-c > img')].every(i => i.getAttribute('loading') === 'lazy'),
      iframesAtLoad: document.querySelectorAll('iframe').length,
    };
  });
}

(async () => {
  const [mode, outDir, label] = process.argv.slice(2);
  fs.mkdirSync(outDir, { recursive: true });

  const browser = await chromium.launch({ executablePath: EXEC });
  const report = {};

  if (mode === 'shop') {
    for (const [w, h] of WIDTHS) {
      const ctx = await browser.newContext({ viewport: { width: w, height: h }, deviceScaleFactor: 2 });
      const page = await ctx.newPage();
      /* Third-party requests are COUNTED rather than assumed. The section must make
         ZERO of them at page load, which is the whole of its performance claim:
         every thumbnail is a local file and the embed iframe is created on tap. */
      const thirdParty = [];
      page.on('request', r => { if (!r.url().startsWith(BASE)) thirdParty.push(r.url()); });

      await page.goto(BASE + '/', { waitUntil: 'networkidle' });
      await page.evaluate(() => document.querySelector('.igp')?.scrollIntoView({ block: 'center' }));
      await page.waitForTimeout(500);

      const m = await measure(page);
      m.thirdPartyRequests = thirdParty.length;
      m.thirdPartyHosts = [...new Set(thirdParty.map(u => { try { return new URL(u).host; } catch (e) { return u; } }))];
      report[`${label}-${w}`] = m;

      const el = await page.$('.igp');
      if (el) await el.screenshot({ path: `${outDir}/${label}-${w}.png` });
      await ctx.close();
    }
  }

  if (mode === 'page') {
    for (const [w, h] of WIDTHS) {
      const ctx = await browser.newContext({ viewport: { width: w, height: h }, deviceScaleFactor: 2 });
      const page = await ctx.newPage();
      await page.goto(BASE + '/', { waitUntil: 'networkidle' });
      await page.evaluate(() => document.querySelector('.igp')?.scrollIntoView({ block: 'center' }));
      await page.waitForTimeout(500);
      report[`homepage-${w}`] = await measure(page);
      await page.screenshot({ path: `${outDir}/homepage-in-place-${w}.png` });
      await ctx.close();
    }
  }

  if (mode === 'tap') {
    const ctx = await browser.newContext({ viewport: { width: 390, height: 1500 }, deviceScaleFactor: 2 });
    const page = await ctx.newPage();
    await page.goto(BASE + '/', { waitUntil: 'networkidle' });
    await page.evaluate(() => document.querySelector('.igp')?.scrollIntoView({ block: 'center' }));
    await page.waitForTimeout(400);
    const before = await page.evaluate(() => document.querySelectorAll('iframe').length);
    await page.evaluate(() => document.querySelector('.igp-c > .igp-lk')?.click());
    await page.waitForTimeout(700);
    report['lightbox-390'] = {
      iframesBeforeTap: before,
      iframesAfterTap: await page.evaluate(() => document.querySelectorAll('iframe').length),
      src: await page.evaluate(() => document.querySelector('.igp-box iframe')?.getAttribute('src') ?? null),
      open: await page.evaluate(() => !!document.querySelector('.igp-box.is-open')),
      sandbox: await page.evaluate(() => document.querySelector('.igp-box iframe')?.getAttribute('sandbox') ?? null),
      referrerPolicy: await page.evaluate(() => document.querySelector('.igp-box iframe')?.getAttribute('referrerpolicy') ?? null),
    };
    await page.screenshot({ path: `${outDir}/lightbox-390.png` });
    await ctx.close();
  }

  if (mode === 'admin') {
    for (const [w, h] of WIDTHS) {
      const ctx = await browser.newContext({ viewport: { width: w, height: h }, deviceScaleFactor: 2 });
      const page = await ctx.newPage();
      await page.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
      await page.fill('input[name=email]', 'owner@preview.test');
      await page.fill('input[name=password]', 'preview-secret-1');
      await Promise.all([
        page.waitForNavigation({ waitUntil: 'networkidle' }),
        page.click('button[type=submit], input[type=submit]'),
      ]);
      await page.goto(BASE + '/admin', { waitUntil: 'networkidle' });
      await page.waitForTimeout(900);
      await page.evaluate(() => window.go('instagram'));
      await page.waitForTimeout(2000);

      report[`admin-${label||'fresh'}-${w}`] = await page.evaluate(() => ({
        viewport: document.documentElement.clientWidth,
        pageScrollWidth: document.documentElement.scrollWidth,
        horizontalOverflow: document.documentElement.scrollWidth - document.documentElement.clientWidth,
        contentScrollWidth: document.querySelector('#content')?.scrollWidth ?? null,
        contentClientWidth: document.querySelector('#content')?.clientWidth ?? null,
        crumb: document.querySelector('#crumb')?.textContent ?? null,
        title: document.querySelector('#ptitle')?.textContent ?? null,
        sidebarRows: [...document.querySelectorAll('.side .nav-item')].filter(b => b.dataset.go === 'instagram').length,
        stepCount: document.querySelectorAll('.igs-step').length,
        stepStates: [...document.querySelectorAll('.igs-step')].map(s => s.className.replace('igs-step', '').trim() || 'todo'),
        redirectUri: document.querySelector('[data-igs-uri]')?.textContent ?? null,
        buttons: [...document.querySelectorAll('.igs-btn')].map(b => b.textContent.trim()),
        configureGreyedOut: !!document.querySelector('.igs-btn.is-off'),
        secretType: document.querySelector('[data-igs-secret]')?.type ?? null,
        secretPlaceholder: document.querySelector('[data-igs-secret]')?.placeholder ?? null,
        secretValueLength: (document.querySelector('[data-igs-secret]')?.value ?? '').length,
        appIdShown: document.querySelector('[data-igs-appid]')?.value ?? null,
        tabs: [...document.querySelectorAll('.igs-tab')].map(t => t.textContent.trim()),
        fieldCount: document.querySelectorAll('.igs-f').length,
        profileReadback: document.querySelector('.igs-me b')?.textContent ?? null,
        expiryNote: document.querySelector('.igs-note.is-good, .igs-note.is-warm, .igs-note.is-bad')?.innerText?.slice(0, 120) ?? null,
        /* The whole page's source, checked for the two values that must never be in
           it. A camera is the last place these could still appear. */
        leaksSecret: document.documentElement.outerHTML.includes('abcdef0123456789abcdef0123456789'),
      }));

      await page.screenshot({ path: `${outDir}/admin-connection-${label||"fresh"}-${w}.png`, fullPage: true });

      await page.evaluate(() => document.querySelector('[data-igs-tab="tile"]')?.click());
      await page.waitForTimeout(500);
      await page.screenshot({ path: `${outDir}/admin-tile-${label||"fresh"}-${w}.png`, fullPage: true });
      await ctx.close();
    }
  }

  /*
   * ── THE PREVIEW: THE NUMBERS, AND THAT IT MOVES ON INPUT ──────────────────
   *
   *   node tools/ig-shots.cjs preview <outDir> <label>
   *
   * Round 2, Lane IG. The preview is a DRAWING of the section inside the admin, so
   * the two things worth measuring are the ones a picture cannot show:
   *
   *   · that its columns, gap, radius and tile aspect are the SAME answers the
   *     section gives on the shop — the arithmetic is deliberately duplicated
   *     between InstagramSettings::cssVariables() and the screen's own CSS, and a
   *     duplicate is only safe while somebody checks it;
   *   · that it REDRAWS ON INPUT AND NOT ON SAVE. Driven by dispatching real
   *     `input` events at the controls and re-measuring with nothing saved — and
   *     the payload is read back afterwards to prove the server still holds the
   *     old values, which is what makes "not on save" an assertion rather than a
   *     claim.
   *
   * As with every other mode here the measuring happens in the CAMERA. Rule 4
   * forbids it in the shipped page, and InstagramSectionShapeTest holds the screen
   * to that by scanning it for the eight element-measuring APIs by name.
   */
  if (mode === 'preview') {
    for (const [w, h] of WIDTHS) {
      const ctx = await browser.newContext({ viewport: { width: w, height: h }, deviceScaleFactor: 2 });
      const page = await ctx.newPage();

      await page.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
      await page.fill('input[name=email]', 'owner@preview.test');
      await page.fill('input[name=password]', 'preview-secret-1');
      await Promise.all([
        page.waitForNavigation({ waitUntil: 'networkidle' }),
        page.click('button[type=submit], input[type=submit]'),
      ]);
      await page.goto(BASE + '/admin', { waitUntil: 'networkidle' });
      await page.waitForTimeout(900);
      await page.evaluate(() => window.go('instagram'));
      await page.waitForTimeout(1800);

      const readPreview = () => page.evaluate(() => {
        const box = (el) => el ? { w: Math.round(el.getBoundingClientRect().width), h: Math.round(el.getBoundingClientRect().height) } : null;
        const px = (el, p) => el ? Math.round(parseFloat(getComputedStyle(el)[p]) * 10) / 10 : null;
        const track = document.querySelector('.igs-pvt');
        const cells = [...document.querySelectorAll('.igs-pvc')];
        const first = cells[0];
        return {
          viewport: document.documentElement.clientWidth,
          pageScrollWidth: document.documentElement.scrollWidth,
          horizontalOverflow: document.documentElement.scrollWidth - document.documentElement.clientWidth,
          contentOverflow: (document.querySelector('#content')?.scrollWidth ?? 0)
            - (document.querySelector('#content')?.clientWidth ?? 0),
          previewPresent: !!document.querySelector('[data-igs-preview]'),
          frame: box(document.querySelector('.igs-pvf')),
          trackClass: track ? track.className : null,
          columns: track ? getComputedStyle(track).gridTemplateColumns : null,
          gap: track ? px(track, 'columnGap') : null,
          radius: first ? px(first, 'borderTopLeftRadius') : null,
          tiles: cells.length,
          ghosts: document.querySelectorAll('.igs-pvc.is-ghost').length,
          tile: box(first),
          tileAspect: first ? Math.round((first.getBoundingClientRect().width / first.getBoundingClientRect().height) * 100) / 100 : null,
          /* The one fault a screenshot hides: a peeking rail that widens the card
             instead of scrolling inside it. The FRAME must not overflow the page,
             and the TRACK is allowed to overflow the frame — that is the layout. */
          trackScrollWidth: track ? track.scrollWidth : null,
          trackClientWidth: track ? track.clientWidth : null,
          frameScrollWidth: document.querySelector('.igs-pvf')?.scrollWidth ?? null,
          frameClientWidth: document.querySelector('.igs-pvf')?.clientWidth ?? null,
          playBadges: document.querySelectorAll('.igs-pvc.is-video').length,
          albumMarks: document.querySelectorAll('.igs-pvc.is-album').length,
          captionsDrawn: document.querySelectorAll('.igs-pvcap').length,
          countsDrawn: document.querySelectorAll('.igs-pvm').length,
          firstCount: (document.querySelector('.igs-pvm') || {}).innerText?.replace(/\n/g, ' ') ?? null,
          profileStyle: document.querySelector('.igs-pvp')?.className ?? null,
          profileName: document.querySelector('.igs-pvn b')?.textContent ?? null,
          heading: document.querySelector('.igs-pvhd h3')?.textContent ?? null,
          headingFontSize: px(document.querySelector('.igs-pvhd h3'), 'fontSize'),
          note: document.querySelector('[data-igs-preview] .igs-help')?.innerText?.slice(0, 260) ?? null,
          widthSwitch: [...document.querySelectorAll('[data-igs-pvwidth]')].map(b => b.textContent.trim() + (b.getAttribute('aria-pressed') === 'true' ? '*' : '')),
          configureLabel: [...document.querySelectorAll('.igs-btn')].map(b => b.textContent.trim()).find(t => /Configure|Reconnect/.test(t)) ?? null,
          /* The popup wiring, as the DOM has it: the anchor is still an anchor with
             a real href, and it is the one the click handler recognises. */
          oauthTag: document.querySelector('[data-igs-oauth]')?.tagName ?? null,
          oauthHref: document.querySelector('[data-igs-oauth]')?.getAttribute('href') ?? null,
          leaksSecret: document.documentElement.outerHTML.includes('abcdef0123456789abcdef0123456789'),
          leaksToken: document.documentElement.outerHTML.includes('a-very-long-lived-token'),
        };
      });

      const settle = () => page.waitForTimeout(260);

      /* A real `input` event at a real control, which is what the screen listens
         for — not a direct call into its internals. */
      const drive = (selector, value, kind) => page.evaluate(([sel, val, k]) => {
        const el = document.querySelector(sel);
        if (!el) return false;
        if (k === 'check') { el.checked = val === '1'; }
        else { el.value = val; }
        el.dispatchEvent(new Event('input', { bubbles: true }));
        if (el.tagName === 'SELECT') el.dispatchEvent(new Event('change', { bubbles: true }));
        return true;
      }, [selector, value, kind]);

      /*
       * ── THE PICTURE IS OF THE CARD, NOT OF `fullPage` ─────────────────────
       *
       * MEASURED, and it cost this round a set of pictures: the admin console is a
       * fixed-height shell whose #content is the scroller, so `fullPage: true`
       * returns the VIEWPORT and nothing below it. At 390 the preview sits under the
       * connection wizard, off the bottom of that, and the "before" and "after"
       * shots came back BYTE-IDENTICAL — md5 for md5 — which reads as "the preview
       * does not move" and is really "the preview is not in the picture". The
       * committed 390 shots from round 1 have the same fault and the same md5.
       *
       * So: the card is shot as an ELEMENT, and the console around it is shot as a
       * viewport with the inner scroller moved to it.
       */
      const shoot = async (name) => {
        await page.evaluate(() => document.querySelector('[data-igs-preview]')
          ?.scrollIntoView({ block: 'center' }));
        await page.waitForTimeout(260);
        const el = await page.$('[data-igs-preview]');
        if (el) await el.screenshot({ path: `${outDir}/${name}-${w}.png` });
      };

      const at = {};
      at.asLoaded = await readPreview();
      await shoot(`preview-${label}`);
      /* The screen around it, so rule 3's "say where it sits" has a picture: the
         wizard, the Configure now button and the preview in one frame. */
      await page.screenshot({ path: `${outDir}/screen-${label}-${w}.png` });

      /* ── IT MOVES ON INPUT ─────────────────────────────────────────────── */
      at.drove = {
        layout: await drive('#igs-layout', 'rail'),
        gap: await drive('#igs-gap', '22'),
        radius: await drive('#igs-radius', '0'),
        posts: await drive('#igs-posts', '6'),
      };
      await settle();
      at.afterLookInput = await readPreview();

      /* The tile tab carries the caption switch, so the tab is opened the way the
         owner opens it and the switch is driven there. */
      await page.evaluate(() => document.querySelector('[data-igs-tab="tile"]')?.click());
      await settle();
      at.drove.caption = await drive('#igs-caption', '1', 'check');
      await settle();
      at.afterCaptionInput = await readPreview();
      await shoot(`preview-${label}-moved`);

      /* The Phone button, which clamps the frame and lets the @container rules do
         the rest — no JavaScript measures anything to make this work. */
      await page.evaluate(() => document.querySelector('[data-igs-pvwidth="phone"]')?.click());
      await settle();
      at.afterPhoneSwitch = await readPreview();

      /* AND NOTHING WAS SAVED. Read back from the server, which is what makes the
         two measurements above a demonstration rather than an assertion. */
      at.serverStillHolds = await page.evaluate(async () => {
        const base = window.location.pathname.replace(/\/+$/, '').replace(/\/[^\/]*$/, '') + '/admin-api';
        const r = await fetch(base + '/instagram', { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
        const j = await r.json();
        const out = {};
        (j.tabs || []).forEach(t => t.fields.forEach(f => { out[f.key] = f.value; }));
        out['__tiles'] = (j.content && j.content.tiles) ? j.content.tiles.length : 0;
        out['__connected'] = !!(j.connection && j.connection.connected);
        return out;
      });

      report[`preview-${label}-${w}`] = at;
      await ctx.close();
    }
  }

  /*
   * ── THE POPUP, DRIVEN IN A REAL BROWSER ───────────────────────────────────
   *
   *   node tools/ig-shots.cjs popup <outDir>
   *
   * Round 2, Lane IG. InstagramPreviewAndPopupTest asserts the SHAPE of this wiring
   * over the source — the origin check being the first line, the constant being the
   * whole message, preventDefault() coming after window.open. What it cannot do is
   * run it. This does, and the four things it proves are the four that would
   * otherwise only be true on the owner's shop:
   *
   *   opened        clicking Configure now opens a SECOND WINDOW and leaves the
   *                 opener where it was. That is the whole point of the change.
   *   finished      the popup, landed back on this console's own callback URL,
   *                 posts its one word, closes itself, and the opener picks the
   *                 connection up with nothing reloaded by hand.
   *   ignored       a message carrying anything but that one constant changes
   *                 nothing at all, from the same origin.
   *   fellBack      with window.open returning null — which is what a blocked
   *                 popup is — the anchor navigates as it always did.
   *
   * EGRESS IS BLOCKED IN THIS CONTAINER (docs/IG-PROFILE.md §0), so `finished`
   * drives the popup to the URL the callback REDIRECTS TO rather than through
   * Instagram. That is honest about what is being exercised: the browser half of
   * the handshake, which is this round's change. The server half — the state, the
   * exchange, the refusal — is asserted through the real route in
   * InstagramPreviewAndPopupTest and InstagramProfileTest.
   */
  if (mode === 'popup') {
    const ctx = await browser.newContext({ viewport: { width: 1280, height: 1400 }, deviceScaleFactor: 2 });
    const page = await ctx.newPage();

    await page.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
    await page.fill('input[name=email]', 'owner@preview.test');
    await page.fill('input[name=password]', 'preview-secret-1');
    await Promise.all([
      page.waitForNavigation({ waitUntil: 'networkidle' }),
      page.click('button[type=submit], input[type=submit]'),
    ]);
    await page.goto(BASE + '/admin', { waitUntil: 'networkidle' });
    await page.waitForTimeout(900);
    await page.evaluate(() => window.go('instagram'));
    await page.waitForTimeout(1800);

    const banner = () => page.evaluate(() => {
      const n = document.querySelector('.igs-wrap > .igs-note');
      return n ? { klass: n.className, text: n.innerText.slice(0, 200) } : null;
    });

    const out = { openerUrlBefore: page.url(), pagesBefore: ctx.pages().length };

    /* THE CLICK, AND THE SECOND WINDOW. */
    const [popup] = await Promise.all([
      page.waitForEvent('popup', { timeout: 8000 }).catch(() => null),
      page.click('[data-igs-oauth]'),
    ]);

    await page.waitForTimeout(400);
    out.opened = {
      gotPopup: !!popup,
      pagesAfter: ctx.pages().length,
      /* The opener must NOT have navigated. This is the assertion the old anchor
         could never pass, because it took the whole page with it. */
      openerStillHere: page.url() === out.openerUrlBefore,
      openerBanner: await banner(),
      popupUrl: popup ? popup.url() : null,
    };

    /*
     * THE WAY BACK, AND WHY IT IS A SECOND window.open RATHER THAN A goto ──────
     *
     * The popup above is on a Chromium network-error page, because /start redirected
     * it to instagram.com and egress is blocked here. Driving THAT window back with
     * goto() does not exercise the real thing: a failed cross-origin navigation puts
     * the window in another process and the `opener` handle does not survive it, so
     * the popup half finds no opener and correctly posts nothing. That is an artefact
     * of the container, not a defect — measured, and it is why this leg opens the
     * window fresh, at the URL InstagramController::screenUrl() redirects to, under
     * THE SAME WINDOW NAME the feature uses. Which is the state a real popup is in at
     * the moment it comes back from Instagram.
     */
    if (popup && !popup.isClosed()) { await popup.close().catch(() => {}); }

    const [returned] = await Promise.all([
      page.waitForEvent('popup', { timeout: 8000 }).catch(() => null),
      page.evaluate((u) => window.open(u, 'kbb-instagram-oauth', 'width=620,height=780'),
        BASE + '/admin?ig_done=Connected+to+Instagram.#instagram'),
    ]);

    out.finished = { gotPopup: !!returned };

    if (returned) {
      out.finished.popupClosedItself = await returned.waitForEvent('close', { timeout: 10000 })
        .then(() => true).catch(() => false);
      await page.waitForTimeout(2500);
      out.finished.openerBanner = await banner();
      out.finished.openerStillHere = page.url() === out.openerUrlBefore;
      out.finished.pagesAfter = ctx.pages().length;
      await page.screenshot({ path: `${outDir}/popup-returned-1280.png`, fullPage: true });
    }

    /*
     * ── ABANDONED, AND THE TIMER THAT MUST NOT SURVIVE IT ─────────────────────
     *
     * There is no event for "the owner closed the popup", so the screen keeps one
     * interval looking — and the brief's rule is that nothing is left running once
     * the popup is gone. That is measured rather than asserted: setInterval and
     * clearInterval are counted from the HARNESS SIDE (never in the shipped page,
     * which measures nothing), the popup is closed from outside as an owner would
     * close it, and the count has to come back to zero.
     */
    await page.evaluate(() => {
      window.__liveTimers = 0;
      const si = window.setInterval, ci = window.clearInterval;
      window.setInterval = function () { window.__liveTimers++; return si.apply(window, arguments); };
      window.clearInterval = function () { window.__liveTimers--; return ci.apply(window, arguments); };
    });

    const [abandoned] = await Promise.all([
      page.waitForEvent('popup', { timeout: 8000 }).catch(() => null),
      page.click('[data-igs-oauth]'),
    ]);

    out.abandoned = {
      gotPopup: !!abandoned,
      timersWhileOpen: await page.evaluate(() => window.__liveTimers),
    };

    if (abandoned) { await abandoned.close().catch(() => {}); }
    await page.waitForTimeout(3000);

    out.abandoned.timersAfterClose = await page.evaluate(() => window.__liveTimers);
    out.abandoned.openerBanner = await banner();
    out.abandoned.pages = ctx.pages().length;

    /* A MESSAGE THAT IS NOT THE WORD CHANGES NOTHING, from our own origin. */
    const before = await banner();
    await page.evaluate(() => {
      window.postMessage('kbb-instagram-oauth-done-but-not-really', window.location.origin);
      window.postMessage({ connected: true, token: 'pretend' }, window.location.origin);
    });
    await page.waitForTimeout(1200);
    out.ignored = { bannerBefore: before, bannerAfter: await banner() };

    /* AND THE BLOCKED-POPUP FALLBACK. window.open returning null is exactly what a
       blocked popup is, so the anchor must navigate. */
    await page.goto(BASE + '/admin', { waitUntil: 'networkidle' });
    await page.waitForTimeout(900);
    await page.evaluate(() => window.go('instagram'));
    await page.waitForTimeout(1800);
    await page.evaluate(() => { window.open = function () { return null; }; });

    const asked = [];
    page.on('request', r => { if (r.isNavigationRequest()) asked.push(r.url()); });
    await page.click('[data-igs-oauth]').catch(() => {});
    await page.waitForTimeout(2500);

    out.fellBack = {
      navigatedTo: asked.filter(u => u.includes('/admin-api/instagram/start')),
      /* No second window was opened, because window.open answered null. */
      pages: ctx.pages().length,
    };

    report['popup-1280'] = out;
    await ctx.close();
  }

  fs.writeFileSync(`${outDir}/part-${mode}-${label || 'x'}.json`, JSON.stringify(report, null, 1));
  console.log(JSON.stringify(report, null, 1));
  await browser.close();
})();
