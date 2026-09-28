/*
 * Lane SF evidence. Chromium at 390 and 1280, and the measured numbers under
 * every shot.
 *
 * The pinned headless-shell build is not in this container, so the browser is
 * the full Chromium at the path below — `npx playwright install` is forbidden
 * here.
 *
 * ── WHAT IS PHOTOGRAPHED ───────────────────────────────────────────────────
 *
 *   design-<key>-3    the three-member set, in each of the four designs
 *   design-<key>-12   the twelve-member set, in each of the four designs
 *   design-<key>-rtl  the three-member set, mirrored
 *   unpriced-<key>    a set with no price, which must not claim a saving
 *   bundle-after      a set page WITHOUT the quantity-bundle strip (this lane)
 *   bundle-before     the same page WITH it (tools/sf-shoot.sh disables the
 *                     guard for one pass, so the removal is a before and an
 *                     after rather than an assertion)
 *   plain-product     an ordinary product, whose strip must be untouched
 *   admin-screen      Appearance → Set contents, with its four live previews
 *
 * ── HOW THE DESIGN IS SWITCHED ─────────────────────────────────────────────
 *
 * Through THIS LANE'S OWN ENDPOINT — the script signs in as the preview owner
 * and POSTs /admin-api/set-contents, which is what the screen does. Writing the
 * settings row directly would have photographed four designs while proving
 * nothing about the thing that selects them, and would have left the settings
 * cache holding the old value.
 *
 * ── EVERY NUMBER UNDER EVERY SHOT IS READ FROM THE PAGE ────────────────────
 *
 * viewport, scrollWidth, whether the document overflows sideways, how many
 * members are drawn, how many are LINKS and how many are not, and the footing's
 * own words. That last one is why "must not claim a saving" is checkable from
 * the log rather than from squinting at a picture.
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const BASE = process.env.SF_BASE || 'http://127.0.0.1:8994';
const APP = path.resolve(__dirname, '..');
const OUT = process.env.SF_OUT || `${APP}/docs/lane-sf-shots`;
const ONLY = process.env.SF_ONLY || '';

const SET3 = 'lanesf-glow-starter-set';
const SET12 = 'lanesf-full-routine-set';
const UNPRICED = 'lanesf-unpriced-set';
const PLAIN = 'lanesf-plain-moisturiser';

const DESIGNS = ['grid', 'list', 'cards', 'stack'];

/* Each design's own member element and its own name element, so the counts
   below are of the design that is actually on the page rather than of whatever
   happens to carry a class the grid also uses. */
const MARKS = {
  grid: { member: '.ksp-m', link: 'a.ksp-nm', plain: 'span.ksp-nm' },
  list: { member: '.ksl-r', link: 'a.ksl-nm', plain: 'span.ksl-nm' },
  cards: { member: '.ksc-c', link: 'a.ksc-nm', plain: 'span.ksc-nm' },
  stack: { member: '.kss-i', link: 'a.kss-nm', plain: 'span.kss-nm' },
};

const log = [];

async function shoot(page, name, w, h, marks, extra) {
  await page.setViewportSize({ width: w, height: h });
  await page.waitForTimeout(400);

  const m = await page.evaluate((sel) => ({
    viewport: document.documentElement.clientWidth,
    scrollWidth: document.documentElement.scrollWidth,
    /* THE NUMBER THAT MATTERS AT 390. scrollWidth greater than clientWidth is
       a page with a horizontal scrollbar, which is the defect every width
       measurement in this repository is really about. */
    overflows: document.documentElement.scrollWidth > document.documentElement.clientWidth,
    members: sel ? document.querySelectorAll(sel.member).length : null,
    linked: sel ? document.querySelectorAll(sel.link).length : null,
    unlinked: sel ? document.querySelectorAll(sel.plain).length : null,
    footing: document.querySelector('.ksp-foot')?.innerText.replace(/\n/g, ' | ') ?? null,
    // The quantity-bundle strip, by the words it actually prints.
    bundleStrip: document.body.innerText.includes('2-pack bundle')
      || document.body.innerText.includes('3-pack bundle'),
    bundleRows: [...document.querySelectorAll('#variants .variant .vn')].map((n) => n.textContent.trim()),
  }), marks || null);

  await page.screenshot({ path: `${OUT}/${name}-${w}.png`, fullPage: true });

  const row = { shot: `${name}-${w}`, ...m, ...(extra || {}) };
  log.push(row);
  console.log(JSON.stringify(row));
}

async function signIn(page) {
  await page.goto(`${BASE}/admin/login`, { waitUntil: 'networkidle' });
  await page.fill('input[name=email]', 'owner@preview.test');
  await page.fill('input[name=password]', 'preview-secret-1');
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'networkidle' }),
    page.click('button[type=submit], input[type=submit]'),
  ]);
  await page.goto(`${BASE}/admin`, { waitUntil: 'networkidle' });
  await page.waitForTimeout(900);
}

/* Set the live design the way the screen does: a POST to this lane's own
   endpoint, from an authenticated console page, carrying the console's own
   XSRF cookie. */
async function choose(page, design) {
  await page.goto(`${BASE}/admin`, { waitUntil: 'networkidle' });

  const result = await page.evaluate(async (d) => {
    const cookie = (n) => {
      const m = document.cookie.match('(^|;)\\s*' + n + '\\s*=\\s*([^;]+)');
      return m ? decodeURIComponent(m.pop()) : '';
    };

    const r = await fetch('/admin-api/set-contents', {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        'X-XSRF-TOKEN': cookie('XSRF-TOKEN'),
      },
      body: JSON.stringify({ design: d }),
    });

    return { status: r.status, body: await r.json().catch(() => null) };
  }, design);

  if (result.status !== 200 || !result.body || result.body.current !== design) {
    throw new Error(`could not select design ${design}: ${JSON.stringify(result)}`);
  }

  console.log(JSON.stringify({ chose: design, via: 'POST /admin-api/set-contents', status: result.status }));
}

(async () => {
  fs.mkdirSync(OUT, { recursive: true });

  const browser = await chromium.launch({
    executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
  });
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 1400 }, deviceScaleFactor: 2 });
  const page = await ctx.newPage();
  page.on('dialog', (d) => d.accept());

  /* ── the bundle strip, before and after ─────────────────────────────── */

  if (ONLY === '' || ONLY === 'bundle') {
    // SF_BUNDLE names the shot, so tools/sf-shoot.sh can run this script twice
    // — once with the guard in place and once with it disabled — and get a
    // real before and a real after of the same page.
    const tag = process.env.SF_BUNDLE || 'bundle-after';

    await page.goto(`${BASE}/product/${SET3}/`, { waitUntil: 'networkidle' });
    for (const w of [390, 1280]) await shoot(page, tag, w, 1800, MARKS.grid);
  }

  if (ONLY === '' || ONLY === 'plain') {
    /* THE CONTROL. An ordinary product's page must be exactly where it was —
       strip included. This is the shot that says so. */
    await page.goto(`${BASE}/product/${PLAIN}/`, { waitUntil: 'networkidle' });
    for (const w of [390, 1280]) await shoot(page, 'plain-product', w, 1800, null);
  }

  /* ── the four designs ───────────────────────────────────────────────── */

  if (ONLY === '' || ONLY === 'designs') {
    await signIn(page);

    for (const design of DESIGNS) {
      await choose(page, design);

      await page.goto(`${BASE}/product/${SET3}/`, { waitUntil: 'networkidle' });
      for (const w of [390, 1280]) await shoot(page, `design-${design}-3`, w, 2000, MARKS[design]);

      await page.goto(`${BASE}/product/${SET12}/`, { waitUntil: 'networkidle' });
      for (const w of [390, 1280]) await shoot(page, `design-${design}-12`, w, 2600, MARKS[design]);

      /* AN UNPRICED SET. `footing` in the log is the assertion: it must read
         "Bought separately ... | Set price AED 0" and NOTHING about saving. */
      await page.goto(`${BASE}/product/${UNPRICED}/`, { waitUntil: 'networkidle' });
      await shoot(page, `unpriced-${design}`, 1280, 1600, MARKS[design]);

      /* ARABIC. The storefront is bilingual and a design that only holds up
         one way round is not finished. `?lang=ar` is how this shop switches. */
      await page.goto(`${BASE}/ar/product/${SET3}/`, { waitUntil: 'networkidle' }).catch(() => {});
      const isRtl = await page.evaluate(() => document.documentElement.getAttribute('dir') === 'rtl');
      if (isRtl) {
        for (const w of [390, 1280]) await shoot(page, `design-${design}-rtl`, w, 2000, MARKS[design], { rtl: true });
      }
    }

    // Leave the shop on the design it ships as, so a preview looked at later
    // is the shipped state rather than whatever was photographed last.
    await choose(page, 'grid');
  }

  /* ── Appearance → Set contents ──────────────────────────────────────── */

  if (ONLY === '' || ONLY === 'admin') {
    await signIn(page);
    await page.setViewportSize({ width: 1280, height: 2200 });
    await page.evaluate(() => window.go('setcontents'));
    // The screen asks the server for four renders when it opens.
    await page.waitForTimeout(4500);
    for (const w of [390, 1280]) await shoot(page, 'admin-screen', w, 2400, null);
  }

  fs.writeFileSync(`${OUT}/measurements${ONLY ? '-' + ONLY : ''}.json`, JSON.stringify(log, null, 2));

  await browser.close();
})().catch((e) => {
  console.error(e);
  process.exit(1);
});
