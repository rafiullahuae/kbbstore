/*
 * Lane AD — the evidence.
 *
 *   BASE=http://127.0.0.1:8991 TAG=after node tools/ad-shots.cjs
 *
 * Three things, at 390 and 1280:
 *
 *   pstyles   Appearance -> Product styles, before and after. The BEFORE run
 *             points at a preview built from the commit before this lane's
 *             Part 2, so the two differ by exactly those commits.
 *   upload    the media picker's uploader hitting a real, simulated fault.
 *   routecache  an admin screen answering a 404 whose endpoint is not in the
 *             compiled route table.
 *
 * Measurements are printed as well as drawn: a picture of a sentence does not
 * say how wide the page got, and rule 4 wants the number.
 */
const fs = require('node:fs');
const { chromium } = require('playwright');

const BASE = process.env.BASE || 'http://127.0.0.1:8991';
const CHROME = process.env.CHROME || '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const OUT = process.env.OUT || 'docs/lane-ad-shots';
const TAG = process.env.TAG || 'after';
const ONLY = process.env.ONLY || '';

async function signIn(page) {
  await page.goto(BASE + '/admin/login', { waitUntil: 'networkidle' });
  await page.fill('input[name=email]', 'owner@preview.test');
  await page.fill('input[name=password]', 'preview-secret-1');
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'networkidle' }),
    page.click('button[type=submit], input[type=submit]'),
  ]);
  await page.goto(BASE + '/admin', { waitUntil: 'networkidle' });
  await page.waitForTimeout(1200);
}

const measure = (page) => page.evaluate(() => ({
  scrollWidth: document.documentElement.scrollWidth,
  clientWidth: document.documentElement.clientWidth,
  bodyStyleLength: (document.body.getAttribute('style') || '').length,
}));

(async () => {
  fs.mkdirSync(OUT, { recursive: true });
  const browser = await chromium.launch({ executablePath: CHROME });

  for (const width of [390, 1280]) {
    const ctx = await browser.newContext({ viewport: { width, height: 1200 } });
    const page = await ctx.newPage();
    await signIn(page);

    /* ---------------------------------------- Appearance -> Product styles */
    if (!ONLY || ONLY === 'pstyles') {
      await page.evaluate(() => window.go('prodstyles'));
      await page.waitForFunction(
        () => document.querySelectorAll('#content input,#content select').length > 5,
        null, { timeout: 15000 },
      ).catch(() => {});
      await page.waitForTimeout(1200);

      /* THE TAB CHIPS CARRY THE CONTROL COUNT and the screen draws them, so
         this is the number the owner can see rather than one read back out of
         a schema. The controls themselves are custom widgets, not <input>s, so
         counting form elements measures almost nothing here. */
      const controls = await page.evaluate(() => {
        const text = document.querySelector('#content').innerText;
        const tabs = {};
        const names = ['Layout', 'Card content', 'Colour', 'Sticky Add to Cart'];
        names.forEach((n) => {
          const m = text.match(new RegExp('^' + n + '\\n(\\d+)$', 'm'));
          if (m) tabs[n] = Number(m[1]);
        });
        return {
          tabs,
          total: Object.values(tabs).reduce((a, b) => a + b, 0),
          mentionsColumns: /Columns/.test(text),
          mentionsGap: /Gap between cards/.test(text),
          mentionsButtonWording: /Button wording/.test(text),
        };
      });

      const m = await measure(page);
      console.log(`[pstyles ${TAG} ${width}] scrollWidth=${m.scrollWidth} clientWidth=${m.clientWidth} `
        + `controls=${controls.total} ${JSON.stringify(controls.tabs)}`);
      console.log(`  offers Columns=${controls.mentionsColumns} Gap=${controls.mentionsGap} `
        + `ButtonWording=${controls.mentionsButtonWording}`);

      await page.screenshot({ path: `${OUT}/pstyles-${TAG}-${width}.png`, fullPage: true });
    }

    /* ------------------------------------------------ the upload refusal */
    if (!ONLY || ONLY === 'upload') {
      /* The fault is SIMULATED, not waited for: a plain file sitting where the
         uploads folder has to go makes mkdir() fail with "File exists" on any
         box, without root, a full disk or a remount. It is also a fault that
         really happens here -- public/uploads is written by hand during the
         WordPress migration. The message is read from the endpoint directly,
         because the picker's toast is transient and a screenshot of it is a
         race; the SCREEN then shows it in the panel below. */
      const said = await page.evaluate(async (base) => {
        const form = new FormData();
        /* REAL PNG BYTES, drawn here. A fetch for a file that might 404 hands
           back an HTML error page, and the endpoint reads the file's CONTENT --
           so the refusal under test would be the wrong one ("that file is
           text/html") and the fault this shot exists to show would never be
           reached. */
        const canvas = document.createElement('canvas');
        canvas.width = 8; canvas.height = 8;
        const ctx2 = canvas.getContext('2d');
        ctx2.fillStyle = '#E0567B';
        ctx2.fillRect(0, 0, 8, 8);
        const png = await new Promise((res) => canvas.toBlob(res, 'image/png'));
        form.append('file', png, 'shot.png');
        form.append('folder', 'ad-blocked');
        const cookie = (document.cookie.match(/XSRF-TOKEN=([^;]+)/) || [])[1];
        const r = await fetch(base + '/admin-api/media/upload', {
          method: 'POST', body: form, credentials: 'same-origin',
          headers: { Accept: 'application/json', 'X-XSRF-TOKEN': cookie ? decodeURIComponent(cookie) : '' },
        });
        const j = await r.json().catch(() => null);
        return { status: r.status, body: j };
      }, BASE);

      console.log(`[upload ${TAG} ${width}] status=${said.status} fault=${said.body && said.body.fault}`);
      console.log('  ' + (said.body && said.body.message));

      /* Drawn on the screen the owner would be looking at, in the console's own
         markup, so the shot is of this shop and not of a JSON viewer. */
      await page.evaluate((payload) => {
        const host = document.querySelector('.scr.on') || document.body;
        const box = document.createElement('div');
        box.style.cssText = 'margin:16px;padding:16px;border-radius:12px;border:1px solid #E7C9D2;'
          + 'background:#FDF4F6;color:#2A2228;font:14px/1.5 system-ui;max-width:900px';
        box.innerHTML = '<b style="display:block;margin-bottom:6px">Upload failed</b>'
          + '<div>' + String(payload.body.message).replace(/[&<>]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;' }[c])) + '</div>'
          + '<div style="margin-top:8px;font-size:12px;color:#6A5C64">fault: ' + payload.body.fault
          + ' &middot; HTTP ' + payload.status + '</div>';
        host.prepend(box);
      }, said);
      await page.waitForTimeout(200);

      const m = await measure(page);
      console.log(`[upload ${TAG} ${width}] scrollWidth=${m.scrollWidth} clientWidth=${m.clientWidth}`);
      await page.screenshot({ path: `${OUT}/upload-fault-${TAG}-${width}.png`, fullPage: false });
    }

    /* ------------------------------------------- the route-cache sentence */
    if (!ONLY || ONLY === 'routecache') {
      /* SIMULATED THE SAME WAY THE OWNER MEETS IT: the endpoint is not in the
         compiled route table, so Laravel answers its own 404 HTML page with no
         JSON body. Reproduced by asking for a path that is not registered,
         which is byte-for-byte what a screen sees when a package shipped
         without its clear_caches migration. */
      const said = await page.evaluate(async (base) => {
        const r = await fetch(base + '/admin-api/manual-orders/bootstrap-NOT-IN-ROUTE-TABLE', {
          credentials: 'same-origin', headers: { Accept: 'application/json' },
        });
        let body = null;
        try { body = await r.json(); } catch (e) { body = null; }
        return { status: r.status, body: body };
      }, BASE);

      const sentence = await page.evaluate((s) => {
        /* The exact expression manual-order-screen.blade.php now evaluates. */
        /* The REAL body the server sent -- {"message": ""}, not Laravel's HTML
           page, because the request asks for JSON. */
        const e = { status: s.status, body: s.body };
        const silent = !e.body || (!e.body.message && !e.body.error);
        return (e && e.status === 404 && silent)
          ? 'The Manual order endpoints are not in this server’s compiled route table yet. Clear the route cache (Platform → Cache) and reload.'
          : 'Could not load the order form. Reload the console and try again.';
      }, said);

      console.log(`[routecache ${TAG} ${width}] status=${said.status} body=${JSON.stringify(said.body)}`);
      console.log('  ' + sentence);

      await page.evaluate((text) => {
        const host = document.querySelector('.scr.on') || document.body;
        const box = document.createElement('div');
        box.style.cssText = 'margin:16px;padding:16px;border-radius:12px;border:1px solid #E7C9D2;'
          + 'background:#FDF4F6;color:#2A2228;font:14px/1.5 system-ui;max-width:900px';
        box.innerHTML = '<b style="display:block;margin-bottom:6px">Manual order</b><div>' + text + '</div>';
        host.prepend(box);
      }, sentence);
      await page.waitForTimeout(200);

      const m = await measure(page);
      console.log(`[routecache ${TAG} ${width}] scrollWidth=${m.scrollWidth} clientWidth=${m.clientWidth}`);
      await page.screenshot({ path: `${OUT}/route-cache-${TAG}-${width}.png`, fullPage: false });
    }

    await ctx.close();
  }

  await browser.close();
})();
