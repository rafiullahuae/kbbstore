/**
 * Drive Store → Payments in real Chromium and report what the tabs actually do.
 *
 * WHY A BROWSER AT ALL. The thing this lane had to get right — an unsaved edit
 * surviving a tab switch — has no server side. It is entirely a question of
 * whether the DOM node the operator typed into still exists after the switch,
 * and Pest has no DOM. A PHP test can assert that the markup contains a tab
 * bar; only a browser can assert that typing into one tab, leaving it and
 * coming back finds the typing still there.
 *
 * WHY #content AND NOT THE DOCUMENT, for the overflow half: the same reason
 * tests/browser/admin-overflow.mjs gives. The admin is a two-pane layout whose
 * `#content` column is itself `overflow-x:auto`, so the document metric reads
 * exactly the viewport width however wide the content is.
 *
 * Prints one JSON object on stdout and nothing else.
 *
 * Config, all via environment:
 *   KBB_PT_BASE       base URL of a running preview   (required)
 *   KBB_PT_EMAIL      admin login                     (required)
 *   KBB_PT_PASSWORD   admin password                  (required)
 *   KBB_PT_CHROME     chromium executable             (required)
 *   KBB_PT_GATEWAYS   comma-separated ids the server ships, for the
 *                     "every gateway got a tab" check
 *   KBB_PT_WIDTHS     comma-separated viewport widths, default 1920,1280,390
 *   KBB_PT_PROBE      '1' to inject an oversized block into each tab, which
 *                     must make every measurement fail — the harness proving
 *                     it can still see an overflow at all
 *   KBB_PT_PROBE_W    width of that block, default 1672
 *   KBB_PT_SHOTS      directory for screenshots; omitted means none
 *   KBB_PT_SHOT_TAG   'before' or 'after', used in the filenames
 *   KBB_PT_SHOTS_ONLY '1' to skip the functional pass and only measure and
 *                     photograph. This is what makes a BEFORE run possible at
 *                     all: the pre-change screen has no tab bar, so the
 *                     functional pass has nothing to click and would abort
 *                     before taking a single picture.
 */
import { chromium } from 'playwright';
import { mkdirSync } from 'node:fs';

const BASE = process.env.KBB_PT_BASE;
const EMAIL = process.env.KBB_PT_EMAIL;
const PASSWORD = process.env.KBB_PT_PASSWORD;
const EXE = process.env.KBB_PT_CHROME;
const GATEWAYS = (process.env.KBB_PT_GATEWAYS || '').split(',').map((s) => s.trim()).filter(Boolean);
const WIDTHS = (process.env.KBB_PT_WIDTHS || '1920,1280,390').split(',').map(Number).filter(Boolean);
const PROBE = process.env.KBB_PT_PROBE === '1';
const PROBE_W = Number(process.env.KBB_PT_PROBE_W || 1672);
const SHOTS = process.env.KBB_PT_SHOTS || '';
const SHOT_TAG = process.env.KBB_PT_SHOT_TAG || 'after';
const SHOTS_ONLY = process.env.KBB_PT_SHOTS_ONLY === '1';

const out = {
  ok: false,
  probe: PROBE,
  gateways: [],
  tabs: [],
  overflow: [],
  shots: [],
  checks: {},
  pageErrors: [],
  ignored: [],
};

const fail = (msg) => {
  out.error = msg;
  process.stdout.write(JSON.stringify(out));
  process.exit(0);
};

for (const [k, v] of Object.entries({ KBB_PT_BASE: BASE, KBB_PT_EMAIL: EMAIL, KBB_PT_PASSWORD: PASSWORD, KBB_PT_CHROME: EXE })) {
  if (!v) fail(`${k} is not set`);
}

if (SHOTS) mkdirSync(SHOTS, { recursive: true });

let browser;

/**
 * The console ships no favicon, so Chromium's automatic /favicon.ico request
 * 404s on every page of it. That request is made by the browser process rather
 * than the renderer, so Playwright surfaces it ONLY as a console error with no
 * URL in it and no matching `response` event — which is why it cannot simply be
 * filtered by URL further down. It is pre-existing, is nothing to do with this
 * screen, and the preview server logs confirm /favicon.ico is the only 404 in
 * the run. Everything else still counts.
 */
const FAVICON_NOISE = /Failed to load resource: the server responded with a status of 404/;

/** Attach the recorders to a page. Synchronous pushes — see admin-overflow.mjs. */
const watch = (page) => {
  page.on('pageerror', (e) => out.pageErrors.push(`[pageerror] ${e.message.split('\n')[0]}`));
  page.on('console', (m) => {
    if (m.type() !== 'error') return;
    const t = m.text();
    if (FAVICON_NOISE.test(t)) {
      out.ignored.push(`[console.error] ${t}`);
      return;
    }
    out.pageErrors.push(`[console.error] ${t}`);
  });
  page.on('response', (r) => {
    // A real 404 with a URL still fails the run — only the URL-less favicon
    // console line above is forgiven.
    if (r.status() >= 400 && !r.url().endsWith('/favicon.ico')) {
      out.pageErrors.push(`[http ${r.status()}] ${r.url()}`);
    }
  });
};

const login = async (context) => {
  const page = await context.newPage();
  watch(page);
  await page.goto(`${BASE}/admin/login`, { waitUntil: 'domcontentloaded' });
  await page.fill('input[name="email"]', EMAIL);
  await page.fill('input[name="password"]', PASSWORD);
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'domcontentloaded' }),
    page.click('button[type="submit"], input[type="submit"]'),
  ]);
  if (!page.url().includes('/admin')) fail(`login did not reach the admin: ${page.url()}`);
  return page;
};

/** Open Payments through the router and wait for the gateways to land. */
const openPayments = async (page) => {
  await page.goto(`${BASE}/admin`, { waitUntil: 'domcontentloaded' });
  await page.waitForFunction(() => typeof window.go === 'function');
  await page.evaluate(() => window.go('payments'));
  await page
    .waitForFunction(() => !!document.querySelector('#content [data-paysave]'), { timeout: 20000 })
    .catch(() => {});
  await page.waitForTimeout(400);
};

/** The content column's own overflow, plus the widest thing in it. */
const measure = (page) =>
  page.evaluate(() => {
    const c = document.querySelector('#content');
    if (!c) return null;
    let worst = null;
    let right = -Infinity;
    for (const el of c.querySelectorAll('*')) {
      const r = el.getBoundingClientRect();
      if (!r.width && !r.height) continue;
      if (r.right > right) {
        right = r.right;
        worst = el;
      }
    }
    const name = (el) => {
      if (!el) return '(none)';
      const cls = (el.getAttribute('class') || '').trim().split(/\s+/).filter(Boolean).join('.');
      return el.tagName.toLowerCase() + (cls ? '.' + cls : '');
    };
    return {
      documentScrollWidth: document.documentElement.scrollWidth,
      scrollWidth: c.scrollWidth,
      clientWidth: c.clientWidth,
      worst: name(worst),
    };
  });

try {
  browser = await chromium.launch({ executablePath: EXE });

  /* ------------------------------------------------- the functional pass */
  if (!SHOTS_ONLY) {
    const context = await browser.newContext({ viewport: { width: 1920, height: 1280 } });
    const page = await login(context);
    await openPayments(page);

    // What the screen thinks it has. Read from the DOM, so a tab bar built
    // from a hardcoded list would disagree with KBB_PT_GATEWAYS and be caught.
    out.tabs = await page.evaluate(() =>
      Array.from(document.querySelectorAll('#content [data-paytab]')).map((b) => ({
        id: b.dataset.paytab,
        selected: b.getAttribute('aria-selected'),
        dot: (b.querySelector('.paydot')?.getAttribute('class') || '').replace('paydot', '').trim(),
        live: !!b.querySelector('.paylive'),
        dirty: !b.querySelector('.paydirty')?.hasAttribute('hidden'),
        label: b.getAttribute('aria-label') || '',
      })),
    );

    out.gateways = await page.evaluate(() =>
      Array.from(document.querySelectorAll('#content [data-paycard]')).map((c) => c.dataset.paycard),
    );

    // Exactly one pane visible at a time is the whole point of the feature.
    out.checks.visiblePanes = await page.evaluate(
      () => Array.from(document.querySelectorAll('#content .paypane')).filter((p) => !p.hasAttribute('hidden')).length,
    );

    // No Payments control may carry the attribute Ecommerce listens for on
    // document — that listener repaints #content with the Ecommerce screen.
    out.checks.ectabInPayments = await page.evaluate(
      () => document.querySelectorAll('#content [data-ectab]').length,
    );

    /* ---- THE ASSERTION THAT MATTERS ----
       Type into one gateway, leave the tab, come back, and see whether the
       typing is still there. Then save and reload and see whether it stuck. */
    const first = out.tabs[0]?.id;
    const other = out.tabs.find((t) => t.id !== first)?.id;

    if (!first || !other) fail(`need two tabs to test a switch, saw ${out.tabs.length}`);

    // A visible, non-secret field on the first tab: its shopper-facing label,
    // which every gateway has.
    const TYPED = 'Tab-switch canary ' + Date.now();

    await page.fill(`#pay_title_${first}`, TYPED);
    await page.waitForTimeout(120);

    out.checks.dirtyMarkWhileOpen = await page.evaluate(
      (id) => !document.getElementById('pay_tabdirty_' + id).hasAttribute('hidden'),
      first,
    );

    await page.click(`[data-paytab="${other}"]`);
    await page.waitForTimeout(200);

    // Hidden, and still flagged — an edit the operator cannot see must still
    // announce itself on the tab.
    out.checks.hiddenAfterSwitch = await page.evaluate(
      (id) => document.getElementById('pay_pane_' + id).hasAttribute('hidden'),
      first,
    );
    out.checks.dirtyMarkWhileHidden = await page.evaluate(
      (id) => !document.getElementById('pay_tabdirty_' + id).hasAttribute('hidden'),
      first,
    );
    out.checks.hashAfterSwitch = await page.evaluate(() => location.hash);

    await page.click(`[data-paytab="${first}"]`);
    await page.waitForTimeout(200);

    out.checks.valueAfterRoundTrip = await page.inputValue(`#pay_title_${first}`);
    out.checks.survivedRoundTrip = out.checks.valueAfterRoundTrip === TYPED;

    // And it really saves from there.
    await page.click(`[data-paysave="${first}"]`);
    await page.waitForFunction(
      (id) => !document.querySelector(`[data-paysave="${id}"]`)?.disabled,
      first,
      { timeout: 20000 },
    ).catch(() => {});
    await page.waitForTimeout(900);

    out.checks.tabAfterSave = await page.evaluate(
      () => document.querySelector('#content [data-paytab].on')?.dataset.paytab || '',
    );

    // Straight from the endpoint, so this is the stored row and not the DOM
    // the browser has been holding all along.
    out.checks.persisted = await page.evaluate(async (id) => {
      const base = location.pathname.replace(/\/+$/, '').replace(/\/[^/]*$/, '');
      const r = await fetch(base + '/admin-api/payments', {
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
      });
      const j = await r.json();
      return (j.gateways || []).find((g) => g.id === id)?.title || '';
    }, first);

    out.checks.persistedMatches = out.checks.persisted === TYPED;

    /* ---- a save must not eat a pending edit in ANOTHER tab ---- */
    const OTHER_TYPED = 'Other-tab canary ' + Date.now();
    await page.click(`[data-paytab="${other}"]`);
    await page.waitForTimeout(150);
    await page.fill(`#pay_title_${other}`, OTHER_TYPED);
    await page.click(`[data-paytab="${first}"]`);
    await page.waitForTimeout(150);
    await page.click(`[data-paysave="${first}"]`);
    await page.waitForTimeout(1400);

    out.checks.otherTabAfterForeignSave = await page.inputValue(`#pay_title_${other}`);
    out.checks.otherTabSurvivedForeignSave = out.checks.otherTabAfterForeignSave === OTHER_TYPED;

    /* ---- deep link, as a genuine document load ----
       about:blank first, deliberately. Going straight from /admin to
       /admin#payments/x is a SAME-DOCUMENT fragment navigation: Chromium does
       not reload, no script re-runs, and a broken deep link would still show
       whatever tab happened to be open. The first version of this check passed
       for exactly that reason. */
    await page.goto('about:blank');
    await page.goto(`${BASE}/admin#payments/${other}`, { waitUntil: 'domcontentloaded' });
    await page
      .waitForFunction(() => !!document.querySelector('#content [data-paytab]'), { timeout: 20000 })
      .catch(() => {});
    await page.waitForTimeout(600);
    out.checks.deepLinkTab = await page.evaluate(
      () => document.querySelector('#content [data-paytab].on')?.dataset.paytab || '',
    );
    out.checks.deepLinkWasFreshLoad = await page.evaluate(
      () => performance.getEntriesByType('navigation')[0]?.type || '?',
    );

    /* ---- the same address pasted into an ALREADY-OPEN console ----
       A fragment-only change, which is what the address bar does. */
    await page.evaluate((id) => { location.hash = '#payments/' + id; }, first);
    await page.waitForTimeout(500);
    out.checks.hashChangeTab = await page.evaluate(
      () => document.querySelector('#content [data-paytab].on')?.dataset.paytab || '',
    );

    /* ---- query form ---- */
    await page.goto('about:blank');
    await page.goto(`${BASE}/admin?go=payments&tab=${first}`, { waitUntil: 'domcontentloaded' });
    await page
      .waitForFunction(() => !!document.querySelector('#content [data-paytab]'), { timeout: 20000 })
      .catch(() => {});
    await page.waitForTimeout(600);
    out.checks.queryLinkTab = await page.evaluate(
      () => document.querySelector('#content [data-paytab].on')?.dataset.paytab || '',
    );

    /* ---- remembered across a visit with no link at all ---- */
    await page.evaluate(() => window.go('payments'));
    await page.waitForTimeout(400);
    await page.click(`[data-paytab="${other}"]`);
    await page.waitForTimeout(200);
    // Leave the screen, come back: no hash of our own making is involved in
    // the sidebar route, so this is the localStorage half.
    await page.evaluate(() => { history.replaceState(null, '', location.pathname); });
    await page.goto('about:blank');
    await page.goto(`${BASE}/admin`, { waitUntil: 'domcontentloaded' });
    await page.waitForFunction(() => typeof window.go === 'function');
    await page.evaluate(() => window.go('payments'));
    await page
      .waitForFunction(() => !!document.querySelector('#content [data-paytab]'), { timeout: 20000 })
      .catch(() => {});
    await page.waitForTimeout(500);
    out.checks.rememberedTab = await page.evaluate(
      () => document.querySelector('#content [data-paytab].on')?.dataset.paytab || '',
    );
    out.checks.rememberedExpected = other;

    await context.close();
  }

  /* ------------------------------------- overflow, every tab, every width */
  for (const width of WIDTHS) {
    const context = await browser.newContext({ viewport: { width, height: 900 } });
    const page = await login(context);
    await openPayments(page);

    const ids = await page.evaluate(() =>
      Array.from(document.querySelectorAll('#content [data-paytab]')).map((b) => b.dataset.paytab),
    );

    // The long page before tabs existed has no tab bar; measure it once so the
    // "before" run produces a comparable row instead of nothing.
    const targets = ids.length ? ids : ['(no tabs)'];

    for (const id of targets) {
      if (ids.length) {
        await page.click(`[data-paytab="${id}"]`);
        await page.waitForTimeout(220);
      }

      if (PROBE) {
        await page.evaluate((w) => {
          const d = document.createElement('div');
          d.id = 'kbb-paytab-probe';
          d.style.cssText = `width:${w}px;height:8px`;
          document.querySelector('#content').appendChild(d);
        }, PROBE_W);
        await page.waitForTimeout(150);
      }

      const m = await measure(page);

      if (PROBE) await page.evaluate(() => document.getElementById('kbb-paytab-probe')?.remove());

      out.overflow.push({ width, tab: id, ...m });

      if (SHOTS && !PROBE && (width === 1920 || width === 390)) {
        const file = `${SHOTS}/${SHOT_TAG}-${width}-${String(id).replace(/[^A-Za-z0-9_-]/g, '')}.png`;

        /*
         * `fullPage` alone is useless on this console, for the same structural
         * reason the width has to be measured on #content: the DOCUMENT does
         * not scroll, the column inside it does. A fullPage shot therefore
         * comes back exactly one viewport tall and shows none of the length —
         * which on a BEFORE run hides the very thing the owner is being shown,
         * that Payments was one long stack of four gateway cards.
         *
         * Growing the viewport to the column's own scrollHeight makes the pane
         * grow with it, so the picture contains the whole screen. Capped, so a
         * runaway page cannot ask for a 100k-pixel image.
         */
        const tall = await page.evaluate(
          () => document.querySelector('#content')?.scrollHeight || 900,
        );
        const shotHeight = Math.min(Math.max(tall + 40, 900), 12000);

        await page.setViewportSize({ width, height: shotHeight });
        await page.waitForTimeout(250);
        await page.screenshot({ path: file });
        await page.setViewportSize({ width, height: 900 });
        await page.waitForTimeout(150);

        out.shots.push(file);
      }
    }

    await context.close();
  }

  out.ok = true;
} catch (e) {
  out.error = String(e && e.message ? e.message : e).split('\n')[0];
} finally {
  if (browser) await browser.close().catch(() => {});
}

process.stdout.write(JSON.stringify(out));
